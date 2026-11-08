<?php

function xray_enabled(): bool
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    return isset($cfg->OPNsense->xray->general->enabled)
        && (string)$cfg->OPNsense->xray->general->enabled === '1';
}

function xray_configure(): array
{
    return ['bootup' => ['xray_configure_do:10'], 'dns' => ['xray_restore_tun_address'], 'newwanip' => ['xray_restore_tun_address:2']];
}

/**
 * BUG-1 FIX: убрана хрупкая goto-логика управления lock-файлом.
 *
 * v3.0.0: итерирует все инстансы из config.xml, для каждого:
 *   - Захватывает per-instance lock (xray_start_<uuid>.lock)
 *   - Запускает xray-core + hev-socks5-tunnel если не запущены
 *   - Ждёт TUN-интерфейс до 5 секунд
 */
function xray_configure_do(bool $verbose = false): void
{
    if (!xray_enabled()) {
        return;
    }

    // Use the same generator/startup path as Apply: no stale JSON/YAML on boot.
    exec('/usr/local/opnsense/scripts/Xray/xray-service-control.php start 2>&1', $output, $rc);
    if ($verbose || $rc !== 0) {
        echo implode("\n", $output) . "\n";
    }
    exec('/usr/local/opnsense/scripts/Xray/xray-routing-control.php start 2>&1', $routingOutput, $routingRc);
    if ($verbose || $routingRc !== 0) echo implode("\n", $routingOutput) . "\n";
}

/**
 * v3.0.0: регистрирует один сервис на каждый инстанс.
 */
function xray_services()
{
    $services  = [];
    $cfg       = OPNsense\Core\Config::getInstance()->object();
    $instances = $cfg->OPNsense->xray->instances ?? null;

    if ($instances) {
        foreach ($instances->instance as $inst) {
            $inst_uuid = (string)$inst['uuid'];
            if ($inst_uuid === '' || !xray_enabled()) {
                continue;
            }
            $name       = (string)($inst->name ?? 'default');
            $services[] = [
                'description' => 'Xray (' . $name . ')',
                'configd'     => [
                    'restart' => ['xray restart ' . $inst_uuid],
                    'start'   => ['xray start '   . $inst_uuid],
                    'stop'    => ['xray stop '    . $inst_uuid],
                ],
                'name'    => 'xray',
                'pidfile' => "/var/run/xray_core_{$inst_uuid}.pid",
            ];
        }
    }

    return $services;
}

/**
 * E1: регистрирует cron-задание для watchdog.
 */
function xray_cron(): array
{
    return [
        ['autocron' => ['/usr/local/sbin/configctl xray watchdog', '*']],
        ['autocron' => ['/usr/local/opnsense/scripts/Xray/update-lists.php', '23', '4']],
        ['autocron' => ['/usr/local/opnsense/scripts/Xray/update-ip-lists.php', '*/30']],
        ['autocron' => ['/usr/local/opnsense/scripts/Xray/subscriptions.php auto', '*/5']],
    ];
}

function xray_cron_watchdog(): void
{
    (new OPNsense\Core\Backend())->configdRun('xray watchdog');
}

function xray_xmlrpc_sync()
{
    return [];
}

/** Restore the peer required by FreeBSD after native interface address changes. */
function xray_restore_tun_address($verbose = false, $interfaces = []): void
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    foreach ($cfg->OPNsense->xray->instances->instance ?? [] as $instance) {
        $device = (string)$instance->tun_interface;
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,14}$/D', $device)) continue;
        foreach ($cfg->interfaces->children() as $key => $item) {
            if ($interfaces && !in_array($key, (array)$interfaces)) continue;
            $ip = (string)$item->ipaddr;
            if ((string)$item->if !== $device || !isset($item->enable) || (string)$item->subnet !== '30'
                || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $host = ip2long($ip) & 3;
            if ($host !== 1 && $host !== 2) continue;
            $details = [];
            exec('/sbin/ifconfig ' . escapeshellarg($device) . ' 2>/dev/null', $details, $rc);
            if ($rc || !preg_grep('/groups:.*\btun\b/', $details)) continue;
            $peer = long2ip(ip2long($ip) ^ 3);
            if (preg_grep('/\binet ' . preg_quote($ip, '/') . ' --> ' . preg_quote($peer, '/') . '\s/', $details)) continue;
            exec('/sbin/ifconfig ' . escapeshellarg($device) . ' inet ' . escapeshellarg($ip . '/30')
                . ' ' . escapeshellarg($peer) . ' up', $output, $rc);
            if (!$rc) system_routing_configure($verbose, $key);
        }
    }
}
