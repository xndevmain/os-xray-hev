<?php
require __DIR__ . '/../plugin/scripts/Xray/xray-config.php';
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$outbound = ['protocol' => 'socks', 'settings' => ['servers' => [['address' => '127.0.0.1', 'port' => 19001]]]];
$base = ['outbound_config' => json_encode($outbound), 'socks5_listen' => '0.0.0.0', 'socks5_port' => 19000, 'tun_iface' => 'proxyhev2socks0', 'mtu' => 1500];
$single = xray_build_config($base);
check(!isset($single['observatory']), 'Single proxy remains compatible');
check($single['inbounds'][0]['settings']['ip'] === '127.0.0.1', 'Wildcard is not advertised as UDP relay');
$poolBase = array_merge($base, ['outbound_config' => json_encode([$outbound, $outbound]), 'direct_domains' => 'geosite:google', 'proxy_ips' => 'geoip:private', 'default_route' => 'direct']);
$pool = xray_build_config($poolBase);
check($pool['routing']['balancers'][0]['fallbackTag'] === 'unavailable', 'All-dead pool must block');
check($pool['routing']['balancers'][0]['strategy']['type'] === 'leastPing', 'Pool uses health observations');
check($pool['observatory']['subjectSelector'] === ['proxy-'], 'Every pool member is probed');
check(count($pool['routing']['rules']) === 1, 'Legacy destination lists do not bypass the pool');
check(end($pool['routing']['rules'])['balancerTag'] === 'proxy-pool', 'Unmatched traffic follows default route');
check(xray_entries("geoip:ru,\ngeoip:private\r\n") === ['geoip:ru', 'geoip:private'], 'Rule delimiters');
$hev = hev_build_config($base);
check(str_contains($hev, "address: '127.0.0.1'"), 'Hev connects to loopback for wildcard listen');
check(!str_contains($hev, 'pid-file:') && !str_contains($hev, 'ipv4:'), 'daemon and OPNsense retain lifecycle/address ownership');
foreach (['{}', '[]', 'null', '{', '[{"protocol":"freedom"}]', '{"outbounds":[]}'] as $bad) {
    try {
        xray_outbounds($bad);
        throw new RuntimeException('Accepted invalid proxy input: ' . $bad);
    } catch (InvalidArgumentException | JsonException $expected) {
    }
}
if (!is_dir(__DIR__ . '/output')) {
    mkdir(__DIR__ . '/output');
}
file_put_contents(__DIR__ . '/output/single.json', json_encode($single, JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . '/output/pool.json', json_encode($pool, JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . '/output/hev.yaml', $hev);
echo "Config regression checks passed\n";
