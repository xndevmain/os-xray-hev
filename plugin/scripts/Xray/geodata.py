"""Read the documented v2ray GeoSiteList/GeoIPList protobuf wire format.

No generated protobuf package is required on the firewall.
"""
import ipaddress
import hashlib
import json
import re
import signal
import time


def varint(data, offset):
    value = 0
    for shift in range(0, 70, 7):
        if offset >= len(data):
            raise ValueError('Truncated protobuf varint')
        byte = data[offset]
        offset += 1
        value |= (byte & 127) << shift
        if byte < 128:
            return value, offset
    raise ValueError('Oversized protobuf varint')


def fields(data):
    offset = 0
    while offset < len(data):
        tag, offset = varint(data, offset)
        number, wire = tag >> 3, tag & 7
        if not number:
            raise ValueError('Invalid protobuf field')
        if wire == 0:
            value, offset = varint(data, offset)
        elif wire in (1, 2, 5):
            if wire == 2:
                size, offset = varint(data, offset)
            else:
                size = 8 if wire == 1 else 4
            if offset + size > len(data):
                raise ValueError('Truncated protobuf field')
            value = data[offset:offset + size]
            offset += size
        else:
            raise ValueError('Unsupported protobuf wire type')
        yield number, value


def categories(path, selected=None):
    result = {}
    with open(path, 'rb') as source:
        data = source.read(67108865)
    if len(data) > 67108864:
        raise ValueError('Geodata file exceeds 64 MiB')
    for field, entry in fields(data):
        if field == 1:
            name = next((value.decode('utf-8').lower() for key, value in fields(entry) if key == 1), '')
            if selected is not None and name not in selected:
                continue
            values = list(fields(entry))
            if not name or name in result:
                raise ValueError('Missing or duplicate category name')
            result[name] = values
    if not result and selected is None:
        raise ValueError('No geodata categories found')
    return result


def selectors(raw, prefix):
    return [item.strip().lower().removeprefix(prefix + ':') for item in re.split('[,\r\n]+', raw) if item.strip()]


def validate_database(database, kind):
    for category in database.values():
        for key, value in category:
            if key != 2:
                continue
            entry = dict(fields(value))
            if kind == 'geoip':
                if not isinstance(entry.get(1), bytes) or len(entry[1]) not in (4, 16):
                    raise ValueError('Invalid GeoIP address')
                prefix = entry.get(2, 0)
                if not isinstance(prefix, int) or not 0 <= prefix <= len(entry[1]) * 8:
                    raise ValueError('Invalid GeoIP prefix')
            else:
                if entry.get(1, 0) not in (0, 1, 2, 3) or not isinstance(entry.get(2), bytes):
                    raise ValueError('Invalid GeoSite domain')
                if not entry[2].decode('utf-8'):
                    raise ValueError('Empty GeoSite domain')


class DomainMatcher:
    def __init__(self, database, selected, custom_domains="", custom_hosts=""):
        self.full, self.suffix, self.keywords, self.regex = set(), set(), set(), []
        for selected_item in selectors(selected, 'geosite'):
            name, *filters = selected_item.split('@')
            if name not in database:
                raise ValueError('Unknown geosite category: ' + name)
            for key, value in database[name]:
                if key != 2:
                    continue
                entry = list(fields(value))
                kind = next((v for k, v in entry if k == 1), 0)
                text = next((v.decode('utf-8') for k, v in entry if k == 2), '')
                attributes = {v.decode('utf-8').lower() for k, a in entry if k == 3 for f, v in fields(a) if f == 1}
                if any((attribute[1:] in attributes) if attribute.startswith('!') else (attribute not in attributes) for attribute in filters):
                    continue
                if kind == 0:
                    self.keywords.add(text.lower())
                elif kind == 1:
                    # Community patterns are RE2 expressions. Reject unsupported
                    # constructs explicitly instead of quietly dropping entries.
                    if '(?<' in text or re.search(r'\\[1-9]', text):
                        raise ValueError('Unsupported geosite regex: ' + text)
                    self.regex.append(re.compile(text.replace(r'\z', r'\Z')))
                elif kind == 2:
                    self.suffix.add(text.lower())
                elif kind == 3:
                    self.full.add(text.lower())
                else:
                    raise ValueError('Unknown geosite domain type')
        for raw, target in [(custom_domains, self.suffix), (custom_hosts, self.full)]:
            for value in re.split(r'[,\s]+', raw.strip()):
                if not value:
                    continue
                name = value.rstrip('.').encode('idna').decode('ascii').lower()
                labels = name.split('.')
                if len(name) > 253 or len(labels) < 2 or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label) for label in labels):
                    raise ValueError('Invalid custom domain: ' + value)
                try:
                    ipaddress.ip_address(name)
                except ValueError:
                    target.add(name)
                else:
                    raise ValueError('Use domain names, not IP addresses: ' + value)
        self.signature = hashlib.sha256(json.dumps([
            sorted(self.full), sorted(self.suffix), sorted(self.keywords),
            [pattern.pattern for pattern in self.regex],
        ], sort_keys=True).encode()).hexdigest()

    def matches(self, name):
        name = name.rstrip('.').lower()
        if name in self.full or any(keyword in name for keyword in self.keywords):
            return True
        labels = name.split('.')
        if any('.'.join(labels[index:]) in self.suffix for index in range(len(labels))):
            return True
        if not self.regex:
            return False
        # Python re is backtracking, unlike Xray's RE2. Bound work on FreeBSD
        # so an adversarial DNS name cannot hang the privileged broker.
        if not hasattr(signal, 'setitimer'):  # Portable development/test host.
            return any(pattern.search(name) for pattern in self.regex)
        previous_handler = signal.getsignal(signal.SIGALRM)
        def timed_out(*_):
            raise TimeoutError('Geosite regex matching exceeded its time budget')
        signal.signal(signal.SIGALRM, timed_out)
        started = time.monotonic()
        previous_timer = signal.setitimer(signal.ITIMER_REAL, 0.05)
        try:
            return any(pattern.search(name) for pattern in self.regex)
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, previous_handler)
            if previous_timer[0]:
                signal.setitimer(signal.ITIMER_REAL, max(0.001, previous_timer[0] - (time.monotonic() - started)), previous_timer[1])


def networks(database, selected):
    result = set()
    for name in selectors(selected, 'geoip'):
        if name not in database:
            raise ValueError('Unknown geoip category: ' + name)
        for key, value in database[name]:
            if key == 3 and value:
                raise ValueError('Inverse GeoIP categories cannot be exported as a PF table')
            if key == 2:
                entry = dict(fields(value))
                address = ipaddress.ip_address(entry[1])
                prefix = entry.get(2, 0)
                result.add(str(ipaddress.ip_network((address, prefix), strict=False)))
    return sorted(result)
