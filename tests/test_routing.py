import importlib.util
import ipaddress
import json
from pathlib import Path
import socket
import struct
import sys
import tempfile
import time
from types import SimpleNamespace as NS
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'plugin/scripts/Xray'))
from geodata import DomainMatcher, categories, fields, networks, validate_database
import unbound_xray as hook

spec = importlib.util.spec_from_file_location('routing', ROOT / 'plugin/scripts/Xray/xray-routing.py')
routing = importlib.util.module_from_spec(spec)
spec.loader.exec_module(routing)


def vint(number):
    result = bytearray()
    while number > 127:
        result.append((number & 127) | 128)
        number >>= 7
    result.append(number)
    return bytes(result)


def scalar(field, number):
    return vint(field << 3) + vint(number)


def blob(field, data):
    if isinstance(data, str):
        data = data.encode()
    return vint((field << 3) | 2) + vint(len(data)) + data


def domain(kind, value, attribute=None):
    result = scalar(1, kind) + blob(2, value)
    if attribute:
        result += blob(3, blob(1, attribute) + scalar(2, 1))
    return 2, result


class RoutingTests(unittest.TestCase):
    def setUp(self):
        self.domains = {'test': [(1, b'TEST'), domain(3, 'exact.example'), domain(2, 'suffix.example', 'ads'), domain(0, 'keyword'), domain(1, r'^video[0-9]+\.example$')]}
        self.matcher = DomainMatcher(self.domains, 'geosite:test')

    def test_all_domain_types_and_boundaries(self):
        for name in ['exact.example.', 'child.suffix.example', 'suffix.example', 'keyword.example', 'video123.example']:
            self.assertTrue(self.matcher.matches(name), name)
        for name in ['child.exact.example', 'notsuffix.example', 'videoabc.example']:
            self.assertFalse(self.matcher.matches(name), name)
        ads = DomainMatcher(self.domains, 'test@ads')
        self.assertTrue(ads.matches('www.suffix.example'))
        self.assertFalse(ads.matches('exact.example'))
        self.assertFalse(DomainMatcher(self.domains, 'test@!ads').matches('www.suffix.example'))
        with self.assertRaises(ValueError):
            DomainMatcher(self.domains, 'missing')

    def test_cidr_and_truncation(self):
        records = []
        for address, prefix in [('192.0.2.123', 24), ('2001:db8::5', 32)]:
            records.append((2, blob(1, ipaddress.ip_address(address).packed) + scalar(2, prefix)))
        self.assertEqual(networks({'test': records}, 'geoip:test'), ['192.0.2.0/24', '2001:db8::/32'])
        with self.assertRaises(ValueError):
            list(fields(b'\x0a\x7fshort'))
        with self.assertRaises(ValueError):
            validate_database({'test': records}, 'geosite')
        with self.assertRaises(ValueError):
            networks({'test': [(3, 1)]}, 'test')

    def test_static_ip_lists_are_normalized_and_validated(self):
        with tempfile.TemporaryDirectory() as directory:
            routes = routing.load_routes({'asset_dir': directory, 'routes': [{
                'table': 'XRAY_0123456789ab', 'geosite': '', 'geoip': '',
                'custom_domains': '', 'custom_hosts': '',
                'static_ips': ['192.0.2.7', '198.51.100.42/24', '2001:db8::5/32'],
            }]})
            self.assertEqual(routes['XRAY_0123456789ab'][1], [
                '192.0.2.7/32', '198.51.100.0/24', '2001:db8::/32'])
            with self.assertRaises(ValueError):
                routing.load_routes({'asset_dir': directory, 'routes': [{
                    'table': 'XRAY_0123456789ab', 'geosite': '', 'geoip': '',
                    'custom_domains': '', 'custom_hosts': '', 'static_ips': ['not-an-ip'],
                }]})

    def test_static_content_change_invalidates_learned_addresses(self):
        table = 'XRAY_0123456789ab'
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'state.sqlite')
            runner = lambda *args, **kwargs: None
            broker = routing.Broker({table: (self.matcher, ['198.51.100.0/24'])}, path, runner)
            broker.publish({'name': 'exact.example', 'records': [['192.0.2.4', 300]]})
            self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM learned').fetchone()[0], 1)
            broker.db.close()
            broker = routing.Broker({table: (self.matcher, ['203.0.113.0/24'])}, path, runner)
            try:
                self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM learned').fetchone()[0], 0)
            finally:
                broker.db.close()

    def test_pf_before_persist_and_restore(self):
        calls = []
        def runner(args, **kwargs):
            calls.append((args, kwargs['input']))
        table = 'XRAY_0123456789ab'
        with tempfile.TemporaryDirectory() as temp:
            path = str(Path(temp) / 'state.sqlite')
            broker = routing.Broker({table: (self.matcher, ['198.51.100.0/24'])}, path, runner)
            broker.publish({'name': 'www.suffix.example.', 'records': [['192.0.2.1', 120], ['2001:db8::1', 120]]})
            self.assertEqual(calls[0][0], ['/sbin/pfctl', '-t', table, '-T', 'add', '-f', '-'])
            self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM learned').fetchone()[0], 2)
            broker.restore()
            self.assertIn('198.51.100.0/24', calls[-1][1])
            self.assertIn('2001:db8::1', calls[-1][1])
            before = len(calls)
            broker.publish({'name': 'unrelated.example', 'records': [['203.0.113.1', 120]]})
            self.assertEqual(len(calls), before)
            broker.db.execute('UPDATE learned SET expires=0')
            broker.db.commit()
            broker.expire()
            self.assertEqual(calls[-1][1], '198.51.100.0/24\n')
            broker.publish({'name': 'exact.example', 'records': [['192.0.2.2', 120]]})
            broker.db.close()
            # Category changes invalidate previously learned addresses.
            broker = routing.Broker({table: (DomainMatcher(self.domains, 'test@ads'), [])}, path, runner)
            self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM learned').fetchone()[0], 0)
            broker.db.close()

    def test_unbound_answer_only_and_cached_ttl(self):
        sent = []
        class Socket:
            def __enter__(self): return self
            def __exit__(self, *_): pass
            def settimeout(self, _): pass
            def connect(self, _): pass
            def sendall(self, message): sent.append(json.loads(message))
            def recv(self, _): return b'OK\n'
        def rr(address, ttl):
            addr = ipaddress.ip_address(address)
            return NS(rk=NS(type=socket.htons(1 if addr.version == 4 else 28), rrset_class=socket.htons(1)),
                      entry=NS(data=NS(count=1, rr_data=[struct.pack('!H', len(addr.packed)) + addr.packed], rr_ttl=[ttl])))
        rep = NS(an_numrrsets=2, rrsets=[rr('192.0.2.1', int(time.time()) + 120), rr('2001:db8::1', int(time.time()) + 120), rr('203.0.113.1', 300)])
        with patch.object(hook.socket, 'socket', return_value=Socket()), patch.object(hook.socket, 'AF_UNIX', 1, create=True):
            self.assertTrue(hook.publish_cached(NS(qname_str='www.suffix.example.'), None, rep, 0, None, None, None))
        self.assertEqual([item[0] for item in sent[0]['records']], ['192.0.2.1', '2001:db8::1'])
        self.assertTrue(118 <= sent[0]['records'][0][1] <= 120)

    def test_https_address_hints(self):
        ipv4 = ipaddress.ip_address('192.0.2.1').packed
        ipv6 = ipaddress.ip_address('2001:db8::1').packed
        payload = b'\x00\x01\x00' + struct.pack('!HH', 4, 4) + ipv4 + struct.pack('!HH', 6, 16) + ipv6
        record = struct.pack('!H', len(payload)) + payload
        self.assertEqual(hook.answer_addresses(65, record), ['192.0.2.1', '2001:db8::1'])
        self.assertEqual(hook.answer_addresses(65, record[:-1]), [])

    def test_empty_selection_clears_persisted_addresses(self):
        calls = []
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'learned.sqlite')
            runner = lambda args, **kwargs: calls.append((args, kwargs))
            broker = routing.Broker({'XRAY_HOSTS': (self.matcher, [])}, path, runner)
            broker.publish({'name': 'exact.example', 'records': [['192.0.2.4', 300]]})
            broker.db.close()
            routes = routing.load_routes({'asset_dir': directory, 'routes': [
                {'table':'XRAY_HOSTS', 'geosite':'', 'geoip':'', 'custom_domains':'', 'custom_hosts':''}]})
            broker = routing.Broker(routes, path, runner)
            broker.restore()
            self.assertEqual(calls[-1][1]['input'], '\n')
            self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM learned').fetchone()[0], 0)
            broker.db.close()

    def test_database_snapshot_restores_learned_addresses_after_failed_apply(self):
        import shutil
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'learned.sqlite'
            snapshot = Path(directory) / 'before.sqlite'
            calls = []
            runner = lambda args, **kwargs: calls.append((args, kwargs))
            broker = routing.Broker({'XRAY_HOSTS': (self.matcher, [])}, str(path), runner)
            broker.publish({'name': 'exact.example', 'records': [['192.0.2.4', 300]]})
            broker.db.close()
            shutil.copyfile(path, snapshot)
            replacement = routing.Broker({'XRAY_HOSTS': (DomainMatcher({}, ''), [])}, str(path), runner)
            self.assertEqual(replacement.db.execute('SELECT COUNT(*) FROM learned').fetchone()[0], 0)
            replacement.db.close()
            shutil.copyfile(snapshot, path)
            restored = routing.Broker({'XRAY_HOSTS': (self.matcher, [])}, str(path), runner)
            restored.restore()
            self.assertIn('192.0.2.4', calls[-1][1]['input'])
            restored.db.close()

    def test_broker_purges_orphaned_learned_and_revision_rows(self):
        table = 'XRAY_0123456789ab'
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'state.sqlite')
            runner = lambda *args, **kwargs: None
            broker = routing.Broker({table: (self.matcher, ['198.51.100.0/24'])}, path, runner)
            broker.publish({'name': 'exact.example', 'records': [['192.0.2.4', 300]]})
            self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM learned').fetchone()[0], 1)
            broker.db.close()
            # A restart without that alias in the active routes (e.g. its list
            # was deleted) must purge orphaned learned/revision rows.
            broker = routing.Broker({'XRAY_HOSTS': (self.matcher, [])}, path, runner)
            try:
                self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM learned').fetchone()[0], 0)
                self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM revisions WHERE alias=?', (table,)).fetchone()[0], 0)
                self.assertEqual(broker.db.execute('SELECT COUNT(*) FROM revisions').fetchone()[0], 1)
            finally:
                broker.db.close()

    def test_real_community_files_when_available(self):
        for source, category in [(ROOT / '.tools/xray/geosite.dat', 'youtube'), (ROOT / '.tools/itdog-geosite.dat', 'russia-inside@geoblock')]:
            if source.exists():
                data = categories(source)
                validate_database(data, 'geosite')
                self.assertTrue(DomainMatcher(data, category).signature)
        path = ROOT / '.tools/xray/geoip.dat'
        if path.exists():
            data = categories(path)
            validate_database(data, 'geoip')
            self.assertTrue(networks(data, 'ru'))


if __name__ == '__main__':
    unittest.main()
