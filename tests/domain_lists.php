<?php
require __DIR__ . '/../plugin/scripts/Xray/xray-config.php';
require __DIR__ . '/../plugin/scripts/Xray/xray-ip-lists.php';

function check_domain_list(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$domainParse = static function (string $raw): array {
    $items = array_values(array_unique(array_map('strtolower', array_filter(array_map('trim', explode("\n", $raw))))));
    sort($items, SORT_STRING);
    return $items;
};

$directory = sys_get_temp_dir() . '/xray-domain-lists-' . bin2hex(random_bytes(4));
mkdir($directory);
$list = [
    'uuid' => 'abcdef01-2345-6789-abcd-ef0123456789', 'enabled' => '1', 'kind' => 'domain',
    'urls' => "https://example.com/domains\nhttps://example.net/more", 'refresh' => '60',
];
$fetchCount = 0;
$fetch = static function (string $url) use (&$fetchCount): string {
    $fetchCount++;
    return str_contains($url, 'example.com') ? "Example.COM\ncdn.example.org\n" : "ads.example.net\nexample.com\n";
};
try {
    $first = xray_update_ip_list($list, $directory, $fetch, $domainParse, false, 200000);
    check_domain_list($first['kind'] === 'domain', 'Domain update reports its kind');
    check_domain_list($first['changed'] && $first['count'] === 3, 'Domain sources are unioned and deduplicated');
    $cache = $directory . '/XRAY_abcdef012345.json';
    check_domain_list(json_decode(file_get_contents($cache), true) === [
        'kind' => 'domain', 'entries' => ['ads.example.net', 'cdn.example.org', 'example.com'],
    ], 'Domain cache is a normalized typed object');
    touch($cache, 200000);
    $second = xray_update_ip_list($list, $directory, $fetch, $domainParse, false, 200060);
    check_domain_list($second['skipped'] && $fetchCount === 2, 'Fresh typed domain cache honors refresh interval');
    // A kind change must bypass freshness and overwrite the cache from the new
    // sources: the previously cached IP entries may never merge into domains.
    $asIp = $list;
    $asIp['kind'] = 'ip';
    unset($asIp['domains']);
    $ipFetch = static function (string $url): array { return ['8.8.8.8']; };
    $switched = xray_update_ip_list($asIp, $directory, $fetch, $ipFetch, false, 200060);
    check_domain_list(!$switched['skipped'] && $switched['kind'] === 'ip', 'Kind change bypasses the freshness shortcut');
    check_domain_list(json_decode(file_get_contents($cache), true) === [
        'kind' => 'ip', 'entries' => ['8.8.8.8'],
    ], 'Kind change overwrites the cache with the new kind');
    check_domain_list($fetchCount === 4, 'Kind change re-downloads from the configured sources');
    // Back to domain: the stale IP cache must be replaced, not merged.
    $back = xray_update_ip_list($list, $directory, $fetch, $domainParse, false, 200060);
    check_domain_list(!$back['skipped'] && $back['count'] === 3, 'Switching back to domain bypasses freshness again');
    check_domain_list(json_decode(file_get_contents($cache), true)['kind'] === 'domain', 'Cache kind follows the model');
    // Failed downloads keep the last good typed cache.
    $before = file_get_contents($cache);
    $failing = static function (string $url): string { throw new RuntimeException('offline'); };
    try {
        xray_update_ip_list($list, $directory, $failing, $domainParse, true, 200120);
        throw new RuntimeException('Accepted failed download');
    } catch (RuntimeException $expected) {
        check_domain_list(file_get_contents($cache) === $before, 'Failed download preserves the typed domain cache');
    }
    // Removing URLs converges to an empty typed cache without downloads.
    $noUrls = $list;
    $noUrls['urls'] = '';
    $cleared = xray_update_ip_list($noUrls, $directory, $fetch, $domainParse, false, 200180);
    check_domain_list($cleared['changed'] && $cleared['count'] === 0, 'Removed URLs empty the domain cache');
    check_domain_list(json_decode(file_get_contents($cache), true) === ['kind' => 'domain', 'entries' => []],
        'Domain cache converges to a typed empty object');
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) @unlink($path);
    @rmdir($directory);
}

// Route building: inline domains and the typed domain cache merge into
// custom_domains; static_ips stays empty so only DNS learning fills the alias.
$routeDir = sys_get_temp_dir() . '/xray-domain-routes-' . bin2hex(random_bytes(4));
mkdir($routeDir);
try {
    file_put_contents($routeDir . '/XRAY_abcdef012345.json', json_encode(
        ['kind' => 'domain', 'entries' => ['cdn.example.org', 'example.com']]) . "\n");
    $routes = xray_firewall_routes_from([], [[
        'uuid' => 'abcdef01-2345-6789-abcd-ef0123456789', 'enabled' => '1', 'kind' => 'domain',
        'domains' => "Ads.Example.NET\n*.Example.COM", 'networks' => '192.0.2.0/24', 'ports' => '1234',
    ]], '/assets', $routeDir);
    $route = $routes['routes'][1];
    check_domain_list($route['static_ips'] === [], 'Domain routes never carry static IPs');
    check_domain_list($route['custom_domains'] === "ads.example.net\ncdn.example.org\nexample.com",
        'Inline domains and the typed domain cache merge into custom_domains');
    check_domain_list($route['table'] === 'XRAY_abcdef012345', 'Domain list keeps its stable alias');
    // IP entries keep merging legacy and typed IP caches as before.
    file_put_contents($routeDir . '/XRAY_abcdef012345.json', json_encode(['kind' => 'ip', 'entries' => ['1.1.1.0/24']]) . "\n");
    $ipRoutes = xray_firewall_routes_from([], [[
        'uuid' => 'abcdef01-2345-6789-abcd-ef0123456789', 'enabled' => '1', 'kind' => 'ip', 'networks' => '8.8.8.8',
    ]], '/assets', $routeDir);
    check_domain_list($ipRoutes['routes'][1]['static_ips'] === ['1.1.1.0/24', '8.8.8.8'],
        'Typed IP cache still merges into static_ips');
    // Legacy plain arrays remain valid IP caches for backwards compatibility.
    file_put_contents($routeDir . '/XRAY_abcdef012345.json', "[\"1.1.1.0/24\"]\n");
    $legacyRoutes = xray_firewall_routes_from([], [[
        'uuid' => 'abcdef01-2345-6789-abcd-ef0123456789', 'enabled' => '1', 'networks' => '8.8.8.8',
    ]], '/assets', $routeDir);
    check_domain_list($legacyRoutes['routes'][1]['static_ips'] === ['1.1.1.0/24', '8.8.8.8'],
        'Legacy plain JSON arrays are still accepted for IP lists');
    // Kind mismatches must never merge; reading fails closed instead.
    file_put_contents($routeDir . '/XRAY_abcdef012345.json', json_encode(['kind' => 'domain', 'entries' => ['example.com']]) . "\n");
    try {
        xray_firewall_routes_from([], [[
            'uuid' => 'abcdef01-2345-6789-abcd-ef0123456789', 'enabled' => '1', 'kind' => 'ip', 'networks' => '8.8.8.8',
        ]], '/assets', $routeDir);
        throw new RuntimeException('Merged a domain cache into an IP route');
    } catch (RuntimeException $expected) {
        check_domain_list(str_contains($expected->getMessage(), 'kind'), 'Domain cache is rejected for IP routes');
    }
    file_put_contents($routeDir . '/XRAY_abcdef012345.json', json_encode(['kind' => 'ip', 'entries' => ['1.1.1.0/24']]) . "\n");
    try {
        xray_firewall_routes_from([], [[
            'uuid' => 'abcdef01-2345-6789-abcd-ef0123456789', 'enabled' => '1', 'kind' => 'domain',
            'domains' => 'example.org',
        ]], '/assets', $routeDir);
        throw new RuntimeException('Merged an IP cache into a domain route');
    } catch (RuntimeException $expected) {
        check_domain_list(str_contains($expected->getMessage(), 'kind'), 'Mismatched domain cache fails closed');
    }
    file_put_contents($routeDir . '/XRAY_abcdef012345.json', "not-json\n");
    try {
        xray_firewall_routes_from([], [[
            'uuid' => 'abcdef01-2345-6789-abcd-ef0123456789', 'enabled' => '1', 'kind' => 'ip', 'networks' => '8.8.8.8',
        ]], '/assets', $routeDir);
        throw new RuntimeException('Accepted a corrupt cache');
    } catch (RuntimeException $expected) {
    }
    // Inline domain validation rejects networks, IPs and malformed labels.
    foreach (['192.0.2.0/24', '192.0.2.1', '2001:db8::1', 'onlylabel', 'bad..example.com', 'bad_domain.example.com'] as $bad) {
        try {
            xray_static_domains($bad);
            throw new RuntimeException('Accepted invalid inline domain');
        } catch (InvalidArgumentException $expected) {
        }
    }
    check_domain_list(xray_static_domains("*.Example.COM\n.ADS.example.net") === ['example.com', 'ads.example.net'],
        'Inline domains are normalized like downloaded ones');
    // Deleting a domain list must only clean up plugin-owned helper rules:
    // ownership is alias-only — the marker carries the stable generated alias,
    // never the mutable list name — so renaming the list cannot orphan cleanup.
    require_once __DIR__ . '/../plugin/mvc/app/models/OPNsense/Xray/RoutingListRule.php';
    $domainMarker = \OPNsense\Xray\RoutingListRule::description('domain', 'Voice', 'XRAY_abcdef012345');
    check_domain_list($domainMarker === 'Xray domain list [XRAY_abcdef012345]',
        'Domain helper rule has an exact alias-only ownership marker');
    check_domain_list(!str_contains($domainMarker, 'Voice'),
        'Domain ownership marker never embeds the mutable list name');
    check_domain_list(\OPNsense\Xray\RoutingListRule::description('domain', 'Renamed', 'XRAY_abcdef012345') === $domainMarker,
        'Renaming the list does not change the ownership marker');
    check_domain_list(\OPNsense\Xray\RoutingListRule::description('ip', 'Voice', 'XRAY_abcdef012345') === 'Xray IP list: Voice',
        'IP helper rule description is backward-compatible');
    $longName = str_repeat('a', 300);
    check_domain_list(\OPNsense\Xray\RoutingListRule::ownedByDomainList(
        'XRAY_abcdef012345', \OPNsense\Xray\RoutingListRule::description('domain', $longName, 'XRAY_abcdef012345'),
        'XRAY_abcdef012345'),
        'Ownership survives long list names');
    check_domain_list(\OPNsense\Xray\RoutingListRule::ownedByDomainList(
        'XRAY_abcdef012345', 'Xray domain list [XRAY_abcdef012345]', 'XRAY_abcdef012345'),
        'A generated helper rule is recognized as owned');
    check_domain_list(!\OPNsense\Xray\RoutingListRule::ownedByDomainList('XRAY_abcdef012345', 'My custom Voice rule', 'XRAY_abcdef012345'),
        'A user rule on the same alias with a custom description is never owned');
    check_domain_list(!\OPNsense\Xray\RoutingListRule::ownedByDomainList(
        'XRAY_abcdef012345', 'Xray domain list [XRAY_ffffffffffff]', 'XRAY_abcdef012345'),
        'A helper rule generated for another list is never owned');
    check_domain_list(!\OPNsense\Xray\RoutingListRule::ownedByDomainList(
        'XRAY_ffffffffffff', 'Xray domain list [XRAY_abcdef012345]', 'XRAY_abcdef012345'),
        'A matching description on another alias is never owned');
    check_domain_list(!\OPNsense\Xray\RoutingListRule::ownedByDomainList('XRAY_abcdef012345', 'Xray IP list: Voice', 'XRAY_abcdef012345'),
        'IP helper rules are not owned by domain-list cleanup');
    // An oversized cache of the matching kind reports its own error, distinct
    // from the kind-mismatch failure.
    file_put_contents($routeDir . '/XRAY_abcdef012345.json', json_encode(['kind' => 'ip',
        'entries' => array_fill(0, 65537, '1.1.1.0/24')]) . "\n");
    try {
        xray_read_list_cache($routeDir . '/XRAY_abcdef012345.json', 'ip');
        throw new RuntimeException('Accepted an oversized cache');
    } catch (RuntimeException $expected) {
        check_domain_list(str_contains($expected->getMessage(), 'exceeds')
            && !str_contains($expected->getMessage(), 'kind mismatch'),
            'Oversized cache error is distinct from kind mismatch');
    }
    // The cache pruner removes typed caches of deleted lists of either kind.
    file_put_contents($routeDir . '/XRAY_abcdef012345.json', json_encode(['kind' => 'domain', 'entries' => ['example.com']]) . "\n");
    file_put_contents($routeDir . '/XRAY_999999999999.json', json_encode(['kind' => 'ip', 'entries' => ['8.8.8.8']]) . "\n");
    $prunedDeleted = xray_prune_ip_list_cache($routeDir, ['XRAY_bbbbbbbbbbbb']);
    check_domain_list($prunedDeleted === ['XRAY_999999999999', 'XRAY_abcdef012345'],
        'Typed caches of deleted lists are pruned for both kinds');
} finally {
    foreach (glob($routeDir . '/*') ?: [] as $path) @unlink($path);
    @rmdir($routeDir);
}

echo "Domain list regression checks passed\n";
