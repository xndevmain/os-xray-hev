#!/usr/local/bin/php
<?php
/** Probe each configured proxy in isolation; do not change running instances. */
define('XRAY_LIBRARY_ONLY', true);
require_once __DIR__ . '/xray-service-control.php';

$process = null;
$temp = '';
$reservations = [];
$result = ['result' => 'failed', 'message' => 'Probe did not complete'];
try {
    $uuid = $argv[1] ?? '';
    if (!preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/', $uuid)) {
        throw new RuntimeException('Select an instance to test');
    }
    $c = xray_get_config($uuid);
    if (!$c) {
        throw new RuntimeException('Instance not found');
    }
    $proxies = xray_outbounds($c['outbound_config']);
    $inbounds = [];
    $rules = [];
    $ports = [];
    foreach ($proxies as $index => $proxy) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) {
            throw new RuntimeException('Cannot allocate probe port');
        }
        $reservations[] = $socket;
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        $ports[] = $port;
        $tag = 'probe-' . $index;
        $inbounds[] = ['tag' => $tag, 'listen' => '127.0.0.1', 'port' => $port, 'protocol' => 'socks', 'settings' => ['auth' => 'noauth']];
        $rules[] = ['type' => 'field', 'inboundTag' => [$tag], 'outboundTag' => $proxy['tag']];
    }
    $config = ['log' => ['loglevel' => 'none'], 'inbounds' => $inbounds, 'outbounds' => $proxies, 'routing' => ['rules' => $rules]];
    $tempBase = tempnam('/tmp', 'xray-probe-');
    if ($tempBase === false) {
        throw new RuntimeException('Cannot create probe config');
    }
    $temp = $tempBase . '.json';
    if (!rename($tempBase, $temp)) {
        unlink($tempBase);
        throw new RuntimeException('Cannot name probe JSON config');
    }
    chmod($temp, 0600);
    file_put_contents($temp, json_encode($config, JSON_THROW_ON_ERROR));
    foreach ($reservations as $socket) {
        fclose($socket);
    }
    $reservations = [];
    $process = proc_open([XRAY_BIN, 'run', '-format', 'json', '-c', $temp], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start probe process');
    }
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            throw new RuntimeException('Probe process exited: check outbound configuration');
        }
        $socket = @stream_socket_client('tcp://127.0.0.1:' . end($ports), $errno, $error, 0.05);
        if ($socket) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        throw new RuntimeException('Probe process did not become ready');
    }
    $target = $c['probe_url'];
    if (!filter_var($target, FILTER_VALIDATE_URL) || parse_url($target, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('Health check URL must use HTTPS');
    }
    $checks = [];
    foreach ($proxies as $index => $proxy) {
        $output = [];
        exec('/usr/local/bin/curl --silent --output /dev/null --noproxy ' . escapeshellarg('')
            . ' --proxy ' . escapeshellarg('socks5h://127.0.0.1:' . $ports[$index])
            . ' --proto =https --connect-timeout 3 --max-time 5 --write-out ' . escapeshellarg('%{http_code} %{time_total}')
            . ' ' . escapeshellarg($target) . ' 2>/dev/null', $output, $rc);
        $parts = explode(' ', implode('', $output));
        $code = (int)($parts[0] ?? 0);
        $ok = $rc === 0 && $code >= 200 && $code < 400;
        $checks[] = ['tag' => $proxy['tag'], 'result' => $ok ? 'ok' : 'failed', 'http_code' => $code,
            'latency_ms' => (int)round((float)($parts[1] ?? 0) * 1000), 'curl_code' => $rc];
    }
    $alive = count(array_filter($checks, static fn($check) => $check['result'] === 'ok'));
    $result = ['result' => $alive > 0 ? 'ok' : 'failed', 'proxies' => $checks,
        'message' => $alive . '/' . count($checks) . ' proxies healthy', 'checked_at' => gmdate('c')];
} catch (Throwable $e) {
    $result = ['result' => 'failed', 'message' => $e->getMessage()];
} finally {
    foreach ($reservations as $socket) {
        fclose($socket);
    }
    if (is_resource($process)) {
        proc_terminate($process);
        for ($i = 0; $i < 20 && proc_get_status($process)['running']; $i++) {
            usleep(50000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }
    if ($temp && is_file($temp)) {
        unlink($temp);
    }
}
echo json_encode($result) . "\n";
