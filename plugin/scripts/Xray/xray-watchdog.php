#!/usr/local/bin/php
<?php
/**
 * xray-watchdog.php — E1: watchdog для всех инстансов xray-core + hev-socks5-tunnel.
 *
 * v3.0.0: итерирует все инстансы из config.xml; для каждого инстанса:
 *   1. Проверяет watchdog_enabled (общий флаг) и xray_stopped_<uuid>.flag (намеренная остановка).
 *   2. Проверяет PID-файлы инстанса.
 *   3. Если xray-core ИЛИ hev-socks5-tunnel упал — вызывает restart <inst_uuid>.
 *
 * Вызывается из cron каждую минуту через configd action [watchdog].
 */

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');
define('XRAY_LIBRARY_ONLY', true);
require_once __DIR__ . '/xray-service-control.php';

define('XRAY_CTRL',    '/usr/local/opnsense/scripts/Xray/xray-service-control.php');
define('WATCHDOG_LOG', '/var/log/xray-watchdog.log');

function wlog(string $msg): void
{
    $ts   = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents(WATCHDOG_LOG, $line, FILE_APPEND | LOCK_EX);
}

function proc_alive(string $pidfile): bool
{
    return proc_is_running($pidfile);
}

// ─── Читаем конфиг ────────────────────────────────────────────────────────────
$cfg     = OPNsense\Core\Config::getInstance()->object();
$general = $cfg->OPNsense->xray->general ?? null;

$enabled         = (string)($general->enabled          ?? '0') === '1';
$watchdogEnabled = (string)($general->watchdog_enabled ?? '0') === '1';

if (!$enabled) {
    exit(0);
}

if (!$watchdogEnabled) {
    exit(0);
}

if (is_file('/usr/local/etc/xray-routing.json') && !proc_alive('/var/run/xray-routing.pid')) {
    $routingOutput = [];
    exec('/usr/local/bin/php ' . escapeshellarg(__DIR__ . '/xray-routing-control.php') . ' start 2>&1', $routingOutput, $routingRc);
    wlog('DNS routing broker recovery: ' . implode(' ', $routingOutput));
}

$instances = $cfg->OPNsense->xray->instances ?? null;
if (!$instances) {
    exit(0);
}

if (!file_exists(XRAY_CTRL)) {
    wlog('ERROR: ' . XRAY_CTRL . ' not found — cannot restart');
    exit(1);
}

// ─── Итерируем все инстансы ───────────────────────────────────────────────────
$anyFailed = false;

foreach ($instances->instance as $inst) {
    $inst_uuid = (string)$inst['uuid'];
    if ($inst_uuid === '') {
        continue;
    }

    $name = (string)($inst->name ?? $inst_uuid);

    // БАГ-5 FIX: если инстанс намеренно остановлен — не трогаем
    $stoppedFlag = "/var/run/xray_stopped_{$inst_uuid}.flag";
    if (file_exists($stoppedFlag)) {
        continue;
    }

    $xrayPid = "/var/run/xray_core_{$inst_uuid}.pid";
    $hevPid  = "/var/run/hev-socks5-tunnel_{$inst_uuid}.pid";

    $xrayAlive = proc_alive($xrayPid);
    $hevAlive  = proc_alive($hevPid);

    // E1: watchdog must check xray-core PID (XRAY_PID) and hev-socks5-tunnel PID (HEV_PID)
    if ($xrayAlive && $hevAlive) {
        continue; // всё в порядке
    }

    $died = [];
    if (!$xrayAlive) $died[] = 'xray-core';
    if (!$hevAlive)  $died[] = 'hev-socks5-tunnel';

    wlog("WATCHDOG [{$name}]: " . implode(', ', $died) . " not running — triggering restart");

    exec('/usr/local/bin/php ' . escapeshellarg(XRAY_CTRL) . ' restart ' . escapeshellarg($inst_uuid) . ' 2>&1', $out, $rc);
    $output = trim(implode("\n", $out));

    if ($rc === 0) {
        wlog("WATCHDOG [{$name}]: restart OK — " . ($output ?: 'no output'));
    } else {
        wlog("WATCHDOG [{$name}]: restart FAILED (exit {$rc}) — {$output}");
        $anyFailed = true;
    }
}

exit($anyFailed ? 1 : 0);
