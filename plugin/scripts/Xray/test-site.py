#!/usr/local/bin/python3
"""Test active DNS-to-PF routing without fetching a website or changing rules."""
import ipaddress
import json
from pathlib import Path
import re
import runpy
import subprocess
import sys
import xml.etree.ElementTree as ET

import dns.resolver


def test_site(host):
    host = host.rstrip('.').encode('idna').decode('ascii').lower()
    if len(host) > 253 or len(host.split('.')) < 2 or any(
        not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label)
        for label in host.split('.')
    ):
        raise ValueError('Enter a valid hostname')
    try:
        ipaddress.ip_address(host)
    except ValueError:
        pass
    else:
        raise ValueError('Enter a domain name, not an IP address')
    path = Path('/usr/local/etc/xray-routing.json')
    if not path.exists():
        raise ValueError('Apply Firewall Lists first')
    load_routes = runpy.run_path(str(Path(__file__).with_name('xray-routing.py')))['load_routes']
    routes = load_routes(json.loads(path.read_text()))
    matcher, static = routes.get('XRAY_HOSTS', (None, []))
    match = bool(matcher and matcher.matches(host))
    root = ET.parse('/conf/config.xml').getroot()
    resolver = dns.resolver.Resolver(configure=False)
    resolver.nameservers = ['127.0.0.1']
    resolver.port = int(root.findtext('./OPNsense/unboundplus/general/port') or '53')
    resolver.timeout = 2
    resolver.lifetime = 4
    addresses, errors = {}, []
    for kind in ['A', 'AAAA']:
        try:
            answer = resolver.resolve(host, kind, search=False)
            for record in answer:
                addresses[record.address] = kind
        except dns.resolver.NoAnswer:
            pass
        except dns.exception.DNSException as error:
            errors.append(kind + ': ' + type(error).__name__)
    result = subprocess.run(['/sbin/pfctl', '-t', 'XRAY_HOSTS', '-T', 'show'],
                            capture_output=True, text=True, timeout=5)
    if result.returncode:
        raise ValueError('Cannot read XRAY_HOSTS. Apply Firewall Lists first.')
    networks = [ipaddress.ip_network(line.strip(), strict=False)
                for line in result.stdout.splitlines() if line.strip()]
    static_networks = [ipaddress.ip_network(value) for value in static]
    rows = []
    for address, kind in addresses.items():
        ip = ipaddress.ip_address(address)
        rows.append({'address': address, 'type': kind,
                     'in_alias': any(ip in net for net in networks),
                     'geoip_match': any(ip in net for net in static_networks)})
    return {'result': 'ok', 'host': host, 'domain_match': match, 'addresses': rows,
            'dns_errors': errors, 'resolver': '127.0.0.1:' + str(resolver.port)}


if __name__ == '__main__':
    try:
        print(json.dumps(test_site(sys.argv[1] if len(sys.argv) == 2 else '')))
    except Exception as error:
        print(json.dumps({'result': 'failed', 'message': str(error)}))
