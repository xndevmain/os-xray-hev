#!/usr/local/bin/python3
"""Validate and normalize plain-text IP/CIDR routing lists."""
import argparse
import ipaddress
import json
from pathlib import Path
import sys


def parse_text(text, max_entries=65536, require_global=True):
    networks = set()
    for number, raw in enumerate(text.splitlines(), 1):
        value = raw.split('#', 1)[0].strip()
        if not value:
            continue
        if any(char.isspace() for char in value) or ',' in value:
            raise ValueError(f'Line {number}: expected one IP address or CIDR')
        try:
            network = ipaddress.ip_network(value, strict=False)
        except ValueError as error:
            raise ValueError(f'Line {number}: invalid IP address or CIDR') from error
        if require_global and not network.network_address.is_global:
            raise ValueError(f'Line {number}: only global networks are allowed')
        networks.add(network)
        if len(networks) > max_entries:
            raise ValueError(f'List exceeds {max_entries} entries')
    return [str(network) for network in sorted(
        networks, key=lambda item: (item.version, item.network_address, item.prefixlen))]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('path', nargs='?')
    parser.add_argument('--max-entries', type=int, default=65536)
    args = parser.parse_args()
    text = Path(args.path).read_text(encoding='utf-8') if args.path else sys.stdin.read()
    try:
        result = parse_text(text, max_entries=args.max_entries)
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 1
    print(json.dumps(result, separators=(',', ':')))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
