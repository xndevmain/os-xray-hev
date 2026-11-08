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
check(xray_route_alias('01234567-89ab-cdef-0123-456789abcdef') === 'XRAY_0123456789ab', 'Routing list alias is stable');
try {
    xray_route_alias('not-a-uuid');
    throw new RuntimeException('Accepted an invalid routing list UUID');
} catch (InvalidArgumentException $expected) {
}
$temp = sys_get_temp_dir() . '/xray-list-test-' . bin2hex(random_bytes(4));
mkdir($temp);
try {
    file_put_contents($temp . '/XRAY_0123456789ab.json', json_encode(['8.8.8.8/32', '1.1.1.0/24']));
    $routes = xray_firewall_routes_from([
        'firewall_geosite' => 'discord', 'firewall_geoip' => '',
        'custom_domains' => '', 'custom_hosts' => '',
    ], [[
        'uuid' => '01234567-89ab-cdef-0123-456789abcdef', 'enabled' => '1',
        'name' => 'Discord voice', 'networks' => "104.29.136.0/21\n104.29.144.0/20",
    ], [
        'uuid' => 'fedcba98-7654-3210-fedc-ba9876543210', 'enabled' => '0',
        'name' => 'Disabled', 'networks' => '9.9.9.9',
    ]], '/assets', $temp);
    check(count($routes['routes']) === 2, 'Enabled routing lists append one route each');
    check($routes['routes'][1]['table'] === 'XRAY_0123456789ab', 'Routing list uses derived alias');
    check($routes['routes'][1]['static_ips'] === [
        '1.1.1.0/24', '8.8.8.8/32', '104.29.136.0/21', '104.29.144.0/20'], 'Cached and inline networks are combined');
    $cleanup = xray_stale_managed_aliases(['XRAY_HOSTS', 'XRAY_0123456789ab'], [
        ['name'=>'XRAY_0123456789ab','type'=>'external','description'=>'Managed by os-xray DNS routing'],
        ['name'=>'XRAY_111111111111','type'=>'external','description'=>'Managed by os-xray DNS routing'],
        ['name'=>'XRAY_222222222222','type'=>'external','description'=>'Managed by os-xray DNS routing'],
        ['name'=>'XRAY_333333333333','type'=>'network','description'=>'User alias'],
    ], ['XRAY_222222222222']);
    check($cleanup === ['delete'=>['XRAY_111111111111'], 'flush'=>['XRAY_222222222222']], 'Stale managed aliases are removed or safely emptied when referenced');
    check(xray_rule_alias_references("lan, !XRAY_111111111111 XRAY_222222222222") === ['lan', 'XRAY_111111111111', 'XRAY_222222222222'], 'Negated alias references are normalized');
    $negated = xray_stale_managed_aliases(['XRAY_HOSTS'], [
        ['name'=>'XRAY_111111111111','type'=>'external','description'=>'Managed by os-xray DNS routing'],
    ], xray_rule_alias_references('!XRAY_111111111111'));
    check($negated === ['delete'=>[], 'flush'=>['XRAY_111111111111']], 'Negated reference keeps stale alias for safe flush');
} finally {
    @unlink($temp . '/XRAY_0123456789ab.json');
    @rmdir($temp);
}
check(xray_is_missing_table_error(1, 'pfctl: Table does not exist.'), 'Absent table is tolerated');
check(xray_is_missing_table_error(1, "pfctl: no such table 'XRAY_111111111111'"), 'Alternate absent-table wording is tolerated');
check(!xray_is_missing_table_error(0, ''), 'Successful flush is not an error');
check(!xray_is_missing_table_error(1, 'pfctl: Permission denied'), 'Real flush errors still fail');
check(!xray_is_missing_table_error(1, ''), 'Empty failure output still fails');
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
