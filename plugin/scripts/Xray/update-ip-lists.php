#!/usr/local/bin/php
<?php
require_once 'config.inc';
require_once __DIR__ . '/xray-config.php';
require_once __DIR__ . '/xray-ip-lists.php';
require_once __DIR__ . '/../../mvc/app/models/OPNsense/Xray/HttpsDownload.php';

use OPNsense\Xray\HttpsDownload;

$force = ($argv[1] ?? '') === 'force';
$lock = fopen('/var/run/xray-ip-lists.lock', 'ce');
$errors = [];
$updated = [];
$exitCode = 0;
try {
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Routing-list update is busy');
    $fetch = static function (string $url): string {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Cannot allocate download buffer');
        try {
            HttpsDownload::fetch($url, $stream, 8 * 1024 * 1024, 30);
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    };
    $parse = static function (string $raw): array {
        $path = tempnam('/tmp', 'xray-ip-list-');
        if ($path === false) throw new RuntimeException('Cannot stage routing list');
        try {
            file_put_contents($path, $raw);
            chmod($path, 0600);
            $output = [];
            exec('/usr/local/bin/python3 ' . escapeshellarg(__DIR__ . '/parse-ip-list.py')
                . ' ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
            if ($rc !== 0) throw new RuntimeException(implode("\n", $output) ?: 'Routing-list validation failed');
            $parsed = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($parsed)) throw new RuntimeException('Routing-list parser returned invalid data');
            return $parsed;
        } finally {
            @unlink($path);
        }
    };
    $cacheDir = '/var/db/xray-routing-lists';
    $model = new OPNsense\Xray\RoutingList();
    $knownAliases = [];
    foreach ($model->list->iterateItems() as $uuid => $node) {
        $knownAliases[] = xray_route_alias($uuid);
        if ((string)$node->enabled !== '1') continue;
        $item = [
            'uuid' => $uuid, 'name' => (string)$node->name, 'urls' => (string)$node->urls,
            'refresh' => (string)$node->refresh, 'enabled' => '1',
        ];
        try {
            $result = xray_update_ip_list($item, $cacheDir, $fetch, $parse, $force);
            $updated[] = ['name' => $item['name'], 'alias' => $result['alias'],
                'count' => $result['count'], 'skipped' => $result['skipped']];
        } catch (Throwable $error) {
            $errors[] = $item['name'] . ': ' . $error->getMessage();
        }
    }
    xray_prune_ip_list_cache($cacheDir, $knownAliases);
    // Apply whenever the generated routing configuration changes, not only
    // when downloaded content changed: inline network edits and lists without
    // URLs would otherwise stay stale until a forced run.
    $signaturePath = $cacheDir . '/.apply-signature';
    $assetDir = is_dir('/usr/local/share/xray/current') ? '/usr/local/share/xray/current' : '/usr/local/share/xray';
    $signature = xray_routing_apply_signature(xray_firewall_routes($assetDir));
    if ($force || $signature !== xray_read_apply_signature($signaturePath)) {
        $output = [];
        exec('/usr/local/bin/php ' . escapeshellarg(__DIR__ . '/xray-routing-control.php') . ' apply 2>&1', $output, $rc);
        $reply = json_decode(implode("\n", $output), true);
        if ($rc !== 0 || ($reply['result'] ?? '') !== 'ok') {
            throw new RuntimeException($reply['message'] ?? 'Firewall-list apply failed');
        }
        xray_write_apply_signature($signaturePath, $signature);
    }
    if ($errors) {
        $exitCode = 1;
        echo json_encode(['result' => 'failed', 'message' => implode('; ', $errors), 'lists' => $updated]) . "\n";
    } else {
        echo json_encode(['result' => 'ok', 'message' => 'Downloaded IP lists updated and applied.', 'lists' => $updated]) . "\n";
    }
} catch (Throwable $error) {
    $exitCode = 1;
    echo json_encode(['result' => 'failed', 'message' => $error->getMessage(), 'lists' => $updated]) . "\n";
} finally {
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}
exit($exitCode);
