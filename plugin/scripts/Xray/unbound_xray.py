"""Unbound pythonmod: publish answer IPs before replying, including cache hits.

Only the root broker can change PF; the Unbound worker uses a local Unix socket.
"""
import json
import socket
import struct
import time

SOCKET_PATH = '/run/xray-routing.sock'  # Relative to Unbound's chroot.
DNSBL_PATH = 'unbound-dnsbl/dnsbl_module.py'
_dnsbl = None
_callbacks = {}


def _capture(kind):
    def register(callback, env, id):
        _callbacks.setdefault(kind, []).append(callback)
        return True
    return register


def _original_callbacks(kind, *args, **kwargs):
    return all(callback(*args, **kwargs) for callback in _callbacks.get(kind, []))


def answer_addresses(kind, raw):
    if len(raw) < 2 or struct.unpack('!H', raw[:2])[0] != len(raw) - 2:
        return []
    payload = raw[2:]
    if kind in (1, 28):
        size, family = (4, socket.AF_INET) if kind == 1 else (16, socket.AF_INET6)
        return [socket.inet_ntop(family, payload)] if len(payload) == size else []
    # HTTPS/SVCB clients may connect using ipv4hint/ipv6hint without querying A/AAAA.
    if kind not in (64, 65) or len(payload) < 3:
        return []
    offset = 2  # SvcPriority
    while offset < len(payload):
        size = payload[offset]
        offset += 1
        if size == 0:
            break
        if size > 63 or offset + size > len(payload):
            return []  # Compression is not permitted in SVCB TargetName.
        offset += size
    result = []
    while offset + 4 <= len(payload):
        key, length = struct.unpack('!HH', payload[offset:offset + 4])
        offset += 4
        value = payload[offset:offset + length]
        if len(value) != length:
            return []
        if key in (4, 6):
            size, family = (4, socket.AF_INET) if key == 4 else (16, socket.AF_INET6)
            if length % size:
                return []
            result.extend(socket.inet_ntop(family, value[index:index + size]) for index in range(0, length, size))
        offset += length
    return result


def publish(qinfo, qstate, rep, rcode, edns, opt_list_out, region, **kwargs):
    if rcode != 0 or rep is None:
        return True
    try:
        records = []
        # Only the answer section, never unrelated additional/glue records.
        for index in range(rep.an_numrrsets):
            rrset = rep.rrsets[index]
            kind = socket.ntohs(rrset.rk.type)
            if kind not in (1, 28, 64, 65) or socket.ntohs(rrset.rk.rrset_class) != 1:
                continue
            data = rrset.entry.data
            for item in range(data.count):
                raw = bytes(data.rr_data[item])
                ttl = int(data.rr_ttl[item])
                if kwargs.get('absolute_ttl'):
                    ttl = max(0, ttl - int(time.time()))
                records.extend([address, ttl] for address in answer_addresses(kind, raw))
        if not records:
            return True
        message = json.dumps({'name': qinfo.qname_str, 'records': records}).encode() + b'\n'
        if len(message) > 65536:
            raise ValueError('DNS answer too large for routing update')
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
            client.settimeout(2)
            client.connect(SOCKET_PATH)
            client.sendall(message)
            if client.recv(16) != b'OK\n':
                raise RuntimeError('PF update was rejected')
    except Exception as error:
        log_err('xray-routing: ' + str(error))
        # A callback failure does not guarantee suppression of the DNS response.
        # The broker failure is logged; this module never claims a DNS kill switch.
        return False
    return True


def init_standard(id, env):
    global _dnsbl
    # OPNsense's checkconf whitelists only one Python module. Load the stock
    # DNSBL in an isolated namespace and compose its callbacks under that ID.
    # The stock source is neither patched nor replaced.
    _callbacks.clear()
    _dnsbl = dict(globals())
    _dnsbl['__file__'] = DNSBL_PATH
    _dnsbl['mod_env'] = dict(globals().get('mod_env', {}))
    for kind in ('reply', 'reply_cache', 'reply_local', 'reply_servfail'):
        _dnsbl['register_inplace_cb_' + kind] = _capture(kind)
    with open(DNSBL_PATH, 'rb') as source:
        exec(compile(source.read(), DNSBL_PATH, 'exec'), _dnsbl)
    if 'init_standard' in _dnsbl and _dnsbl['init_standard'] is not init_standard:
        initialized = _dnsbl['init_standard'](id, env)
    else:
        initialized = _dnsbl['init'](id, env.cfg)
    if not initialized:
        return False
    return (register_inplace_cb_reply(publish_resolved, env, id)
            and register_inplace_cb_reply_cache(publish_cached, env, id)
            and register_inplace_cb_reply_local(publish_local, env, id)
            and register_inplace_cb_reply_servfail(publish_servfail, env, id))


def publish_resolved(*args, **kwargs):
    return _original_callbacks('reply', *args, **kwargs) and publish(*args, **kwargs)


def publish_cached(*args, **kwargs):
    if not _original_callbacks('reply_cache', *args, **kwargs):
        return False
    kwargs['absolute_ttl'] = True
    return publish(*args, **kwargs)


def publish_local(*args, **kwargs):
    return _original_callbacks('reply_local', *args, **kwargs) and publish(*args, **kwargs)


def publish_servfail(*args, **kwargs):
    return _original_callbacks('reply_servfail', *args, **kwargs)


def deinit(id):
    return _dnsbl['deinit'](id) if _dnsbl else True


def inform_super(id, qstate, superqstate, qdata):
    return _dnsbl['inform_super'](id, qstate, superqstate, qdata) if _dnsbl else True


def operate(id, event, qstate, qdata):
    return _dnsbl['operate'](id, event, qstate, qdata)
