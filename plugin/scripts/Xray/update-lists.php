#!/usr/local/bin/php
<?php
require_once 'config.inc';
if ((string)(OPNsense\Core\Config::getInstance()->object()->OPNsense->xray->general->geodata_auto_update ?? '0') !== '1') exit;
$lock = fopen('/var/run/xray-auto-update.lock', 'ce');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
try {
    foreach (['xray-geodata.php', 'xray-routing-control.php apply'] as $step) {
        $command = '/usr/local/bin/php ' . __DIR__ . '/' . $step;
        $output = [];
        exec($command . ' 2>&1', $output, $rc);
        $reply = json_decode(implode("\n", $output), true);
        if ($rc !== 0 || ($reply['result'] ?? '') !== 'ok') {
            throw new RuntimeException($reply['message'] ?? 'Update step failed: ' . $step);
        }
    }
    syslog(LOG_NOTICE, 'Xray community lists updated and applied');
} catch (Throwable $error) {
    syslog(LOG_ERR, 'Xray list update failed: ' . $error->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
