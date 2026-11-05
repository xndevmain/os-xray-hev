<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Instance';
    protected static $internalModelName  = 'instance';

    public function searchItemAction()
    {
        $response = $this->searchBase('instance', ['enabled', 'name', 'outbound_config', 'tun_interface']);
        if (!empty($response['rows'])) {
            $assigned = [];
            $peers = [];
            $pending = (new \OPNsense\Interfaces\NetworkInterface())->get_if_todo();
            foreach (\OPNsense\Core\Config::getInstance()->object()->interfaces->children() as $key => $interface) {
                if (isset($pending[$key])) continue;
                $device = (string)$interface->if;
                $assigned[$device] = (string)$key;
                $ip = (string)$interface->ipaddr;
                if ((string)$interface->subnet === '30' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $host = ip2long($ip) & 3;
                    if ($host === 1 || $host === 2) $peers[$device] = long2ip(ip2long($ip) ^ 3);
                }
            }
            foreach ($response['rows'] as &$row) {
                $row['assignment'] = $assigned[$row['tun_interface']] ?? '';
                $row['gateway_peer'] = $peers[$row['tun_interface']] ?? '';
                $row['firewall_alias'] = 'XRAY_' . substr(str_replace('-', '', strtolower($row['uuid'])), 0, 12);
                $ob    = json_decode($row['outbound_config'] ?? '', true);
                $pool = is_array($ob) && array_is_list($ob);
                $first = $pool ? ($ob[0] ?? []) : ($ob ?? []);
                $vnext = $first['settings']['vnext'][0] ?? $first['settings']['servers'][0] ?? [];
                $row['proxy_count'] = $pool ? count($ob) : 1;
                $row['server_address'] = ($vnext['address'] ?? '') . ($pool ? ' (pool: ' . count($ob) . ')' : '');
                $row['server_port']    = isset($vnext['port']) ? (string)$vnext['port'] : '';
                unset($row['outbound_config']);
            }
            unset($row);
        }
        return $response;
    }

    private function endpointPool($node): array
    {
        $raw = trim((string)$node->outbound_config);
        $items = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return isset($items['protocol']) ? [$items] : $items;
    }

    public function listEndpointsAction()
    {
        $model = new \OPNsense\Xray\Instance();
        foreach ($model->instance->iterateItems() as $uuid => $node) {
            $rows = [];
            foreach ($this->endpointPool($node) as $index => $item) {
                $settings = $item['settings'] ?? [];
                $peer = $settings['vnext'][0] ?? $settings['servers'][0] ?? $settings;
                $name = (string)($item['tag'] ?? '');
                if ($name === '' || preg_match('/^proxy(?:-?[0-9]+)?$/iD', $name)) $name = strtoupper($item['protocol']) . ' ' . ($index + 1);
                $rows[] = ['id' => $index + 1, 'name' => $name, 'protocol' => $item['protocol'],
                    'server' => $peer['address'] ?? '', 'port' => $peer['port'] ?? '',
                    'transport' => $item['streamSettings']['network'] ?? 'tcp'];
            }
            return ['uuid' => $uuid, 'rows' => $rows, 'revision' => hash('sha256', (string)$node->outbound_config)];
        }
        return ['rows' => []];
    }

    public function removeEndpointsAction($uuid)
    {
        if (\OPNsense\Xray\CatalogStore::ready()) return ['error'=>'Use the endpoint catalog API'];
        if (!$this->request->isPost()) return ['error' => 'POST required'];
        $ids = $this->request->getPost('ids');
        if (!is_array($ids) || !$ids) return ['error' => 'Select endpoints'];
        $config = \OPNsense\Core\Config::getInstance();
        $config->lock();
        try {
            $model = new \OPNsense\Xray\Instance();
            foreach ($model->instance->iterateItems() as $key => $node) {
                if ($key !== $uuid) continue;
                if ($this->request->getPost('revision') !== hash('sha256', (string)$node->outbound_config)) {
                    return ['error' => 'Pool changed. Refresh and select again.'];
                }
                $pool = $this->endpointPool($node);
                foreach ($ids as $id) {
                    if (!ctype_digit((string)$id) || !isset($pool[(int)$id - 1])) return ['error' => 'Invalid selection'];
                    unset($pool[(int)$id - 1]);
                }
                if (!$pool && (string)$config->object()->OPNsense->xray->general->enabled === '1') return ['error' => 'Disable the tunnel before removing its last endpoint.'];
                $node->outbound_config = json_encode(array_values($pool), JSON_THROW_ON_ERROR);
                $model->serializeToConfig();
                $config->save();
                return ['result' => 'saved'];
            }
            return ['error' => 'Tunnel missing'];
        } finally { $config->unlock(); }
    }

    public function appendEndpointsAction($uuid = '')
    {
        if (\OPNsense\Xray\CatalogStore::ready()) return ['error'=>'Use the endpoint catalog API'];
        if (!$this->request->isPost()) return ['error' => 'POST required'];
        $items = json_decode($this->request->getPost('outbounds', null, ''), true);
        if (!is_array($items) || !$items || !array_is_list($items)) return ['error' => 'Invalid endpoints'];
        $names = json_decode($this->request->getPost('names', null, '[]'), true);
        if (!is_array($names) || !array_is_list($names) || ($names && count($names) !== count($items))) return ['error'=>'Invalid endpoint names'];
        foreach ($names as $name) if (!is_string($name) || strlen($name) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $name)) return ['error'=>'Invalid endpoint name'];
        foreach ($items as $item) {
            if (!is_array($item) || !in_array($item['protocol'] ?? '', ['vmess','vless','shadowsocks','trojan'], true)) return ['error' => 'Unsupported endpoint'];
        }
        $config = \OPNsense\Core\Config::getInstance();
        $config->lock();
        try {
            $model = new \OPNsense\Xray\Instance();
            $node = null;
            foreach ($model->instance->iterateItems() as $key => $candidate) {
                if ($uuid === '' || $uuid === $key) { $node = $candidate; break; }
            }
            if (!$node && $uuid !== '') return ['error' => 'Instance no longer exists'];
            if (!$node) { $node = $model->instance->Add(); $node->name = 'default'; }
            $raw = trim((string)$node->outbound_config);
            $pool = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($pool)) return ['error' => 'Invalid existing pool'];
            if (isset($pool['protocol'])) $pool = [$pool];
            $unique = [];
            foreach (array_merge($pool, $items) as $index => $entry) {
                $name = $index < count($pool) ? (string)($entry['tag'] ?? '') : trim($names[$index - count($pool)] ?? '');
                unset($entry['tag']);
                $fingerprint = json_encode($entry);
                if ($name === '') $name = $unique[$fingerprint]['tag'] ?? '';
                if ($name !== '') $entry['tag'] = $name;
                $unique[$fingerprint] = $entry;
            }
            if (count($unique) > 32) return ['error' => 'Maximum 32 endpoints per tunnel'];
            $node->outbound_config = json_encode(array_values($unique), JSON_UNESCAPED_SLASHES);
            foreach ($model->performValidation() as $error) return ['error' => (string)$error];
            $model->serializeToConfig();
            $config->save();
            return ['result' => 'saved', 'count' => count($unique)];
        } finally { $config->unlock(); }
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return ['error' => 'Use Enable Xray in General settings.'];
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('instance', 'instance', $uuid);
    }

    public function addItemAction()
    {
        return ['result' => 'failed', 'message' => 'Xray uses one tunnel. Add servers in Endpoints.'];
    }

    public function setItemAction($uuid)
    {
        if (\OPNsense\Xray\CatalogStore::ready() && isset(($this->request->getPost('instance') ?? [])['outbound_config'])) return ['error'=>'Manage servers in Endpoints'];
        return $this->setBase('instance', 'instance', $uuid);
    }

    public function delItemAction($uuid)
    {
        return ['result' => 'failed', 'message' => 'The single tunnel can be disabled, not deleted.'];
    }
}
