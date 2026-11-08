#!/usr/local/bin/python3
"""Validate and normalize plain-text suffix-domain routing lists."""
import argparse
import ipaddress
import json
from pathlib import Path
import re
import sys

LABEL = re.compile(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
TYPE_PREFIXES = ('regexp:', 'domain:', 'full:', 'keyword:')


def parse_text(text, max_entries=65536):
    domains = set()
    for number, raw in enumerate(text.splitlines(), 1):
        value = raw.split('#', 1)[0].strip()
        if not value:
            continue
        if any(char.isspace() for char in value) or ',' in value:
            raise ValueError(f'Line {number}: expected one domain name')
        value = value.lower()
        if value.startswith('*.'):
            value = value[2:]
        value = value.lstrip('.').rstrip('.')
        if any(value.startswith(prefix) for prefix in TYPE_PREFIXES):
            raise ValueError(f'Line {number}: expected a plain suffix domain, not a typed rule')
        try:
            name = value.encode('idna').decode('ascii')
        except UnicodeError as error:
            raise ValueError(f'Line {number}: invalid internationalized domain name') from error
        labels = name.split('.')
        if len(name) > 253 or len(labels) < 2 or any(not LABEL.fullmatch(label) for label in labels):
            raise ValueError(f'Line {number}: invalid domain name')
        try:
            ipaddress.ip_address(name)
        except ValueError:
            pass
        else:
            raise ValueError(f'Line {number}: use domain names, not IP addresses')
        domains.add(name)
        if len(domains) > max_entries:
            raise ValueError(f'List exceeds {max_entries} entries')
    return sorted(domains)


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
