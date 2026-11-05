<?php
/** Pure config builders shared by service control, tests and geodata validation. */
function xray_entries(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $value))));
}

function xray_firewall_routes(string $assetDir): array
{
    $general = \OPNsense\Core\Config::getInstance()->object()->OPNsense->xray->general;
    $domains = trim((string)($general->firewall_geosite ?? ''));
    $ips = trim((string)($general->firewall_geoip ?? ''));
    $custom = trim((string)($general->custom_domains ?? ''));
    $hosts = trim((string)($general->custom_hosts ?? ''));
    $routes = [[
        'table' => 'XRAY_HOSTS', 'geosite' => $domains, 'geoip' => $ips, 'custom_domains' => $custom, 'custom_hosts' => $hosts]];
    return ['asset_dir' => realpath($assetDir) ?: $assetDir, 'routes' => $routes];
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
