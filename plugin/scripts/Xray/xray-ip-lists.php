<?php
/** Downloaded IP routing-list helpers. Requires xray-config.php. */

function xray_list_urls(string $value): array
{
    $result = [];
    foreach (preg_split('/[\r\n]+/', $value) as $raw) {
        $url = trim($raw);
        if ($url === '') continue;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 8192
            || preg_match('/[\\s\\\\]/', $url)) {
            throw new InvalidArgumentException('Routing-list sources must be HTTPS URLs without credentials');
        }
        $result[] = $url;
    }
    if (count($result) > 16) throw new InvalidArgumentException('Maximum 16 URLs per routing list');
    return array_values(array_unique($result));
}

function xray_list_ports(string $value): array
{
    $result = [];
    foreach (preg_split('/[,\r\n]+/', $value) as $raw) {
        $range = trim($raw);
        if ($range === '') continue;
        $parts = explode(':', $range);
        if (count($parts) > 2) throw new InvalidArgumentException('Invalid port range: ' . $range);
        foreach ($parts as $port) {
            if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
                throw new InvalidArgumentException('Invalid port range: ' . $range);
            }
        }
        if (count($parts) === 2 && (int)$parts[0] > (int)$parts[1]) {
            throw new InvalidArgumentException('Invalid port range: ' . $range);
        }
        $result[] = $range;
    }
    return array_values(array_unique($result));
}

function xray_sort_networks(array $networks): array
{
    $networks = array_values(array_unique(array_map('strval', $networks)));
    usort($networks, static function (string $left, string $right): int {
        [$leftIp, $leftPrefix] = array_pad(explode('/', $left, 2), 2, '');
        [$rightIp, $rightPrefix] = array_pad(explode('/', $right, 2), 2, '');
        $leftPacked = inet_pton($leftIp);
        $rightPacked = inet_pton($rightIp);
        if ($leftPacked === false || $rightPacked === false) {
            throw new InvalidArgumentException('Invalid parsed routing-list network');
        }
        return strlen($leftPacked) <=> strlen($rightPacked)
            ?: strcmp($leftPacked, $rightPacked)
            ?: ((int)$leftPrefix <=> (int)$rightPrefix);
    });
    return $networks;
}

function xray_update_ip_list(
    array $list,
    string $cacheDir,
    callable $fetch,
    callable $parse,
    bool $force = false,
    ?int $now = null
): array {
    $alias = xray_route_alias((string)($list['uuid'] ?? ''));
    $urls = xray_list_urls((string)($list['urls'] ?? ''));
    $refresh = max(30, min(10080, (int)($list['refresh'] ?? 1440)));
    $now = $now ?? time();
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('Cannot create routing-list cache directory');
    }
    $cache = $cacheDir . '/' . $alias . '.json';
    if (!$urls) {
        // A configured list without URLs must not keep merging a stale
        // downloaded cache into firewall routes; converge it atomically to
        // empty. This runs before the freshness shortcut so a just-emptied
        // list cannot return early on its still-fresh stale cache.
        $payload = "[]\n";
        $previous = is_file($cache) ? file_get_contents($cache) : null;
        $changed = $previous !== null && $previous !== $payload;
        if ($changed) {
            $pending = $cache . '.new';
            if (file_put_contents($pending, $payload, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write routing-list cache');
            }
            chmod($pending, 0600);
            if (!rename($pending, $cache)) {
                @unlink($pending);
                throw new RuntimeException('Cannot activate routing-list cache');
            }
        }
        return ['alias' => $alias, 'changed' => $changed, 'skipped' => true, 'count' => 0];
    }
    if (!$force && is_file($cache) && $now - filemtime($cache) < $refresh * 60) {
        $existing = json_decode((string)file_get_contents($cache), true);
        return ['alias' => $alias, 'changed' => false, 'skipped' => true,
            'count' => is_array($existing) ? count($existing) : 0];
    }
    $networks = [];
    foreach ($urls as $url) {
        $parsed = $parse($fetch($url));
        if (!is_array($parsed)) throw new RuntimeException('Routing-list parser returned invalid data');
        $networks = array_merge($networks, $parsed);
        if (count($networks) > 65536) throw new RuntimeException('Routing list exceeds 65536 entries');
    }
    $networks = xray_sort_networks($networks);
    $payload = json_encode($networks, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $previous = is_file($cache) ? file_get_contents($cache) : null;
    $pending = $cache . '.new';
    if (file_put_contents($pending, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write routing-list cache');
    }
    chmod($pending, 0600);
    if (!rename($pending, $cache)) {
        @unlink($pending);
        throw new RuntimeException('Cannot activate routing-list cache');
    }
    return ['alias' => $alias, 'changed' => $previous !== $payload, 'skipped' => false, 'count' => count($networks)];
}

function xray_prune_ip_list_cache(string $cacheDir, array $keepAliases): array
{
    $keep = array_fill_keys($keepAliases, true);
    $removed = [];
    foreach (glob($cacheDir . '/XRAY_*.json') ?: [] as $path) {
        $name = basename($path, '.json');
        // Only canonical plugin-owned cache names may be removed; anything
        // else in the directory is left untouched.
        if (!preg_match('/^XRAY_[0-9a-f]{12}$/D', $name) || isset($keep[$name])) continue;
        if (@unlink($path)) $removed[] = $name;
    }
    sort($removed, SORT_STRING);
    return $removed;
}

function xray_routing_apply_signature(array $routes): string
{
    return hash('sha256', json_encode($routes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function xray_read_apply_signature(string $path): ?string
{
    if (!is_file($path)) return null;
    $value = trim((string)file_get_contents($path));
    return preg_match('/^[0-9a-f]{64}$/D', $value) ? $value : null;
}

function xray_write_apply_signature(string $path, string $signature): void
{
    $pending = $path . '.new';
    if (file_put_contents($pending, $signature . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write routing apply signature');
    }
    chmod($pending, 0600);
    if (!rename($pending, $path)) {
        @unlink($pending);
        throw new RuntimeException('Cannot activate routing apply signature');
    }
}
