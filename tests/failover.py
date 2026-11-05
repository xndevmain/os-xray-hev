"""Exercise real Xray with local SOCKS proxies: failover, all-dead, recovery.

Run after php tests/config.php: python tests/failover.py /path/to/xray
No external network or production proxy credentials are used.
"""
import json
import os
from pathlib import Path
import socket
import socketserver
import struct
import subprocess
import sys
import threading
import time


def read_exact(sock, count):
    result = b''
    while len(result) < count:
        chunk = sock.recv(count - len(result))
        if not chunk:
            raise ConnectionError('closed')
        result += chunk
    return result


class Proxy(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        try:
            self.request.settimeout(3)
            version, methods = read_exact(self.request, 2)
            read_exact(self.request, methods)
            if not self.server.alive.is_set():
                return
            self.request.sendall(b'\x05\x00')
            _, _, _, kind = read_exact(self.request, 4)
            if kind == 1:
                read_exact(self.request, 4)
            elif kind == 3:
                read_exact(self.request, read_exact(self.request, 1)[0])
            elif kind == 4:
                read_exact(self.request, 16)
            read_exact(self.request, 2)
            self.request.sendall(b'\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x50')
            request = b''
            while b'\r\n\r\n' not in request:
                request += read_exact(self.request, 1)
            time.sleep(self.server.delay)
            body = self.server.label.encode()
            self.request.sendall(b'HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: '
                                 + str(len(body)).encode() + b'\r\n\r\n' + body)
        except (OSError, ConnectionError):
            pass


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def request(port):
    try:
        with socket.create_connection(('127.0.0.1', port), 1) as sock:
            sock.settimeout(2)
            sock.sendall(b'\x05\x01\x00')
            if read_exact(sock, 2) != b'\x05\x00':
                return None
            sock.sendall(b'\x05\x01\x00\x01\x7f\x00\x00\x01' + struct.pack('!H', 80))
            reply = read_exact(sock, 4)
            if reply[1] != 0:
                return None
            read_exact(sock, 4 if reply[3] == 1 else 16)
            read_exact(sock, 2)
            sock.sendall(b'GET /data HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n')
            result = b''
            while True:
                part = sock.recv(4096)
                if not part:
                    break
                result += part
            return result.partition(b'\r\n\r\n')[2].decode() or None
    except (OSError, ConnectionError):
        return None


def wait_for(port, expected, name):
    deadline = time.monotonic() + 25
    while time.monotonic() < deadline:
        if request(port) == expected:
            print(name + ': passed', flush=True)
            return
        time.sleep(.25)
    raise AssertionError(name)


def main():
    root = Path(__file__).resolve().parent
    servers = []
    process = None
    try:
        for label, delay in [('primary', 0), ('backup', .2)]:
            server = Proxy(('127.0.0.1', 0), Handler)
            server.label, server.delay, server.alive = label, delay, threading.Event()
            server.alive.set()
            threading.Thread(target=server.serve_forever, daemon=True).start()
            servers.append(server)
        config = json.loads((root / 'output/pool.json').read_text())
        port = free_port()
        config['log']['loglevel'] = 'debug'
        config['inbounds'][0].update(listen='127.0.0.1', port=port)
        for index, server in enumerate(servers):
            config['outbounds'][index]['settings']['servers'][0]['port'] = server.server_address[1]
        config['routing']['rules'] = [{'type': 'field', 'inboundTag': ['socks-in'], 'balancerTag': 'proxy-pool'}]
        config['observatory'].update(probeUrl='http://127.0.0.1/health', probeInterval='1s')
        path = root / 'output/failover.json'
        path.write_text(json.dumps(config))
        with (root / 'output/failover.log').open('w') as log:
            process = subprocess.Popen([sys.argv[1], 'run', '-c', str(path)], stdout=log, stderr=log,
                                       creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
            wait_for(port, 'primary', 'Initial healthy proxy')
            servers[0].alive.clear()
            wait_for(port, 'backup', 'Failed proxy excluded')
            servers[1].alive.clear()
            # Give Observatory a full cycle; assert repeated failures, not a transient one.
            time.sleep(4)
            assert all(request(port) is None for _ in range(3)), 'All-dead pool leaked traffic'
            print('All-dead pool blocks: passed', flush=True)
            servers[0].alive.set()
            wait_for(port, 'primary', 'Recovered proxy restored')
    finally:
        if process:
            process.terminate()
            process.wait(timeout=5)
        for server in servers:
            server.shutdown()
            server.server_close()


if __name__ == '__main__':
    main()
