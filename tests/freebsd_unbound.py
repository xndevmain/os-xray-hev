"""Root-only isolated Unbound/PF integration test; no production DNS/rules changes."""
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time

import dns.message
import dns.query
import dns.rrset

SCRIPTS = Path('/usr/local/opnsense/scripts/Xray')
sys.path.insert(0, str(SCRIPTS))
from geodata import DomainMatcher
spec = importlib.util.spec_from_file_location('routing_tested', SCRIPTS / 'xray-routing.py')
routing = importlib.util.module_from_spec(spec)
spec.loader.exec_module(routing)
TABLE = 'XRAY_a9a900000001'


def run(*args):
    result = subprocess.run(args, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr + result.stdout)
    return result.stdout


class DNSHandler(socketserver.BaseRequestHandler):
    def handle(self):
        data, udp = self.request
        query = dns.message.from_wire(data)
        self.server.queries += 1
        response = dns.message.make_response(query)
        kind = query.question[0].rdtype
        value = {1: '192.0.2.123', 28: '2001:db8::123', 65: '1 . ipv4hint=192.0.2.124 ipv6hint=2001:db8::124'}.get(kind)
        if value:
            response.answer.append(dns.rrset.from_text(query.question[0].name, 120, 'IN', kind, value))
        udp.sendto(response.to_wire(), self.client_address)


def main():
    if os.geteuid() != 0:
        raise RuntimeError('This test requires root on FreeBSD')
    tables = run('/sbin/pfctl', '-s', 'Tables').split()
    if TABLE in tables:
        raise RuntimeError('Test PF table already exists; refusing to reuse it')
    temporary = Path(tempfile.mkdtemp(prefix='xray-unbound-test-'))
    temporary.chmod(0o755)
    account = pwd.getpwnam('unbound')
    unix = str(temporary / 'broker.sock')
    upstream = socketserver.UDPServer(('127.0.0.1', 0), DNSHandler)
    upstream.queries = 0
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    listener = socket.socket()
    listener.bind(('127.0.0.1', 0))
    port = listener.getsockname()[1]
    listener.close()
    callback = (SCRIPTS / 'unbound_xray.py').read_text().replace("'/run/xray-routing.sock'", repr(unix)).replace("'unbound-dnsbl/dnsbl_module.py'", repr(str(temporary / 'noop.py')))
    (temporary / 'callback.py').write_text(callback)
    (temporary / 'callback.py').chmod(0o644)
    noop = '''def init(id,cfg): return True
def deinit(id): return True
def inform_super(id,qstate,superqstate,qdata): return True
def operate(id,event,qstate,qdata):
    qstate.ext_state[id] = MODULE_FINISHED if event == MODULE_EVENT_MODDONE else MODULE_WAIT_MODULE
    return True
'''
    (temporary / 'noop.py').write_text(noop)
    (temporary / 'noop.py').chmod(0o644)
    config = f'''server:
    interface: 127.0.0.1
    port: {port}
    chroot: ""
    username: unbound
    directory: "{temporary}"
    do-daemonize: no
    use-syslog: no
    do-not-query-localhost: no
    module-config: "python iterator"
    num-threads: 1
    local-data: "local.xray-agent.example.com. 120 IN A 192.0.2.125"
python:
    python-script: "{temporary}/callback.py"
    python-script: "{temporary}/noop.py"
forward-zone:
    name: "xray-agent.example.com."
    forward-addr: 127.0.0.1@{upstream.server_address[1]}
'''
    (temporary / 'unbound.conf').write_text(config)
    (temporary / 'unbound.conf').chmod(0o644)
    stop = threading.Event()
    ready = threading.Event()
    failures = []
    # Fixture with one RootDomain protobuf entry.
    value = b'xray-agent.example.com'
    encoded = b'\x08\x02\x12' + bytes([len(value)]) + value
    matcher = DomainMatcher({'test': [(2, encoded)]}, 'test')

    def broker_loop():
        broker = None
        try:
            broker = routing.Broker({TABLE: (matcher, [])}, str(temporary / 'state.sqlite'))
            broker.restore()
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as server:
                server.bind(unix)
                os.chown(unix, 0, account.pw_gid)
                os.chmod(unix, 0o660)
                server.listen(16)
                server.settimeout(.2)
                ready.set()
                while not stop.is_set():
                    try:
                        client, _ = server.accept()
                    except socket.timeout:
                        continue
                    with client:
                        client.settimeout(2)
                        raw = bytearray()
                        while not raw.endswith(b'\n'):
                            raw.extend(client.recv(4096))
                        broker.publish(json.loads(raw))
                        client.sendall(b'OK\n')
        except Exception as error:
            failures.append(str(error))
            ready.set()
        finally:
            if broker:
                broker.db.close()

    worker = threading.Thread(target=broker_loop, daemon=True)
    worker.start()
    process = None
    try:
        ready.wait(5)
        if failures: raise RuntimeError(failures[0])
        run('/usr/local/sbin/unbound-checkconf', str(temporary / 'unbound.conf'))
        with (temporary / 'unbound.log').open('w') as log:
            process = subprocess.Popen(['/usr/local/sbin/unbound', '-d', '-p', '-c', str(temporary / 'unbound.conf')], stdout=log, stderr=log)
            time.sleep(1)
            if process.poll() is not None:
                raise RuntimeError((temporary / 'unbound.log').read_text())
            def query(name, kind):
                return dns.query.udp(dns.message.make_query(name, kind), '127.0.0.1', port=port, timeout=4)
            name = 'www.xray-agent.example.com'
            query(name, 'A')
            assert '192.0.2.123' in run('/sbin/pfctl', '-t', TABLE, '-T', 'show')
            print('Resolved answer -> PF before DNS response: passed', flush=True)
            before = upstream.queries
            run('/sbin/pfctl', '-t', TABLE, '-T', 'flush')
            query(name, 'A')
            assert upstream.queries == before, 'Response was not served from cache'
            assert '192.0.2.123' in run('/sbin/pfctl', '-t', TABLE, '-T', 'show')
            print('Cached answer -> PF: passed', flush=True)
            query(name, 'AAAA')
            assert '2001:db8::123' in run('/sbin/pfctl', '-t', TABLE, '-T', 'show')
            print('IPv6 answer -> PF: passed', flush=True)
            query(name, 'HTTPS')
            current = run('/sbin/pfctl', '-t', TABLE, '-T', 'show')
            assert '192.0.2.124' in current and '2001:db8::124' in current
            print('HTTPS address hints -> PF: passed', flush=True)
            query('local.xray-agent.example.com', 'A')
            assert '192.0.2.125' in run('/sbin/pfctl', '-t', TABLE, '-T', 'show')
            print('Local answer -> PF: passed', flush=True)
    finally:
        if process:
            process.terminate()
            process.wait(timeout=5)
        stop.set()
        worker.join(timeout=3)
        upstream.shutdown()
        upstream.server_close()
        subprocess.run(['/sbin/pfctl', '-t', TABLE, '-T', 'kill'], capture_output=True)
        shutil.rmtree(temporary)


if __name__ == '__main__':
    main()
