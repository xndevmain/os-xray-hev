#!/usr/local/bin/php
<?php
require_once 'config.inc';

function ipv4_range(string $cidr): ?array
{
    $parts = explode('/', $cidr);
    if (!filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
    $prefix = $parts[1] ?? '32';
    if (!ctype_digit($prefix) || (int)$prefix > 32) return null;
    $size = 2 ** (32 - (int)$prefix);
    $start = (int)(floor((float)sprintf('%u', ip2long($parts[0])) / $size) * $size);
    return [$start, $start + $size - 1];
}

$config = OPNsense\Core\Config::getInstance();
$config->lock();
try {
    $instances = $config->object()->OPNsense->xray->instances->instance;
    if (count($instances) !== 1) throw new RuntimeException('Exactly one tunnel is required');
    $instance = $instances[0];
    $device = (string)$instance->tun_interface;
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,14}$/D', $device)) throw new RuntimeException('Invalid TUN name');
    exec('/sbin/ifconfig ' . escapeshellarg($device) . ' 2>/dev/null', $details, $rc);
    if ($rc !== 0 || !preg_grep('/groups:.*\btun\b/', $details)) throw new RuntimeException('Start the tunnel before configuring its interface');
    $assigned = null;
    $used = [];
    foreach ($config->object()->interfaces->children() as $key => $item) {
        if ((string)$item->if === $device) {
            if (in_array($key, ['lan', 'wan'])) throw new RuntimeException('Cannot change LAN or WAN');
            $assigned = $key;
            if ((string)$item->ipaddr !== '') {
                echo json_encode(['result'=>'existing', 'interface'=>$key]) . "\n";
                exit;
            }
        }
        $range = ipv4_range((string)$item->ipaddr . '/' . (string)$item->subnet);
        if ($range) $used[] = $range;
    }
    foreach ($config->object()->virtualip->vip ?? [] as $vip) {
        $range = ipv4_range((string)$vip->subnet . '/' . (string)$vip->subnet_bits);
        if ($range) $used[] = $range;
    }
    exec('/usr/bin/netstat -rn -f inet', $routes, $routeRc);
    if ($routeRc !== 0) throw new RuntimeException('Cannot check existing routes');
    foreach ($routes as $line) {
        $destination = preg_split('/\s+/', trim($line))[0];
        if ($destination === 'default' || $destination === '0.0.0.0/0') continue;
        // netstat may abbreviate a network, e.g. 10/8.
        $parts = explode('/', $destination);
        if (isset($parts[1]) && preg_match('/^[0-9.]+$/D', $parts[0])) {
            $parts[0] = implode('.', array_pad(explode('.', $parts[0]), 4, '0'));
        }
        $range = ipv4_range(implode('/', $parts));
        if ($range) $used[] = $range;
    }
    $chosen = null;
    $base = ip2long('198.18.0.0');
    for ($offset = 0; $offset < 131072; $offset += 4) {
        $candidate = [$base + $offset, $base + $offset + 3];
        foreach ($used as $range) {
            if ($candidate[0] <= $range[1] && $range[0] <= $candidate[1]) continue 2;
        }
        $chosen = $candidate;
        break;
    }
    if (!$chosen) throw new RuntimeException('No free tunnel subnet in 198.18.0.0/15. Configure the interface manually.');
    $ip = long2ip($chosen[0] + 1);
    $peer = long2ip($chosen[0] + 2);
    if (($argv[1] ?? '') === 'plan') {
        echo json_encode(['result'=>'plan', 'device'=>$device, 'address'=>$ip . '/30', 'peer'=>$peer]) . "\n";
        exit;
    }
    if (!$assigned) {
        $model = new OPNsense\Interfaces\NetworkInterface();
        if ($model->get_if_todo()) throw new RuntimeException('Apply pending interface assignments first');
        $node = $model->interface->Add();
        $node->if = $device;
        $node->descr = 'XRAY';
        $node->lock = '1';
        foreach ($model->performValidation() as $error) throw new RuntimeException((string)$error);
        $model->serializeToConfig();
        foreach ($config->object()->interfaces->children() as $key => $item) {
            if ((string)$item->if === $device) $assigned = $key;
        }
    }
    if (!$assigned) throw new RuntimeException('Could not assign tunnel');
    $item = $config->object()->interfaces->$assigned;
    $item->enable = '1';
    $item->ipaddr = $ip;
    $item->subnet = '30';
    $item->mtu = (string)$instance->mtu ?: '1500';
    unset($item->blockpriv, $item->blockbogons);
    $config->save();
} catch (Throwable $error) {
    echo json_encode(['result'=>'failed', 'message'=>$error->getMessage()]) . "\n";
    exit;
} finally {
    $config->unlock();
}
// FreeBSD point-to-point TUN needs a destination when adding its first address.
exec('/sbin/ifconfig ' . escapeshellarg($device) . ' inet ' . escapeshellarg($ip)
    . ' ' . escapeshellarg($peer) . ' netmask 255.255.255.252 up 2>&1', $addressOutput, $addressRc);
if ($addressRc !== 0) {
    echo json_encode(['result'=>'failed', 'message'=>'Settings saved, but TUN address assignment failed']) . "\n";
    exit;
}
$backend = new OPNsense\Core\Backend();
$result = trim($backend->configdRun('interface reconfigure ' . $assigned));
exec('/sbin/ifconfig ' . escapeshellarg($device) . ' inet', $actual);
$applied = preg_grep('/\binet ' . preg_quote($ip, '/') . '\s/', $actual);
echo json_encode(['result'=>$applied ? 'saved' : 'failed', 'interface'=>$assigned,
    'address'=>$ip . '/30', 'peer'=>$peer,
    'message'=>$applied ? 'Interface configured' : 'Settings saved, but address was not applied. Open interface settings and apply.']) . "\n";
