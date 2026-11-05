#!/usr/local/bin/php
<?php

require_once('config.inc');
require_once __DIR__ . '/xray-config.php';
putenv('XRAY_LOCATION_ASSET=' . (is_dir('/usr/local/share/xray/current') ? '/usr/local/share/xray/current' : '/usr/local/share/xray'));

// ─── Shared constants (not per-instance) ─────────────────────────────────────
define('XRAY_BIN',          '/usr/local/bin/xray-core');
define('XRAY_CONF_DIR',     '/usr/local/etc/xray-core');
define('HEV_BIN',           '/usr/local/bin/hev-socks5-tunnel');
define('HEV_CONF_DIR',      '/usr/local/etc/hev-socks5-tunnel');
// BUG-7 FIX: stderr демонов в лог-файл вместо /dev/null
define('XRAY_DAEMON_LOG',   '/var/log/xray-core.log'); // общий лог (fallback)
define('XRAY_VERSION_FILE', '/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt');

// ─── Per-instance path functions ─────────────────────────────────────────────
// v3.0.0: все runtime-файлы именуются по UUID инстанса, чтобы N инстансов
// не конфликтовали за один PID-файл / конфиг / lock.
function xray_conf_path(string $inst_uuid): string
{
    return XRAY_CONF_DIR . "/config-{$inst_uuid}.json";
}
function xray_pid_path(string $inst_uuid): string
{
    return "/var/run/xray_core_{$inst_uuid}.pid";
}
function hev_conf_path(string $inst_uuid): string
{
    return HEV_CONF_DIR . "/config-{$inst_uuid}.yaml";
}
function hev_pid_path(string $inst_uuid): string
{
    return "/var/run/hev-socks5-tunnel_{$inst_uuid}.pid";
}
function xray_lock_path(string $inst_uuid): string
{
    return "/var/run/xray_start_{$inst_uuid}.lock";
}
function xray_stopped_flag(string $inst_uuid): string
{
    return "/var/run/xray_stopped_{$inst_uuid}.flag";
}
function xray_instance_log(string $inst_uuid): string
{
    return "/var/log/xray-core-{$inst_uuid}.log";
}

// ─── Read config from OPNsense config.xml ────────────────────────────────────

/**
 * Парсит один <instance> SimpleXMLElement в плоский PHP-массив конфига.
 * globalEnabled — значение general.enabled (общий выключатель плагина).
 */
function xray_parse_instance($inst, bool $globalEnabled): array
{
    // B6: нормализация loglevel.
    // Старые: ключ "e" (до v1.0.1) → "error"
    // Новые:  ключ "loglevel_error" (v1.0.1+) → "error"
    $rawLevel = (string)($inst->loglevel ?? 'warning');
    $levelMap = [
        'e'              => 'error',
        'loglevel_error' => 'error',
    ];
    $loglevel = $levelMap[$rawLevel] ?? ($rawLevel ?: 'warning');

    return [
        'enabled'         => $globalEnabled,
        'direct_domains' => (string)($inst->direct_domains ?? ''),
        'firewall_geosite' => (string)($inst->firewall_geosite ?? ''),
        'firewall_geoip' => (string)($inst->firewall_geoip ?? ''),
        'direct_ips' => (string)($inst->direct_ips ?? ''),
        'proxy_domains' => (string)($inst->proxy_domains ?? ''),
        'proxy_ips' => (string)($inst->proxy_ips ?? ''),
        'default_route' => (string)($inst->default_route ?? 'proxy') ?: 'proxy',
        'probe_url' => (string)($inst->probe_url ?? '') ?: 'https://www.gstatic.com/generate_204',
        'probe_interval' => (int)(string)($inst->probe_interval ?? 30) ?: 30,
        'name'            => (string)($inst->name             ?? 'default'),
        'outbound_config' => (string)($inst->outbound_config  ?? ''),
        'socks5_listen'   => (string)($inst->socks5_listen    ?? '127.0.0.1') ?: '127.0.0.1',
        'socks5_port'     => (int)(string)($inst->socks5_port ?? 10808) ?: 10808,
        'tun_iface'       => (string)($inst->tun_interface    ?? 'proxyhev2socks0'),
        'mtu'             => (int)(string)($inst->mtu         ?? 1500),
        'loglevel'        => $loglevel,
        'bypass_networks' => (string)($inst->bypass_networks  ?? '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')
                            ?: '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
    ];
}

/**
 * Читает все инстансы из config.xml.
 * Возвращает массив, индексированный по inst_uuid (UUID инстанса OPNsense).
 */
function xray_get_all_instances(): array
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    $g   = $cfg->OPNsense->xray->general   ?? null;
    $ins = $cfg->OPNsense->xray->instances  ?? null;

    $globalEnabled = (string)($g->enabled ?? '0') === '1';

    if (!$ins) {
        return [];
    }

    $result = [];
    foreach ($ins->instance as $inst) {
        // SimpleXML: атрибуты читаются через $element['attr']
        $inst_uuid = (string)$inst['uuid'];
        if ($inst_uuid === '') {
            continue;
        }
        $c = xray_parse_instance($inst, $globalEnabled);
        $c['inst_uuid'] = $inst_uuid;
        $result[$inst_uuid] = $c;
    }
    return $result;
}

/**
 * Читает конфиг конкретного инстанса по его UUID.
 * Если UUID не указан — возвращает первый найденный инстанс (обратная совместимость).
 */
function xray_get_config(string $inst_uuid = ''): array
{
    $all = xray_get_all_instances();
    if (empty($all)) {
        return [];
    }
    if ($inst_uuid !== '') {
        return $all[$inst_uuid] ?? [];
    }
    // Возвращаем первый инстанс если UUID не указан
    return reset($all);
}

// ─── Build routing block from comma-separated CIDR string ────────────────────
function xray_build_routing(string $bypassRaw): array
{
    $bypassNets = array_values(array_filter(array_map('trim', explode(',', $bypassRaw))));
    if (empty($bypassNets)) {
        $bypassNets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    }
    return [
        'domainStrategy' => 'IPIfNonMatch',
        'rules' => [[
            'type'        => 'field',
            'ip'          => $bypassNets,
            'outboundTag' => 'direct',
        ]],
    ];
}

// ─── P2.5: xhttp/splithttp compatibility ─────────────────────────────────────
function xray_normalize_transport(string $json): string
{
    if (!file_exists(XRAY_BIN)) {
        return $json;
    }
    exec(escapeshellarg(XRAY_BIN) . ' version 2>/dev/null', $out);
    $verLine = $out[0] ?? '';

    if (preg_match('/Xray\s+1\./', $verLine)) {
        $json = str_replace('"xhttp"', '"splithttp"', $json);
        $json = str_replace('"xhttpSettings"', '"splithttpSettings"', $json);
    }

    return $json;
}

// ─── Write xray config.json (per-instance) ───────────────────────────────────
function xray_write_config(array $c): void
{
    if (!is_dir(XRAY_CONF_DIR)) {
        mkdir(XRAY_CONF_DIR, 0750, true);
    }

    $inst_uuid = $c['inst_uuid'];
    $confFile  = xray_conf_path($inst_uuid);

    $config = xray_build_config($c);
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = xray_normalize_transport($json);

    xray_write_private($confFile, $json);
}

// ─── Write hev-socks5-tunnel config.yaml (per-instance) ──────────────────────────────
function hev_write_config(array $c): void
{
    if (!is_dir(HEV_CONF_DIR)) {
        mkdir(HEV_CONF_DIR, 0750, true);
    }
    $inst_uuid = $c['inst_uuid'];
    $yaml = hev_build_config($c);
    xray_write_private(hev_conf_path($inst_uuid), $yaml);
}

// ─── PID helpers (FreeBSD: no posix extension — use /bin/kill) ───────────────
function xray_write_private(string $path, string $content): void
{
    $tmp = tempnam(dirname($path), '.xray-');
    if ($tmp === false) throw new RuntimeException('Cannot stage runtime configuration');
    try {
        if (!chmod($tmp, 0600) || file_put_contents($tmp, $content) !== strlen($content) || !rename($tmp, $path)) {
            throw new RuntimeException('Cannot save runtime configuration');
        }
    } finally { if (is_file($tmp)) unlink($tmp); }
}

function proc_is_running(string $pidfile): bool
{
    clearstatcache(true, $pidfile);
    if (!is_file($pidfile)) return false;
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 1) return false;
    $args = trim((string)shell_exec('/bin/ps -ww -o args= -p ' . $pid . ' 2>/dev/null'));
    if (preg_match('/^(xray_core|hev-socks5-tunnel)_([a-f0-9-]{36})\.pid$/D', basename($pidfile), $match)) {
        $path = $match[1] === 'xray_core' ? xray_conf_path($match[2]) : hev_conf_path($match[2]);
        return str_contains($args, $path);
    }
    return basename($pidfile) === 'xray-routing.pid' && str_contains($args, __DIR__ . '/xray-routing.py');
}

function proc_kill(string $pidfile): void
{
    if (!proc_is_running($pidfile)) {
        @unlink($pidfile);
        return;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid > 0) {
        // БАГ-4 FIX: проверяем что PID принадлежит нашему процессу
        $comm = trim((string)shell_exec('ps -o comm= -p ' . $pid . ' 2>/dev/null'));
        if ($comm === '' || (strpos($comm, 'xray') === false && strpos($comm, 'hev-socks5-tunnel') === false)) {
            @unlink($pidfile);
            return;
        }

        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        $i = 0;
        while ($i++ < 30) {
            exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0) {
                break;
            }
            usleep(100000);
        }
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out2, $rc2);
        if ($rc2 === 0 && proc_is_running($pidfile)) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }
    @unlink($pidfile);
}

function proc_start(string $bin, string $args, string $pidfile, string $logfile = ''): void
{
    // BUG-7 FIX: stderr демона → XRAY_DAEMON_LOG вместо /dev/null
    $log = escapeshellarg($logfile !== '' ? $logfile : XRAY_DAEMON_LOG);
    exec('/usr/sbin/daemon -o ' . $log . ' -p ' . escapeshellarg($pidfile)
       . ' ' . escapeshellarg($bin) . ' ' . $args . ' </dev/null >/dev/null 2>&1 &');
}

// ─── Per-instance lock helpers ────────────────────────────────────────────────
/**
 * Захватывает эксклюзивный non-blocking lock для конкретного инстанса.
 * Возвращает дескриптор при успехе, false если lock уже захвачен.
 * Вызывающий ОБЯЗАН вызвать lock_release($fd, $inst_uuid) после завершения.
 *
 * @return resource|false
 */
function lock_acquire(string $inst_uuid)
{
    $lockPath = xray_lock_path($inst_uuid);
    $fd = fopen($lockPath, 'ce');
    if ($fd === false) {
        return false;
    }
    if (!flock($fd, LOCK_EX | LOCK_NB)) {
        fclose($fd);
        return false;
    }
    fwrite($fd, (string)getmypid());
    fflush($fd);
    return $fd;
}

/**
 * Освобождает lock инстанса, закрывает дескриптор, удаляет lock-файл.
 *
 * @param resource $fd
 */
function lock_release($fd, string $inst_uuid): void
{
    flock($fd, LOCK_UN);
    fclose($fd);
    // Keep the inode stable: unlinking can let two starters lock different files.
}

// ─── BUG-3 FIX: config validation before start ──────────────────────────────
function xray_validate_config(string $confFile): bool
{
    if (!file_exists(XRAY_BIN)) {
        echo "ERROR: xray-core binary not found\n";
        return false;
    }
    if (!file_exists($confFile)) {
        echo "ERROR: config file not found after write: {$confFile}\n";
        return false;
    }
    exec(escapeshellarg(XRAY_BIN) . ' run -test -format json -c ' . escapeshellarg($confFile) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        echo "ERROR: xray config validation failed:\n" . implode("\n", $out) . "\n";
        return false;
    }
    return true;
}

// ─── lo0 alias management ─────────────────────────────────────────────────────
function lo0_needs_alias(string $addr): bool
{
    if ($addr === '127.0.0.1' || $addr === '0.0.0.0') {
        return false;
    }
    $parts = explode('.', $addr);
    return count($parts) === 4 && $parts[0] === '127';
}

function lo0_alias_ensure(string $addr): void
{
    if (!lo0_needs_alias($addr)) {
        return;
    }
    exec('/sbin/ifconfig lo0 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        echo "WARNING: Cannot read lo0 interface\n";
        return;
    }
    $ifOutput = implode("\n", $out);
    if (strpos($ifOutput, $addr) !== false) {
        return;
    }
    exec('/sbin/ifconfig lo0 alias ' . escapeshellarg($addr) . ' 2>/dev/null', $out2, $rc2);
    if ($rc2 !== 0) {
        echo "WARNING: Failed to add lo0 alias {$addr}\n";
    } else {
        echo "INFO: Added lo0 alias {$addr}\n";
    }
}

function lo0_alias_remove(string $addr): void
{
    if (!lo0_needs_alias($addr)) {
        return;
    }
    exec('/sbin/ifconfig lo0 -alias ' . escapeshellarg($addr) . ' 2>/dev/null', $out, $rc);
    if ($rc === 0) {
        echo "INFO: Removed lo0 alias {$addr}\n";
    }
}

// ─── B9: TUN interface teardown ───────────────────────────────────────────────
function tun_destroy(string $iface): void
{
    if (empty($iface)) {
        return;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' destroy 2>/dev/null');
}

// ─── High-level per-instance actions ─────────────────────────────────────────
function xray_preflight(array $instances): bool
{
    $base = tempnam('/tmp', 'xray-preflight-');
    if ($base === false) {
        echo "ERROR: Cannot create preflight config\n";
        return false;
    }
    $tmp = $base . '.json';
    if (!rename($base, $tmp)) {
        unlink($base);
        echo "ERROR: Cannot name preflight JSON config\n";
        return false;
    }
    try {
        foreach ($instances as $instance) {
            if (!$instance['enabled']) {
                continue;
            }
            if (!is_executable(HEV_BIN)) {
                throw new RuntimeException('HevSocks5Tunnel binary is missing');
            }
            foreach (xray_get_all_instances() as $other) {
                if ($other['inst_uuid'] !== $instance['inst_uuid'] && $other['enabled'] &&
                    ($other['tun_iface'] === $instance['tun_iface'] ||
                    ($other['socks5_port'] === $instance['socks5_port'] &&
                    ($other['socks5_listen'] === $instance['socks5_listen'] || $other['socks5_listen'] === '0.0.0.0' || $instance['socks5_listen'] === '0.0.0.0')))) {
                    throw new RuntimeException('Enabled instances must use distinct TUN interfaces and SOCKS endpoints');
                }
            }
            file_put_contents($tmp, json_encode(xray_build_config($instance), JSON_THROW_ON_ERROR));
            chmod($tmp, 0600);
            if (!xray_validate_config($tmp)) {
                return false;
            }
            hev_build_config($instance);
        }
        return true;
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        return false;
    } finally {
        unlink($tmp);
    }
}

/**
 * do_stop() — останавливает hev-socks5-tunnel и xray-core инстанса, выставляет stopped flag.
 */
function do_stop(string $inst_uuid, ?string $tunIface = null): void
{
    if ($tunIface === null) {
        $c        = xray_get_config($inst_uuid);
        $tunIface = $c['tun_iface'] ?? 'proxyhev2socks0';
    }

    // Останавливаем hev-socks5-tunnel первым — он держит TUN open.
    proc_kill(hev_pid_path($inst_uuid));
    // Останавливаем xray-core
    proc_kill(xray_pid_path($inst_uuid));

    // Удаляем lo0 alias если был добавлен
    $c2 = xray_get_config($inst_uuid);
    lo0_alias_remove($c2['socks5_listen'] ?? '127.0.0.1');

    // БАГ-5 FIX: флаг намеренной остановки — watchdog не перезапускает
    file_put_contents(xray_stopped_flag($inst_uuid), date('Y-m-d H:i:s'));

    echo "Stopped.\n";
}

/**
 * do_start() — генерирует конфиги, запускает xray-core и hev-socks5-tunnel инстанса.
 */
function do_start(array $c): bool
{
    if (!file_exists(XRAY_BIN)) {
        echo "ERROR: xray-core not found at " . XRAY_BIN . "\n";
        return false;
    }
    if (!file_exists(HEV_BIN)) {
        echo "ERROR: hev-socks5-tunnel not found at " . HEV_BIN . "\n";
        return false;
    }

    $inst_uuid = $c['inst_uuid'];

    // B7: захватываем per-instance lock перед запуском
    $lock = lock_acquire($inst_uuid);
    if ($lock === false) {
        echo "INFO: Another start is already in progress for instance {$inst_uuid} (lock held). Skipping.\n";
        return true;
    }

    try {
        if (proc_is_running(xray_pid_path($inst_uuid)) && proc_is_running(hev_pid_path($inst_uuid))) {
            echo "Already running. Use Apply to load configuration changes.\n";
            return true;
        }
        // БАГ-5 FIX: снимаем флаг намеренной остановки
        @unlink(xray_stopped_flag($inst_uuid));

        foreach (xray_get_all_instances() as $otherUuid => $other) {
            if ($otherUuid !== $inst_uuid && $other['enabled'] &&
                ($other['tun_iface'] === $c['tun_iface'] ||
                 ($other['socks5_port'] === $c['socks5_port'] && ($other['socks5_listen'] === $c['socks5_listen'] || $other['socks5_listen'] === '0.0.0.0' || $c['socks5_listen'] === '0.0.0.0')))) {
                throw new RuntimeException('Enabled instances must use distinct TUN interfaces and SOCKS endpoints');
            }
        }
        xray_write_config($c);
        hev_write_config($c);

        lo0_alias_ensure($c['socks5_listen']);

        // BUG-3 FIX: валидация конфига до запуска
        if (!xray_validate_config(xray_conf_path($inst_uuid))) {
            return false;
        }

        $startedXray = false;
        $instLog = xray_instance_log($inst_uuid);
        if (!proc_is_running(xray_pid_path($inst_uuid))) {
            $startedXray = true;
            proc_start(XRAY_BIN, 'run -c ' . escapeshellarg(xray_conf_path($inst_uuid)), xray_pid_path($inst_uuid), $instLog);
            usleep(800000);
        }
        if (!proc_is_running(hev_pid_path($inst_uuid))) {
            proc_start(HEV_BIN, escapeshellarg(hev_conf_path($inst_uuid)), hev_pid_path($inst_uuid), $instLog);
            usleep(800000);
        }

        if (!proc_is_running(xray_pid_path($inst_uuid)) || !proc_is_running(hev_pid_path($inst_uuid))) {
            if ($startedXray) proc_kill(xray_pid_path($inst_uuid));
            proc_kill(hev_pid_path($inst_uuid));
            echo "ERROR: A process exited during startup; inspect the instance log.\n";
            return false;
        }

        // Назначаем IP на TUN через syshook (ждёт TUN, читает IP из config, reload firewall)
        exec('/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray ' . escapeshellarg($inst_uuid) . ' &');

        echo "Started.\n";
        return true;
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        return false;
    } finally {
        // B7: освобождаем lock гарантированно (даже при исключении)
        lock_release($lock, $inst_uuid);
    }
}

function do_status(string $inst_uuid = ''): void
{
    if ($inst_uuid !== '') {
        $xray = proc_is_running(xray_pid_path($inst_uuid));
        $hev  = proc_is_running(hev_pid_path($inst_uuid));
        echo json_encode([
            'status'      => ($xray && $hev) ? 'ok' : 'stopped',
            'xray_core'   => $xray ? 'running' : 'stopped',
            'hev-socks5-tunnel'   => $hev  ? 'running' : 'stopped',
            'inst_uuid'   => $inst_uuid,
        ]) . "\n";
        return;
    }

    // Без UUID: статус всех инстансов + агрегированный статус
    $all = xray_get_all_instances();
    if (empty($all)) {
        echo json_encode(['status' => 'stopped', 'xray_core' => 'stopped', 'hev-socks5-tunnel' => 'stopped']) . "\n";
        return;
    }
    // Для совместимости с текущим GUI: возвращаем статус первого инстанса
    $first = reset($all);
    $uuid0 = $first['inst_uuid'];
    $xray  = proc_is_running(xray_pid_path($uuid0));
    $hev   = proc_is_running(hev_pid_path($uuid0));
    echo json_encode([
        'status'    => ($xray && $hev) ? 'ok' : 'stopped',
        'xray_core' => $xray ? 'running' : 'stopped',
        'hev-socks5-tunnel' => $hev  ? 'running' : 'stopped',
    ]) . "\n";
}

function do_status_all(): void
{
    $all    = xray_get_all_instances();
    $result = [];
    foreach ($all as $inst_uuid => $c) {
        $xray = proc_is_running(xray_pid_path($inst_uuid));
        $hev  = proc_is_running(hev_pid_path($inst_uuid));
        $result[$inst_uuid] = [
            'name'      => $c['name'],
            'status'    => ($xray && $hev) ? 'ok' : 'stopped',
            'xray_core' => $xray ? 'running' : 'stopped',
            'hev-socks5-tunnel' => $hev  ? 'running' : 'stopped',
        ];
    }
    echo json_encode($result) . "\n";
}

/** One lock for user service actions and subscription activation. */
function xray_acquire_service_lock()
{
    $lock = fopen('/var/run/xray-service-control.lock', 'ce');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another service operation is running');
    return $lock;
}

/** Caller owns the service lock; configuration is validated before stopping. */
function xray_activate_current(): bool
{
    $instances = xray_get_all_instances();
    if (!xray_preflight($instances)) return false;
    foreach ($instances as $uuid => $c) do_stop($uuid, $c['tun_iface']);
    foreach ($instances as $c) if ($c['enabled'] && !do_start($c)) return false;
    return true;
}

// ─── Main ─────────────────────────────────────────────────────────────────────
if (defined('XRAY_LIBRARY_ONLY')) {
    return;
}
$action    = $argv[1] ?? 'status';
$inst_uuid = isset($argv[2]) ? trim($argv[2]) : '';

if ($inst_uuid !== '' && !preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/', $inst_uuid)) {
    echo "ERROR: Invalid instance UUID\n";
    exit(1);
}
if ($inst_uuid !== '' && !xray_get_config($inst_uuid)) {
    echo "ERROR: Instance not found\n";
    exit(1);
}
if (in_array($action, ['start', 'stop', 'restart', 'reconfigure'], true)) {
    try { $serviceLock = xray_acquire_service_lock(); }
    catch (Throwable $error) { echo 'ERROR: ' . $error->getMessage() . "\n"; exit(1); }
}
if (in_array($action, ['restart', 'reconfigure'], true)) {
    $selected = $inst_uuid !== '' ? [xray_get_config($inst_uuid)] : xray_get_all_instances();
    if (!xray_preflight($selected)) {
        exit(1);
    }
}

switch ($action) {
    case 'start':
        if ($inst_uuid !== '') {
            $c = xray_get_config($inst_uuid);
            if (empty($c) || !$c['enabled']) {
                echo "Xray is disabled or instance not found.\n";
                exit(0);
            }
            $ok = do_start($c);
            exit($ok ? 0 : 1);
        }
        // Запускаем все включённые инстансы
        $all = xray_get_all_instances();
        if (empty($all)) {
            echo "No instances configured.\n";
            exit(0);
        }
        $anyFailed = false;
        foreach ($all as $uuid => $c) {
            if (!$c['enabled']) continue;
            if (!do_start($c)) {
                $anyFailed = true;
            }
        }
        exit($anyFailed ? 1 : 0);

    case 'stop':
        if ($inst_uuid !== '') {
            do_stop($inst_uuid);
        } else {
            foreach (array_keys(xray_get_all_instances()) as $uuid) {
                do_stop($uuid);
            }
        }
        break;

    case 'restart':
        $anyFailed = false;
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxyhev2socks0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                $anyFailed = !do_start($c);
            }
        } else {
            $all = xray_get_all_instances();
            foreach ($all as $uuid => $c) {
                $tunIface = $c['tun_iface'] ?? 'proxyhev2socks0';
                do_stop($uuid, $tunIface);
            }
            sleep(1);
            foreach ($all as $uuid => $c) {
                if ($c['enabled'] && !do_start($c)) $anyFailed = true;
            }
        }
        exit($anyFailed ? 1 : 0);

    case 'reconfigure':
        // B10: возвращаем реальный статус
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxyhev2socks0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                $ok = do_start($c);
                if ($ok) {
                    echo "OK\n";
                    exit(0);
                } else {
                    echo "ERROR: Failed to start Xray services for instance {$inst_uuid}.\n";
                    exit(1);
                }
            } else {
                echo "Xray disabled — services stopped.\n";
                exit(0);
            }
        }
        // Без UUID: рекофигурируем все инстансы
        $all       = xray_get_all_instances();
        $allStopped = [];
        foreach ($all as $uuid => $c) {
            $tunIface = $c['tun_iface'] ?? 'proxyhev2socks0';
            do_stop($uuid, $tunIface);
            $allStopped[$uuid] = $c;
        }
        sleep(1);
        $anyFailed = false;
        foreach ($allStopped as $uuid => $c) {
            if ($c['enabled']) {
                if (!do_start($c)) {
                    $anyFailed = true;
                }
            }
        }
        if ($anyFailed) {
            echo "ERROR: One or more instances failed to start.\n";
            exit(1);
        }
        echo "OK\n";
        exit(0);

    case 'status':
        do_status($inst_uuid);
        break;

    case 'statusall':
        do_status_all();
        break;

    case 'validate':
        // БАГ-6 FIX: сухой прогон через временный файл (рабочий конфиг не перезаписывается)
        $c = $inst_uuid !== '' ? xray_get_config($inst_uuid) : xray_get_config();
        if (empty($c)) {
            echo "ERROR: No xray config found in OPNsense config.xml\n";
            exit(1);
        }
        $tmpBase = tempnam('/tmp', 'xray-validate-');
        if ($tmpBase === false) {
            echo "ERROR: Cannot create temp file for validation\n";
            exit(1);
        }
        $tmpConf = $tmpBase . '.json';
        if (!rename($tmpBase, $tmpConf)) {
            unlink($tmpBase);
            echo "ERROR: Cannot name validation JSON config\n";
            exit(1);
        }
        try {
            $config = xray_build_config($c);
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $json = xray_normalize_transport($json);
            file_put_contents($tmpConf, $json);
            chmod($tmpConf, 0600);
            $valid = xray_validate_config($tmpConf);
            if ($valid) echo "OK: config is valid\n";
        } catch (Throwable $e) {
            echo 'ERROR: ' . $e->getMessage() . "\n";
            $valid = false;
        } finally {
            @unlink($tmpConf);
        }
        exit($valid ? 0 : 1);

    case 'version':
        $ver = file_exists(XRAY_VERSION_FILE) ? trim(file_get_contents(XRAY_VERSION_FILE)) : 'unknown';
        echo json_encode(['version' => $ver]) . "\n";
        break;

    default:
        echo "Unknown action: $action\n";
        exit(1);
}
