#!/usr/local/bin/php
<?php
define('XRAY_LIBRARY_ONLY', true);
require_once __DIR__ . '/xray-service-control.php';

$root = '/usr/local/share/xray';
$stage = '';
$link = '';
$lock = fopen('/var/run/xray-geodata.lock', 'ce');
$guard = fopen('/var/run/xray-geodata-commit.lock', 'ce');
$pending = '/var/run/xray-geodata.pending';
if (($argv[1] ?? '') === 'cancel') {
    flock($guard, LOCK_EX);
    $cancelled = is_file($pending) && unlink($pending);
    echo json_encode(['result' => $cancelled ? 'ok' : 'idle', 'message' => $cancelled ? 'Cancellation requested. Waiting for the current step.' : 'No active import.']);
    exit;
}
$ownsImport = false;
$exitCode = 0;
try {
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another geodata import is running');
    }
    $ownsImport = true;
    if (!touch($pending)) throw new RuntimeException('Cannot initialize cancellation state');
    if (!is_executable(XRAY_BIN)) {
        throw new RuntimeException('Install xray-core before importing geodata');
    }
    if (!is_dir($root) && !mkdir($root, 0755, true)) {
        throw new RuntimeException('Cannot create asset directory');
    }
    $id = bin2hex(random_bytes(8));
    $stage = $root . '/release-' . $id;
    if (!mkdir($stage, 0755)) {
        throw new RuntimeException('Cannot create staging directory');
    }
    $cfg = OPNsense\Core\Config::getInstance()->object();
    $general = $cfg->OPNsense->xray->general;
    $sources = [
        'geosite' => (string)($general->geosite_url ?? '') ?: 'https://github.com/v2fly/domain-list-community/releases/latest/download/dlc.dat',
        'geoip' => (string)($general->geoip_url ?? '') ?: 'https://github.com/v2fly/geoip/releases/latest/download/geoip.dat',
    ];
    foreach ($sources as $name => $url) {
        clearstatcache(true, $pending);
        if (!is_file($pending)) throw new RuntimeException('Import cancelled.');
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null) {
            throw new RuntimeException('Geodata sources must be HTTPS URLs without credentials');
        }
        $file = $stage . '/' . $name . '.dat';
        $download = fopen($file, 'x+b');
        if (!$download) throw new RuntimeException('Cannot create download file');
        try { \OPNsense\Xray\HttpsDownload::fetch($url, $download, 67108864, 60); }
        finally { fclose($download); }
        chmod($file, 0644);
    }
    $validationOutput = [];
    exec('/usr/local/bin/python3 ' . escapeshellarg(__DIR__ . '/validate-geodata.py')
        . ' ' . escapeshellarg($stage) . ' 2>&1', $validationOutput, $validationRc);
    if ($validationRc !== 0) throw new RuntimeException('Invalid geodata database');
    // Validate the runtime independently of whether endpoints have been configured.
    putenv('XRAY_LOCATION_ASSET=' . $stage);
    $baseline = [
        'outbound_config' => '{"protocol":"socks","settings":{"servers":[{"address":"127.0.0.1","port":9}]}}',
    ];
    $configs = [$baseline];
    $check = $stage . '/validate.json';
    foreach ($configs as $instance) {
        file_put_contents($check, json_encode(xray_build_config($instance), JSON_THROW_ON_ERROR));
        chmod($check, 0600);
        $output = [];
        exec(escapeshellarg(XRAY_BIN) . ' run -test -c ' . escapeshellarg($check) . ' 2>&1', $output, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('Geodata validation failed: ' . implode(' ', $output));
        }
    }
    unlink($check);
    $routingCheck = $stage . '/routing.json';
    $routing = xray_firewall_routes($stage);
    file_put_contents($routingCheck, json_encode($routing, JSON_THROW_ON_ERROR));
    $routingOutput = [];
    exec('/usr/local/bin/python3 ' . escapeshellarg(__DIR__ . '/xray-routing.py')
        . ' --check --config ' . escapeshellarg($routingCheck) . ' 2>&1', $routingOutput, $routingRc);
    if ($routingRc !== 0) throw new RuntimeException('Firewall list validation failed: ' . implode(' ', $routingOutput));
    unlink($routingCheck);
    flock($guard, LOCK_EX);
    clearstatcache(true, $pending);
    if (!is_file($pending)) throw new RuntimeException('Import cancelled.');
    $link = $root . '/.current-' . $id;
    if (!symlink(basename($stage), $link) || !rename($link, $root . '/current')) {
        throw new RuntimeException('Cannot activate geodata');
    }
    $stage = ''; // The release is now owned by current; retain previous release for rollback.
    $routingLock = fopen('/var/run/xray-routing-control.lock', 'ce');
    if ($routingLock && flock($routingLock, LOCK_EX | LOCK_NB)) {
        try {
            require_once __DIR__ . '/geodata-releases.php';
            xray_prune_releases($root, '/usr/local/etc/xray-routing.json');
        } finally { flock($routingLock, LOCK_UN); }
    }
    if ($routingLock) fclose($routingLock);
    echo json_encode(['result' => 'ok', 'message' => 'Geosite and GeoIP imported. Use Apply Firewall Lists to activate selected categories.']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'failed', 'message' => $e->getMessage()]) . "\n";
    $exitCode = 1;
} finally {
    if ($link !== '' && is_link($link)) {
        unlink($link);
    }
    if ($stage !== '') {
        foreach (['geosite.dat', 'geoip.dat', 'validate.json', 'routing.json'] as $file) {
            if (is_file($stage . '/' . $file)) {
                unlink($stage . '/' . $file);
            }
        }
        if (is_dir($stage)) {
            rmdir($stage);
        }
    }
    if ($ownsImport) @unlink($pending);
    if ($guard) { flock($guard, LOCK_UN); fclose($guard); }
    if ($lock) {
        flock($lock, LOCK_UN); fclose($lock);
    }
}
exit($exitCode);
