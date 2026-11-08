#!/usr/local/bin/php
<?php
define('XRAY_LIBRARY_ONLY', true);
require_once __DIR__ . '/xray-service-control.php';

const ROUTING_PID = '/var/run/xray-routing.pid';
const ROUTING_CONFIG = '/usr/local/etc/xray-routing.json';
const ROUTING_SNIPPET = '/usr/local/etc/unbound.opnsense.d/xray-routing.conf';

function routing_stop(): void
{
    $pid = is_file(ROUTING_PID) ? (int)file_get_contents(ROUTING_PID) : 0;
    if ($pid > 0) {
        $args = (string)shell_exec('/bin/ps -o args= -p ' . $pid);
        if (strpos($args, __DIR__ . '/xray-routing.py') !== false) {
            exec('/bin/kill -TERM ' . $pid);
            for ($i = 0; $i < 40 && proc_is_running(ROUTING_PID); $i++) usleep(100000);
            if (proc_is_running(ROUTING_PID)) {
                throw new RuntimeException('Routing broker did not stop');
            }
        }
    }
    @unlink(ROUTING_PID);
}

function routing_check(string $path): void
{
    exec('/usr/local/bin/python3 ' . escapeshellarg(__DIR__ . '/xray-routing.py')
        . ' --check --config ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    if ($rc !== 0) throw new RuntimeException(implode("\n", $output));
}

function routing_start(): void
{
    if (!is_file(ROUTING_CONFIG) || proc_is_running(ROUTING_PID)) return;
    $config = json_decode(file_get_contents(ROUTING_CONFIG), true, 512, JSON_THROW_ON_ERROR);
    if (empty($config['routes'])) return;
    routing_check(ROUTING_CONFIG);
    exec('/usr/sbin/daemon -o /var/log/xray-routing.log -p ' . escapeshellarg(ROUTING_PID)
        . ' /usr/local/bin/python3 -u ' . escapeshellarg(__DIR__ . '/xray-routing.py')
        . ' --config ' . escapeshellarg(ROUTING_CONFIG) . ' </dev/null >/dev/null 2>&1 &');
    for ($i = 0; $i < 150; $i++) {
        clearstatcache();
        if (proc_is_running(ROUTING_PID) && file_exists('/var/unbound/run/xray-routing.sock')) return;
        usleep(100000);
    }
    throw new RuntimeException('Routing broker failed to start; inspect /var/log/xray-routing.log');
}

$tmp = '';
$lock = fopen('/var/run/xray-routing-control.lock', 'ce');
$exitCode = 0;
$oldConfig = null;
$oldSnippet = '';
$oldCallback = null;
$callbackChanged = false;
$activeChanged = false;
$databaseBackup = null;
$staleToFlush = [];
$staleToKill = [];
try {
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Routing control is busy');
    $oldConfig = is_file(ROUTING_CONFIG) ? file_get_contents(ROUTING_CONFIG) : null;
    $oldSnippet = is_file(ROUTING_SNIPPET) ? file_get_contents(ROUTING_SNIPPET) : '';
    $oldCallback = is_file('/var/unbound/xray-routing.py') ? file_get_contents('/var/unbound/xray-routing.py') : null;
    $action = $argv[1] ?? 'apply';
    if ($action === 'stop') {
        routing_stop();
    } elseif ($action === 'start') {
        routing_start();
    } elseif ($action === 'apply') {
        $config = xray_firewall_routes(getenv('XRAY_LOCATION_ASSET'));
        $hasDomains = (bool)array_filter($config['routes'], static fn($route) => $route['geosite'] !== '' || !empty($route['custom_domains']) || !empty($route['custom_hosts']));
        if ($hasDomains) {
            $unbound = new OPNsense\Unbound\Unbound();
            if ((string)$unbound->general->enabled !== '1') throw new RuntimeException('Enable Unbound before applying geosite firewall lists');
        }
        $tmp = tempnam('/tmp', 'xray-routing-');
        if ($tmp === false) throw new RuntimeException('Cannot create routing config');
        file_put_contents($tmp, json_encode($config, JSON_THROW_ON_ERROR));
        chmod($tmp, 0600);
        routing_check($tmp);

        // Create only managed external aliases. Firewall rules/gateways stay user-owned.
        $core = OPNsense\Core\Config::getInstance();
        $core->lock();
        try {
            $model = new OPNsense\Firewall\Alias();
            $changed = false;
            foreach ($config['routes'] as $route) {
                $node = null;
                foreach ($model->aliases->alias->iterateItems() as $alias) {
                    if ((string)$alias->name === $route['table']) {
                        if ((string)$alias->type !== 'external' || (string)$alias->description !== 'Managed by os-xray DNS routing') {
                            throw new RuntimeException('Alias name already used: ' . $route['table']);
                        }
                        $node = $alias;
                        break;
                    }
                }
                if ($node === null) {
                    $node = $model->aliases->alias->Add();
                    $node->setNodes(['name' => $route['table'], 'enabled' => '1', 'type' => 'external', 'description' => 'Managed by os-xray DNS routing']);
                    $changed = true;
                }
            }
            $aliasRows = [];
            $aliasKeys = [];
            foreach ($model->aliases->alias->iterateItems() as $key => $alias) {
                $name = (string)$alias->name;
                $aliasKeys[$name] = $key;
                $aliasRows[] = ['name'=>$name, 'type'=>(string)$alias->type, 'description'=>(string)$alias->description];
            }
            $referenced = [];
            $filter = new OPNsense\Firewall\Filter();
            foreach ($filter->rules->rule->iterateItems() as $rule) {
                foreach ([(string)$rule->source_net, (string)$rule->destination_net] as $value) {
                    foreach (xray_rule_alias_references($value) as $name) $referenced[$name] = true;
                }
            }
            $cleanup = xray_stale_managed_aliases(array_column($config['routes'], 'table'), $aliasRows, array_keys($referenced));
            foreach ($cleanup['delete'] as $name) {
                if (isset($aliasKeys[$name]) && $model->aliases->alias->del($aliasKeys[$name])) $changed = true;
            }
            $staleToFlush = $cleanup['flush'];
            $staleToKill = $cleanup['delete'];
            foreach ($model->performValidation() as $error) throw new RuntimeException((string)$error);
            if ($changed) {
                $model->serializeToConfig();
                $core->save();
            }
        } finally {
            $core->unlock();
        }
        $newSnippet = '';
        if ($hasDomains) {
            foreach (glob('/usr/local/etc/unbound.opnsense.d/*.conf') as $custom) {
                if ($custom !== ROUTING_SNIPPET && preg_match('/\b(module-config|python-script)\s*:/', file_get_contents($custom))) {
                    throw new RuntimeException('An existing Unbound module override needs to be reconciled: ' . $custom);
                }
            }
            $base = is_file('/var/unbound/unbound.conf') ? file_get_contents('/var/unbound/unbound.conf') : '';
            if (!preg_match('/^\s*module-config:\s*"([a-z0-9 ]+)"/m', $base, $match) ||
                count(array_keys(explode(' ', trim($match[1])), 'python')) !== 1) {
                throw new RuntimeException('Expected the standard OPNsense Unbound module stack with one Python module');
            }
            // Included before OPNsense's stock python-script line; the first
            // script composes stock DNSBL and our callback in a single module.
            $newSnippet = "python:\n  python-script: xray-routing.py\n";
            if (!is_dir(dirname(ROUTING_SNIPPET))) mkdir(dirname(ROUTING_SNIPPET), 0755, true);
            $callbackChanged = true;
            if (!copy(__DIR__ . '/unbound_xray.py', '/var/unbound/xray-routing.py')) throw new RuntimeException('Cannot deploy Unbound callback');
            chmod('/var/unbound/xray-routing.py', 0644);
            $includes = $newSnippet;
            foreach (glob('/var/unbound/etc/*.conf') as $include) {
                if (basename($include) !== 'xray-routing.conf') $includes .= 'include: "' . $include . '"' . "\n";
            }
            $candidate = str_replace('include: /var/unbound/etc/*.conf', $includes, $base, $replaced);
            if ($replaced !== 1) throw new RuntimeException('Unrecognized Unbound include layout');
            $candidatePath = tempnam('/var/unbound', '.xray-check-');
            if ($candidatePath === false) throw new RuntimeException('Cannot stage Unbound configuration');
            try {
                file_put_contents($candidatePath, $candidate);
                chmod($candidatePath, 0600);
                exec('cd /var/unbound && /usr/local/sbin/unbound-checkconf ' . escapeshellarg($candidatePath) . ' 2>&1', $checkOutput, $checkRc);
                if ($checkRc !== 0) throw new RuntimeException('Unbound configuration check failed: ' . implode(' ', $checkOutput));
            } finally {
                unlink($candidatePath);
            }
        }
        // All parsing/validation is complete before changing the active broker.
        $activeChanged = true;
        routing_stop();
        // The stopped broker has closed SQLite. Preserve learned addresses for rollback.
        if (is_file('/var/db/xray-routing.sqlite')) {
            $backupCandidate = tempnam('/var/db', '.xray-routing-backup-');
            if ($backupCandidate === false) throw new RuntimeException('Cannot stage learned routing addresses');
            if (!copy('/var/db/xray-routing.sqlite', $backupCandidate)) { unlink($backupCandidate); throw new RuntimeException('Cannot back up learned routing addresses'); }
            $databaseBackup = $backupCandidate;
            chmod($databaseBackup, 0600);
        }
        if (!is_dir(dirname(ROUTING_CONFIG))) mkdir(dirname(ROUTING_CONFIG), 0755, true);
        $pending = ROUTING_CONFIG . '.new';
        if (!copy($tmp, $pending)) throw new RuntimeException('Cannot save routing config');
        chmod($pending, 0600);
        if (!rename($pending, ROUTING_CONFIG)) throw new RuntimeException('Cannot activate routing config');
        exec('/usr/local/etc/rc.filter_configure 2>&1', $filterOutput, $filterRc);
        if ($filterRc !== 0) throw new RuntimeException('Firewall reload failed: ' . implode(' ', $filterOutput));
        foreach ($staleToKill as $table) {
            $killOutput = [];
            exec('/sbin/pfctl -t ' . escapeshellarg($table) . ' -T kill 2>&1', $killOutput, $killRc);
            if ($killRc !== 0 && !xray_is_missing_table_error($killRc, implode("\n", $killOutput))) {
                throw new RuntimeException('Cannot remove stale managed alias table: ' . $table);
            }
        }
        foreach ($staleToFlush as $table) {
            $flushOutput = [];
            exec('/sbin/pfctl -t ' . escapeshellarg($table) . ' -T flush 2>&1', $flushOutput, $flushRc);
            if ($flushRc !== 0 && !xray_is_missing_table_error($flushRc, implode("\n", $flushOutput))) {
                throw new RuntimeException('Cannot empty stale managed alias: ' . $table);
            }
        }
        if ($config['routes']) routing_start();
        if ($newSnippet !== '') {
            file_put_contents(ROUTING_SNIPPET, $newSnippet);
        } else {
            @unlink(ROUTING_SNIPPET);
        }
        if ($hasDomains || $oldSnippet !== $newSnippet) {
            $output = (new OPNsense\Core\Backend())->configdRun('unbound restart');
            if (stripos($output, 'error') !== false || stripos($output, 'failed') !== false) {
                throw new RuntimeException('Unbound restart failed: ' . $output);
            }
        }
        echo json_encode(['result' => 'ok', 'message' => 'Firewall aliases updated. Select the XRAY alias in your firewall rule and set the TUN gateway.']) . "\n";
    } else {
        throw new RuntimeException('Unknown routing action');
    }
} catch (Throwable $error) {
    if ($callbackChanged) {
        if ($oldCallback !== null) file_put_contents('/var/unbound/xray-routing.py', $oldCallback);
        else @unlink('/var/unbound/xray-routing.py');
    }
    if ($activeChanged) {
        try {
            routing_stop();
            if ($databaseBackup !== null && is_file($databaseBackup)) {
                if (!copy($databaseBackup, '/var/db/xray-routing.sqlite')) throw new RuntimeException('Cannot restore learned routing addresses');
                chmod('/var/db/xray-routing.sqlite', 0600);
            }
            if ($oldConfig !== null) {
                xray_write_private(ROUTING_CONFIG, $oldConfig);
                routing_start();
            } else {
                @unlink(ROUTING_CONFIG);
            }
            if ($oldSnippet !== '') file_put_contents(ROUTING_SNIPPET, $oldSnippet);
            else @unlink(ROUTING_SNIPPET);
            (new OPNsense\Core\Backend())->configdRun('unbound restart');
        } catch (Throwable $rollbackError) {
            $error = new RuntimeException($error->getMessage() . '; rollback failed: ' . $rollbackError->getMessage());
        }
    }
    echo json_encode(['result' => 'failed', 'message' => $error->getMessage()]) . "\n";
    $exitCode = 1;
} finally {
    if (is_string($databaseBackup) && is_file($databaseBackup)) unlink($databaseBackup);
    if ($tmp && is_file($tmp)) unlink($tmp);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}
exit($exitCode);
