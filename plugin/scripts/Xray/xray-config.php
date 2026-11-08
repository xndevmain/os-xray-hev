<?php
/** Pure config builders shared by service control, tests and geodata validation. */
function xray_entries(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $value))));
}

function xray_route_alias(string $uuid): string
{
    if (!preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', $uuid)) {
        throw new InvalidArgumentException('Invalid routing list UUID');
    }
    return 'XRAY_' . substr(str_replace('-', '', strtolower($uuid)), 0, 12);
}

function xray_static_networks(string $value): array
{
    $result = [];
    foreach (xray_entries($value) as $network) {
        [$address, $prefix] = array_pad(explode('/', $network, 2), 2, null);
        $version = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4
            : (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 6 : 0);
        $limit = $version === 4 ? 32 : 128;
        if ($version === 0 || ($prefix !== null && (!ctype_digit($prefix) || (int)$prefix > $limit))) {
            throw new InvalidArgumentException('Invalid static network: ' . $network);
        }
        $result[] = $network;
    }
    return $result;
}

function xray_firewall_routes_from(array $general, array $lists, string $assetDir, string $cacheDir): array
{
    $routes = [[
        'table' => 'XRAY_HOSTS',
        'geosite' => trim((string)($general['firewall_geosite'] ?? '')),
        'geoip' => trim((string)($general['firewall_geoip'] ?? '')),
        'custom_domains' => trim((string)($general['custom_domains'] ?? '')),
        'custom_hosts' => trim((string)($general['custom_hosts'] ?? '')),
    ]];
    foreach ($lists as $list) {
        if ((string)($list['enabled'] ?? '0') !== '1') {
            continue;
        }
        $alias = xray_route_alias((string)($list['uuid'] ?? ''));
        $static = xray_static_networks((string)($list['networks'] ?? ''));
        $cache = $cacheDir . '/' . $alias . '.json';
        if (is_file($cache)) {
            $cached = json_decode((string)file_get_contents($cache), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($cached) || !array_is_list($cached) || count($cached) > 65536) {
                throw new RuntimeException('Invalid cached routing list: ' . $alias);
            }
            $static = array_merge($static, array_map('strval', $cached));
        }
        $static = array_values(array_unique($static));
        usort($static, static function (string $left, string $right): int {
            [$leftIp, $leftPrefix] = array_pad(explode('/', $left, 2), 2, '');
            [$rightIp, $rightPrefix] = array_pad(explode('/', $right, 2), 2, '');
            $leftPacked = inet_pton($leftIp);
            $rightPacked = inet_pton($rightIp);
            return strlen($leftPacked) <=> strlen($rightPacked)
                ?: strcmp($leftPacked, $rightPacked)
                ?: ((int)$leftPrefix <=> (int)$rightPrefix);
        });
        $routes[] = [
            'table' => $alias, 'geosite' => '', 'geoip' => '',
            'custom_domains' => '', 'custom_hosts' => '', 'static_ips' => $static,
        ];
    }
    return ['asset_dir' => realpath($assetDir) ?: $assetDir, 'routes' => $routes];
}

function xray_rule_alias_references(string $value): array
{
    $result = [];
    foreach (preg_split('/[,\s]+/', $value) as $name) {
        $name = trim($name);
        if ($name === '') continue;
        // pf negation syntax: normalize a leading ! so stale alias references
        // like !XRAY_abc are still detected for safe flush.
        if ($name[0] === '!') $name = substr($name, 1);
        if ($name !== '') $result[] = $name;
    }
    return $result;
}

function xray_is_missing_table_error(int $rc, string $output): bool
{
    if ($rc === 0) return false;
    // rc.filter_configure may skip creating a table for an external alias that
    // no enabled rule references; flushing it then fails benignly.
    return (bool)preg_match('/table does not exist|no such table/i', $output);
}

function xray_stale_managed_aliases(array $routeNames, array $aliases, array $referenced): array
{
    $active = array_fill_keys($routeNames, true);
    $references = array_fill_keys($referenced, true);
    $result = ['delete' => [], 'flush' => []];
    foreach ($aliases as $alias) {
        $name = (string)($alias['name'] ?? '');
        if (isset($active[$name]) || !preg_match('/^XRAY_[0-9a-f]{12}$/D', $name)
            || (string)($alias['type'] ?? '') !== 'external'
            || (string)($alias['description'] ?? '') !== 'Managed by os-xray DNS routing') {
            continue;
        }
        $result[isset($references[$name]) ? 'flush' : 'delete'][] = $name;
    }
    sort($result['delete'], SORT_STRING);
    sort($result['flush'], SORT_STRING);
    return $result;
}

function xray_firewall_routes(string $assetDir): array
{
    $root = \OPNsense\Core\Config::getInstance()->object();
    $general = $root->OPNsense->xray->general;
    $settings = [
        'firewall_geosite' => (string)($general->firewall_geosite ?? ''),
        'firewall_geoip' => (string)($general->firewall_geoip ?? ''),
        'custom_domains' => (string)($general->custom_domains ?? ''),
        'custom_hosts' => (string)($general->custom_hosts ?? ''),
    ];
    $lists = [];
    foreach ($root->OPNsense->xray->routinglists->list ?? [] as $item) {
        $lists[] = [
            'uuid' => (string)$item['uuid'], 'enabled' => (string)($item->enabled ?? '0'),
            'name' => (string)($item->name ?? ''), 'networks' => (string)($item->networks ?? ''),
        ];
    }
    return xray_firewall_routes_from($settings, $lists, $assetDir, '/var/db/xray-routing-lists');
}

function xray_outbounds(string $raw): array
{
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !$data) {
        throw new InvalidArgumentException('Provide an outbound object or a non-empty array of outbounds');
    }
    $items = isset($data['protocol']) ? [$data] : $data;
    if (!array_is_list($items) || count($items) > 32) {
        throw new InvalidArgumentException('Expected at most 32 outbound objects');
    }
    foreach ($items as $index => &$item) {
        if (!is_array($item) || empty($item['protocol']) || in_array($item['protocol'], ['freedom', 'direct', 'blackhole', 'block', 'loopback'])) {
            throw new InvalidArgumentException('Pool entries must be proxy outbound objects');
        }
        $item['tag'] = 'proxy-' . ($index + 1);
    }
    unset($item);
    return $items;
}

function xray_build_config(array $c): array
{
    $proxies = xray_outbounds($c['outbound_config'] ?? '');
    $pool = count($proxies) > 1;
    $via = $pool ? ['balancerTag' => 'proxy-pool'] : ['outboundTag' => 'proxy-1'];
    $rules = [];
    $rules[] = array_merge(['type' => 'field', 'inboundTag' => ['socks-in']], $via);
    $routing = ['domainStrategy' => 'AsIs', 'rules' => $rules];
    if ($pool) {
        $routing['balancers'] = [[
            'tag' => 'proxy-pool', 'selector' => ['proxy-'],
            'fallbackTag' => 'unavailable', 'strategy' => ['type' => 'leastPing'],
        ]];
    }
    $config = [
        'log' => ['loglevel' => $c['loglevel'] ?? 'warning'],
        'inbounds' => [[
            'tag' => 'socks-in', 'listen' => $c['socks5_listen'] ?? '127.0.0.1',
            'port' => (int)($c['socks5_port'] ?? 10808), 'protocol' => 'socks',
            'sniffing' => ['enabled' => true, 'destOverride' => ['http', 'tls', 'quic'], 'routeOnly' => true],
            'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => ($c['socks5_listen'] ?? '127.0.0.1') === '0.0.0.0' ? '127.0.0.1' : ($c['socks5_listen'] ?? '127.0.0.1')],
        ]],
        'outbounds' => array_merge($proxies, [
            ['tag' => 'unavailable', 'protocol' => 'blackhole'],
        ]),
        'routing' => $routing,
    ];
    if ($pool) {
        $config['observatory'] = [
            'subjectSelector' => ['proxy-'],
            'probeUrl' => $c['probe_url'] ?? 'https://www.gstatic.com/generate_204',
            'probeInterval' => max(5, (int)($c['probe_interval'] ?? 30)) . 's',
            'enableConcurrency' => true,
        ];
    }
    return $config;
}

function hev_build_config(array $c): string
{
    $quote = static fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";
    $addr = $c['socks5_listen'] === '0.0.0.0' ? '127.0.0.1' : $c['socks5_listen'];
    // Address assignment belongs to OPNsense, not Hev. Keep Hev in foreground
    // so FreeBSD daemon(8) owns the child PID used by stop/watchdog.
    return "tunnel:\n  name: " . $quote($c['tun_iface']) . "\n  mtu: " . (int)$c['mtu']
        . "\nsocks5:\n  address: " . $quote($addr) . "\n  port: " . (int)$c['socks5_port']
        . "\n  udp: 'udp'\nmisc:\n  log-file: stderr\n  log-level: warn\n";
}
