<?php
require __DIR__ . '/../plugin/scripts/Xray/xray-config.php';
require __DIR__ . '/../plugin/scripts/Xray/xray-ip-lists.php';

function check_ip_list(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

check_ip_list(xray_list_urls("https://example.com/a\nhttps://example.net/b\n") === [
    'https://example.com/a', 'https://example.net/b'], 'Multiple HTTPS list URLs');
foreach (["http://example.com/a", "https://user:pass@example.com/a", "https://example.com/a b"] as $bad) {
    try {
        xray_list_urls($bad);
        throw new RuntimeException('Accepted invalid list URL');
    } catch (InvalidArgumentException $expected) {
    }
}
check_ip_list(xray_list_ports("19294:19344\n50000:50032") === ['19294:19344', '50000:50032'], 'Port ranges');
try {
    xray_list_ports('0:70000');
    throw new RuntimeException('Accepted invalid port range');
} catch (InvalidArgumentException $expected) {
}

$directory = sys_get_temp_dir() . '/xray-ip-lists-' . bin2hex(random_bytes(4));
mkdir($directory);
$list = [
    'uuid' => '01234567-89ab-cdef-0123-456789abcdef', 'enabled' => '1',
    'urls' => "https://example.com/a\nhttps://example.net/b", 'refresh' => '60',
];
$fetchCount = 0;
$fetch = static function (string $url) use (&$fetchCount): string {
    $fetchCount++;
    return str_contains($url, 'example.com') ? "8.8.8.8\n" : "1.1.1.0/24\n8.8.8.8\n";
};
$parse = static function (string $raw): array {
    return array_values(array_filter(array_map('trim', explode("\n", $raw))));
};
try {
    $first = xray_update_ip_list($list, $directory, $fetch, $parse, false, 100000);
    check_ip_list($first['changed'] && $first['count'] === 2, 'Multiple sources are downloaded and unioned');
    $cache = $directory . '/XRAY_0123456789ab.json';
    check_ip_list(json_decode(file_get_contents($cache), true) === ['kind' => 'ip', 'entries' => ['1.1.1.0/24', '8.8.8.8']],
        'Normalized cache is a deterministic typed object');
    touch($cache, 100000);
    $second = xray_update_ip_list($list, $directory, $fetch, $parse, false, 100060);
    check_ip_list($second['skipped'] && $fetchCount === 2, 'Fresh cache honors refresh interval');
    $third = xray_update_ip_list($list, $directory, $fetch, $parse, true, 100060);
    check_ip_list(!$third['skipped'] && $fetchCount === 4, 'Force bypasses refresh interval');
    $before = file_get_contents($cache);
    $failing = static function (string $url): string { throw new RuntimeException('offline'); };
    try {
        xray_update_ip_list($list, $directory, $failing, $parse, true, 100120);
        throw new RuntimeException('Accepted failed download');
    } catch (RuntimeException $expected) {
        check_ip_list(file_get_contents($cache) === $before, 'Failed download preserves previous cache');
    }
    // Removing all URLs must converge the downloaded cache to empty (even when
    // the stale cache is still inside the refresh interval) so routes stop
    // merging outdated networks; no fetch may happen.
    $noUrls = $list;
    $noUrls['urls'] = '';
    $cleared = xray_update_ip_list($noUrls, $directory, $fetch, $parse, false, 100180);
    check_ip_list($cleared['changed'] && $cleared['count'] === 0, 'Removed URLs empty the downloaded cache and report the change');
    check_ip_list(json_decode(file_get_contents($cache), true) === ['kind' => 'ip', 'entries' => []],
        'Downloaded cache converges atomically to a typed empty object');
    check_ip_list($fetchCount === 4, 'Converging an empty list performs no downloads');
    $again = xray_update_ip_list($noUrls, $directory, $fetch, $parse, false, 100200);
    check_ip_list(!$again['changed'], 'Converged empty cache is idempotent');
    // Inline networks stay in the model and must survive without the cache.
    $routes = xray_firewall_routes_from([], [[
        'uuid' => $list['uuid'], 'enabled' => '1', 'networks' => "192.0.2.0/24",
    ]], '/assets', $directory);
    check_ip_list($routes['routes'][1]['static_ips'] === ['192.0.2.0/24'], 'Inline networks remain without downloaded cache');
    // Legacy plain-array caches written by older plugin versions still count
    // as fresh IP caches and converge to the typed format on the next refresh.
    file_put_contents($cache, "[\"1.1.1.0/24\",\"8.8.8.8\"]\n");
    $legacy = xray_update_ip_list($list, $directory, $fetch, $parse, false, 100220);
    check_ip_list($legacy['skipped'] && $legacy['kind'] === 'ip' && $legacy['count'] === 2,
        'Legacy plain cache is treated as a fresh IP cache');
    try {
        xray_update_ip_list(['uuid' => $list['uuid'], 'kind' => 'bogus', 'urls' => 'https://example.com/a'], $directory, $fetch, $parse, false, 100240);
        throw new RuntimeException('Accepted an invalid routing list kind');
    } catch (InvalidArgumentException $expected) {
    }
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) @unlink($path);
    @rmdir($directory);
}

$pruneDir = sys_get_temp_dir() . '/xray-ip-lists-prune-' . bin2hex(random_bytes(4));
mkdir($pruneDir);
try {
    file_put_contents($pruneDir . '/XRAY_0123456789ab.json', "[]\n");
    file_put_contents($pruneDir . '/XRAY_aaaaaaaaaaaa.json', "[]\n");
    file_put_contents($pruneDir . '/XRAY_BBBBBBBBBBBB.json', "[]\n");
    file_put_contents($pruneDir . '/XRAY_short.json', "[]\n");
    file_put_contents($pruneDir . '/unrelated.txt', "keep\n");
    $pruned = xray_prune_ip_list_cache($pruneDir, ['XRAY_aaaaaaaaaaaa']);
    check_ip_list($pruned === ['XRAY_0123456789ab'], 'Only cache files for aliases absent from the model are removed');
    check_ip_list(!file_exists($pruneDir . '/XRAY_0123456789ab.json'), 'Orphaned cache file is deleted');
    check_ip_list(is_file($pruneDir . '/XRAY_aaaaaaaaaaaa.json'), 'Model alias cache is kept');
    check_ip_list(is_file($pruneDir . '/XRAY_BBBBBBBBBBBB.json'), 'Non-canonical cache name is left untouched');
    check_ip_list(is_file($pruneDir . '/XRAY_short.json'), 'Malformed cache name is left untouched');
    check_ip_list(is_file($pruneDir . '/unrelated.txt'), 'Non-cache files are never removed');
} finally {
    foreach (glob($pruneDir . '/*') ?: [] as $path) @unlink($path);
    @rmdir($pruneDir);
}

$sigDir = sys_get_temp_dir() . '/xray-ip-lists-sig-' . bin2hex(random_bytes(4));
mkdir($sigDir);
try {
    $sigPath = $sigDir . '/.apply-signature';
    check_ip_list(xray_read_apply_signature($sigPath) === null, 'Missing signature reads as null');
    $routesA = ['asset_dir' => '/assets', 'routes' => [['table' => 'XRAY_HOSTS', 'static_ips' => ['192.0.2.0/24']]]];
    $routesB = ['asset_dir' => '/assets', 'routes' => [['table' => 'XRAY_HOSTS', 'static_ips' => ['192.0.2.0/24', '198.51.100.0/24']]]];
    check_ip_list(xray_routing_apply_signature($routesA) === xray_routing_apply_signature($routesA), 'Signature is deterministic');
    check_ip_list(xray_routing_apply_signature($routesA) !== xray_routing_apply_signature($routesB), 'Inline network edits change the signature');
    check_ip_list(xray_routing_apply_signature($routesA) !== xray_routing_apply_signature(array_reverse($routesA)), 'Route order changes the signature');
    xray_write_apply_signature($sigPath, xray_routing_apply_signature($routesA));
    check_ip_list(xray_read_apply_signature($sigPath) === xray_routing_apply_signature($routesA), 'Signature persists across writes');
    check_ip_list(!file_exists($sigPath . '.new'), 'Signature is written without leftover staging files');
} finally {
    foreach (glob($sigDir . '/.apply-signature*') ?: [] as $path) @unlink($path);
    @rmdir($sigDir);
}

// An oversized cache of the matching kind must report its own error instead
// of being misreported as a kind mismatch.
$oversizedDir = sys_get_temp_dir() . '/xray-ip-lists-oversized-' . bin2hex(random_bytes(4));
mkdir($oversizedDir);
try {
    file_put_contents($oversizedDir . '/XRAY_0123456789ab.json', json_encode(
        ['kind' => 'ip', 'entries' => array_fill(0, 65537, '1.1.1.0/24')]) . "\n");
    try {
        xray_read_list_cache($oversizedDir . '/XRAY_0123456789ab.json', 'ip');
        throw new RuntimeException('Accepted an oversized cache');
    } catch (RuntimeException $expected) {
        check_ip_list(str_contains($expected->getMessage(), 'exceeds')
            && !str_contains($expected->getMessage(), 'kind mismatch'),
            'Oversized cache error is distinct from a kind mismatch');
    }
} finally {
    @unlink($oversizedDir . '/XRAY_0123456789ab.json');
    @rmdir($oversizedDir);
}

echo "IP list regression checks passed\n";
