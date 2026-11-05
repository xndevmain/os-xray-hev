#!/usr/local/bin/python3
"""Root broker for Unbound -> external OPNsense alias updates."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import signal
import socket
import sqlite3
import subprocess
import time

from geodata import DomainMatcher, categories, networks, validate_database, selectors

SOCKET = '/var/unbound/run/xray-routing.sock'
DATABASE = '/var/db/xray-routing.sqlite'


def load_routes(config):
    site_names = {name.split('@', 1)[0] for route in config['routes'] for name in selectors(route['geosite'], 'geosite')}
    ip_names = {name for route in config['routes'] for name in selectors(route['geoip'], 'geoip')}
    geosite = categories(Path(config['asset_dir']) / 'geosite.dat', site_names) if site_names else {}
    geoip = categories(Path(config['asset_dir']) / 'geoip.dat', ip_names) if ip_names else {}
    validate_database(geosite, 'geosite')
    validate_database(geoip, 'geoip')
    routes = {}
    for route in config['routes']:
        table = route['table']
        if table != 'XRAY_HOSTS' and not re.fullmatch(r'XRAY_[0-9a-f]{12}', table):
            raise ValueError('Invalid managed alias name')
        if table in routes:
            raise ValueError('Managed alias name collision')
        routes[table] = (DomainMatcher(geosite, route['geosite'], route.get('custom_domains', ''), route.get('custom_hosts', '')), networks(geoip, route['geoip']))
    return routes


class Broker:
    def __init__(self, routes, database=DATABASE, runner=subprocess.run):
        self.routes, self.runner = routes, runner
        self.db = sqlite3.connect(database)
        self.db.execute('CREATE TABLE IF NOT EXISTS learned (alias TEXT, ip TEXT, expires REAL, PRIMARY KEY(alias,ip))')
        self.db.execute('CREATE TABLE IF NOT EXISTS revisions (alias TEXT PRIMARY KEY, signature TEXT)')
        for table, (matcher, _) in routes.items():
            previous = self.db.execute('SELECT signature FROM revisions WHERE alias=?', (table,)).fetchone()
            if previous != (matcher.signature,):
                self.db.execute('DELETE FROM learned WHERE alias=?', (table,))
                self.db.execute('INSERT OR REPLACE INTO revisions VALUES(?,?)', (table, matcher.signature))
        self.db.commit()

    def pf(self, table, action, addresses):
        self.runner(['/sbin/pfctl', '-t', table, '-T', action, '-f', '-'],
                    input='\n'.join(addresses) + '\n', text=True, check=True,
                    stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=1.5)

    def restore(self):
        for table, (_, static) in self.routes.items():
            learned = [row[0] for row in self.db.execute('SELECT ip FROM learned WHERE alias=? AND expires>?', (table, time.time()))]
            self.pf(table, 'replace', sorted(set(static) | set(learned)))

    def expire(self):
        # Replace the union, so an expiring DNS IP cannot remove a static GeoIP network.
        if self.db.execute('SELECT 1 FROM learned WHERE expires<=? LIMIT 1', (time.time(),)).fetchone():
            self.db.execute('DELETE FROM learned WHERE expires<=?', (time.time(),))
            self.db.commit()
            self.restore()

    def publish(self, message):
        name = message['name'].rstrip('.').lower()
        if not re.fullmatch(r'[a-z0-9_.-]{1,253}', name):
            raise ValueError('Invalid DNS name')
        records = message['records']
        if not isinstance(records, list) or len(records) > 512:
            raise ValueError('Invalid DNS answer')
        addresses = {}
        for address, ttl in records:
            address = str(ipaddress.ip_address(address))
            if not isinstance(ttl, int) or not 0 <= ttl <= 2147483647:
                raise ValueError('Invalid DNS TTL')
            addresses[address] = max(addresses.get(address, 0), time.time() + max(1, ttl) + 60)
        for table, (matcher, _) in self.routes.items():
            if matcher.matches(name) and addresses:
                # Always add before acknowledgement, even for already learned entries.
                self.pf(table, 'add', sorted(addresses))
                for address, expires in addresses.items():
                    self.db.execute('INSERT INTO learned VALUES(?,?,?) ON CONFLICT(alias,ip) DO UPDATE SET expires=MAX(expires,excluded.expires)', (table, address, expires))
        self.db.commit()


def serve(routes):
    import grp  # FreeBSD-only account lookup; config validation is portable.
    broker = Broker(routes)
    os.chmod(DATABASE, 0o600)
    broker.restore()
    parent = Path(SOCKET).parent
    parent.mkdir(parents=True, exist_ok=True)
    if os.path.lexists(SOCKET):
        os.unlink(SOCKET)
    running = True

    def stop(*_):
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    try:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as server:
            server.bind(SOCKET)
            os.chown(SOCKET, 0, grp.getgrnam('unbound').gr_gid)
            os.chmod(SOCKET, 0o660)
            server.listen(32)
            server.settimeout(1)
            last_expiry = time.monotonic()
            last_restore = last_expiry
            while running:
                try:
                    client, _ = server.accept()
                except socket.timeout:
                    client = None
                if client:
                    with client:
                        client.settimeout(2)
                        try:
                            raw = bytearray()
                            while not raw.endswith(b'\n'):
                                part = client.recv(4096)
                                if not part or len(raw) + len(part) > 65536:
                                    raise ValueError('Invalid broker request')
                                raw.extend(part)
                            broker.publish(json.loads(raw))
                            client.sendall(b'OK\n')
                        except Exception as error:
                            print('xray-routing: ' + str(error), flush=True)
                            try:
                                client.sendall(b'ERROR\n')
                            except OSError:
                                pass
                if time.monotonic() - last_expiry >= 5:
                    try:
                        broker.expire()
                        if time.monotonic() - last_restore >= 60:
                            broker.restore()
                            last_restore = time.monotonic()
                    except (subprocess.SubprocessError, sqlite3.Error) as error:
                        print('xray-routing maintenance failed: ' + type(error).__name__, flush=True)
                    last_expiry = time.monotonic()
    finally:
        broker.db.close()
        if os.path.exists(SOCKET):
            os.unlink(SOCKET)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', default='/usr/local/etc/xray-routing.json')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    with open(args.config) as source:
        routes = load_routes(json.load(source))
    if args.check:
        print('Routing categories validated: ' + str(len(routes)))
    else:
        serve(routes)
