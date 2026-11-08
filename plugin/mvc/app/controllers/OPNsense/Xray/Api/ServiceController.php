<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Service control: start / stop / reconfigure / status / log / testconnect.
 *
 * v3.0.0: все service-actions принимают опциональный $uuid инстанса.
 * Если $uuid пустой — действие применяется ко всем инстансам.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    public function geositesAction()
    {
        $rows = json_decode((new Backend())->configdRun('xray geosites'), true);
        return ['categories' => $rows['geosite'] ?? [], 'geoip' => $rows['geoip'] ?? []];
    }

    public function firewallListsAction()
    {
        if (!$this->request->isPost()) return ['result' => 'failed', 'message' => 'POST required'];
        $output = (new Backend())->configdRun('xray routingapply');
        $data = json_decode(trim($output), true);
        return is_array($data) ? $data : ['result' => 'failed', 'message' => 'No valid response from firewall list importer'];
    }

    public function ipListsAction()
    {
        if (!$this->request->isPost()) return ['result' => 'failed', 'message' => 'POST required'];
        session_write_close();
        $force = $this->request->getPost('force', 'int', 0) === 1;
        $output = (new Backend())->configdRun('xray iplistsupdate' . ($force ? ' force' : ''));
        $data = json_decode(trim($output), true);
        return is_array($data) ? $data : ['result' => 'failed', 'message' => 'No valid response from routing list updater'];
    }

    public function cancelGeodataAction()
    {
        if (!$this->request->isPost()) return ['result' => 'failed', 'message' => 'POST required'];
        return json_decode((new Backend())->configdRun('xray cancelgeodata'), true) ?: ['result' => 'failed', 'message' => 'Cancellation failed'];
    }

    public function geodataAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        session_write_close();
        $output = (new Backend())->configdRun('xray geodata');
        $data = json_decode(trim($output), true);
        return is_array($data) ? $data : ['result' => 'failed', 'message' => 'No valid response from geodata importer'];
    }

    protected static $internalServiceClass    = '\OPNsense\Xray\General';
    protected static $internalServiceTemplate = 'OPNsense/Xray';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'xray';

    /**
     * Санитизирует UUID аргумент из URL: оставляет только hex-символы и дефисы.
     */
    private function sanitizeUuid(string $uuid): string
    {
        if ($uuid !== '' && !preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/', $uuid)) {
            throw new \OPNsense\Base\UserException('Invalid instance UUID');
        }
        return $uuid;
    }

    /**
     * BUG-12 FIX: возвращает реальный статус reconfigure.
     */
    public function reconfigureAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray reconfigure' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));

        if (empty($output)) {
            return ['result' => 'failed', 'message' => 'No response from configd (timeout or service unavailable)'];
        }

        $hasError   = stripos($output, 'ERROR')   !== false
                   || stripos($output, 'failed')  !== false;
        $hasSuccess = stripos($output, 'OK')       !== false
                   || stripos($output, 'disabled') !== false;

        if ($hasError || !$hasSuccess) {
            return ['result' => 'failed', 'message' => $output];
        }

        return ['result' => 'ok', 'message' => $output];
    }

    public function statusAction($uuid = '')
    {
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray status' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $result  = $backend->configdRun($cmd);
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['status' => 'error', 'message' => trim($result)];
    }

    /**
     * GET /api/xray/service/statusAll — статус всех инстансов.
     */
    public function statusAllAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray statusall');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['error' => trim($result)];
    }

    public function startAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray start' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $failed  = empty($output)
                || stripos($output, 'ERROR')  !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function stopAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray stop' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        return [
            'result'  => empty($output) ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function restartAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray restart' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $failed  = empty($output)
                || stripos($output, 'ERROR')  !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * BUG-5 FIX: POST-only — лог содержит чувствительные данные.
     */
    public function logAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = $backend->configdRun('xray log');
        return ['log' => $output];
    }

    /**
     * BUG-5 FIX: POST-only.
     */
    public function xraylogAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $output  = $backend->configdRun('xray xraylog' . ($uuid !== '' ? ' ' . $uuid : ''));
        return ['log' => $output];
    }

    /**
     * E5: POST /api/xray/service/validate[/{uuid}]
     */
    public function validateAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray validate' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $ok      = stripos($output, 'OK')    !== false
                && stripos($output, 'ERROR') === false;
        return [
            'result'  => $ok ? 'ok' : 'failed',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * GET /api/xray/service/version
     */
    public function versionAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray version');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['version' => 'unknown'];
    }

    /**
     * E4: GET /api/xray/service/diagnostics[/{uuid}]
     */
    public function diagnosticsAction($uuid = '')
    {
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray ifstats' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        if (empty($output)) {
            return ['error' => 'No response from configd'];
        }
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON from ifstats: ' . $output];
        }
        return $data;
    }

    /**
     * I8: POST /api/xray/service/testconnect[/{uuid}]
     */
    public function testconnectAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray testconnect' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));

        $data = json_decode($output, true);
        return is_array($data) ? $data : ['result' => 'failed', 'message' => 'No valid response from proxy test'];
    }
    public function setupInterfaceAction()
    {
        if (!$this->request->isPost()) return ['result'=>'failed', 'message'=>'POST required'];
        $data = json_decode((new Backend())->configdRun('xray setupinterface'), true);
        return is_array($data) ? $data : ['result'=>'failed', 'message'=>'No response from interface setup'];
    }
    public function testSiteAction()
    {
        if (!$this->request->isPost()) return ['result'=>'failed', 'message'=>'POST required'];
        $value = trim((string)$this->request->getPost('host', 'string', ''));
        if (str_contains($value, '://')) $value = (string)parse_url($value, PHP_URL_HOST);
        // IDNA conversion happens in Python; PHP intl is optional on OPNsense.
        $host = rtrim($value, '.');
        if (!$host || strlen($host) > 1024 || !preg_match('/^[\p{L}\p{M}\p{N}.-]+$/uD', $host)) {
            return ['result'=>'failed', 'message'=>'Enter a valid hostname or website URL'];
        }
        $data = json_decode((new Backend())->configdpRun('xray testsite', [strtolower($host)]), true);
        return is_array($data) ? $data : ['result'=>'failed', 'message'=>'No response from site test'];
    }
    public function setupListRuleAction($uuid = '')
    {
        if (!$this->request->isPost()) return ['result'=>'failed', 'message'=>'POST required'];
        if (!preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', (string)$uuid)) {
            return ['result'=>'failed', 'message'=>'Invalid routing list UUID'];
        }
        $config = \OPNsense\Core\Config::getInstance();
        $config->lock();
        try {
            $list = null;
            foreach ((new \OPNsense\Xray\RoutingList())->list->iterateItems() as $key => $node) {
                if ($key === $uuid) { $list = $node; break; }
            }
            if ($list === null || (string)$list->enabled !== '1') throw new \RuntimeException('Enable and apply the routing list first');
            $kind = (string)($list->kind ?? 'ip');
            if (!in_array($kind, ['ip', 'domain'], true)) $kind = 'ip';
            $alias = 'XRAY_' . substr(str_replace('-', '', strtolower($uuid)), 0, 12);
            $ports = [];
            if ($kind === 'ip') {
                foreach (preg_split('/[,\r\n]+/', (string)$list->ports) as $raw) {
                    $range = trim($raw);
                    if ($range === '') continue;
                    $parts = explode(':', $range);
                    if (count($parts) > 2) throw new \RuntimeException('Invalid destination port range');
                    foreach ($parts as $port) if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) throw new \RuntimeException('Invalid destination port range');
                    if (count($parts) === 2 && (int)$parts[0] > (int)$parts[1]) throw new \RuntimeException('Invalid destination port range');
                    $ports[$range] = true;
                }
            }
            // Domain lists match all protocols without port scoping; IP lists
            // keep one UDP rule per configured port range (any protocol when
            // none are configured).
            $ports = $ports ? array_keys($ports) : [''];
            $root = $config->object();
            $instances = $root->OPNsense->xray->instances->instance ?? null;
            if ($instances === null || count($instances) !== 1) throw new \RuntimeException('Exactly one tunnel is required');
            $device = (string)$instances[0]->tun_interface;
            $assigned = '';
            foreach ($root->interfaces->children() as $key => $item) if ((string)$item->if === $device) $assigned = $key;
            if (!$assigned || !isset($root->interfaces->lan)) throw new \RuntimeException('Configure the tunnel and LAN interfaces first');
            $gateway = 'XRAY_' . strtoupper($assigned) . '_GW';
            $found = false;
            foreach ($root->OPNsense->Gateways->gateway_item ?? [] as $item) {
                if ((string)$item->name === $gateway && (string)$item->interface === $assigned && (string)$item->disabled !== '1') $found = true;
            }
            if (!$found) throw new \RuntimeException('Create the tunnel gateway first');
            $model = new \OPNsense\Firewall\Filter();
            $missing = [];
            foreach ($ports as $port) {
                $exists = false;
                foreach ($model->rules->rule->iterateItems() as $item) {
                    if ((string)$item->interface === 'lan' && (string)$item->destination_net === $alias
                        && (string)$item->gateway === $gateway && (string)$item->destination_port === $port
                        && (string)$item->enabled === '1'
                        && ($port === '' || (string)$item->protocol === 'UDP')) {
                        $exists = true; break;
                    }
                }
                if (!$exists) $missing[] = $port;
            }
            if (!$missing) return ['result'=>'failed', 'message'=>'The routing-list LAN rules already exist. Review their order in Firewall Rules.'];
            $position = 1;
            $allowPosition = null;
            foreach ($model->rules->rule->iterateItems() as $rule) {
                $sequence = (int)(string)$rule->sequence;
                $position = max($position, $sequence + 10);
                if ((string)$rule->interface === 'lan' && (string)$rule->enabled === '1'
                    && (string)$rule->action === 'pass' && (string)$rule->direction === 'in'
                    && in_array((string)$rule->ipprotocol, ['inet', 'inet46'], true)
                    && (string)$rule->source_net === 'lan' && (string)$rule->destination_net === 'any'
                    && in_array((string)$rule->protocol, ['', 'any'], true)
                    && in_array((string)$rule->destination_port, ['', 'any'], true)) {
                    $allowPosition = $allowPosition === null ? $sequence : min($allowPosition, $sequence);
                }
            }
            if ($allowPosition !== null) {
                $position = $allowPosition;
                $shift = count($missing) * 10;
                foreach ($model->rules->rule->iterateItems() as $rule) {
                    if ((int)(string)$rule->sequence >= $position) $rule->sequence = (string)((int)(string)$rule->sequence + $shift);
                }
            }
            $description = \OPNsense\Xray\RoutingListRule::description($kind, (string)$list->name, $alias);
            foreach ($missing as $index => $port) {
                $item = $model->rules->rule->Add();
                $nodes = ['sequence'=>(string)($position + $index * 10),'enabled'=>'1','action'=>'pass','quick'=>'1','interface'=>'lan',
                    'direction'=>'in','ipprotocol'=>'inet','source_net'=>'lan','destination_net'=>$alias,
                    'gateway'=>$gateway,'description'=>$description];
                if ($port !== '') { $nodes['protocol'] = 'UDP'; $nodes['destination_port'] = $port; }
                $item->setNodes($nodes);
            }
            foreach ($model->performValidation() as $error) throw new \RuntimeException((string)$error);
            $model->serializeToConfig();
            $config->save();
            return ['result'=>'saved', 'count'=>count($missing), 'alias'=>$alias];
        } catch (\Throwable $error) {
            return ['result'=>'failed', 'message'=>$error->getMessage()];
        } finally { $config->unlock(); }
    }

    public function setupRuleAction()
    {
        if (!$this->request->isPost()) return ['result'=>'failed', 'message'=>'POST required'];
        $config = \OPNsense\Core\Config::getInstance();
        $config->lock();
        try {
            $root = $config->object();
            $instances = $root->OPNsense->xray->instances->instance ?? null;
            if ($instances === null || count($instances) !== 1) throw new \RuntimeException('Exactly one tunnel is required');
            $device = (string)$instances[0]->tun_interface;
            $assigned = '';
            foreach ($root->interfaces->children() as $key=>$item) if ((string)$item->if === $device) $assigned = $key;
            if (!$assigned || !isset($root->interfaces->lan)) throw new \RuntimeException('Configure the tunnel and LAN interfaces first');
            $gateway = 'XRAY_' . strtoupper($assigned) . '_GW';
            $found = false;
            foreach ($root->OPNsense->Gateways->gateway_item ?? [] as $item) {
                if ((string)$item->name === $gateway && (string)$item->interface === $assigned && (string)$item->disabled !== '1') $found = true;
            }
            if (!$found) throw new \RuntimeException('Create the tunnel gateway first');
            $model = new \OPNsense\Firewall\Filter();
            foreach ($model->rules->rule->iterateItems() as $item) {
                if ((string)$item->interface === 'lan' && (string)$item->destination_net === 'XRAY_HOSTS' && (string)$item->gateway === $gateway) {
                    return ['result'=>'failed','message'=>'The Xray LAN rule already exists. Review its order in Firewall Rules.'];
                }
            }
            // Insert before the generic LAN allow rule, preserving earlier restrictions.
            $position = 1;
            $allowPosition = null;
            foreach ($model->rules->rule->iterateItems() as $rule) {
                $sequence = (int)(string)$rule->sequence;
                $position = max($position, $sequence + 10);
                if ((string)$rule->interface === 'lan' && (string)$rule->enabled === '1'
                    && (string)$rule->action === 'pass' && (string)$rule->direction === 'in'
                    && in_array((string)$rule->ipprotocol, ['inet', 'inet46'], true)
                    && (string)$rule->source_net === 'lan' && (string)$rule->destination_net === 'any'
                    && in_array((string)$rule->protocol, ['', 'any'], true)
                    && in_array((string)$rule->destination_port, ['', 'any'], true)
                    && in_array((string)$rule->source_port, ['', 'any'], true)
                    && (string)$rule->source_not !== '1' && (string)$rule->destination_not !== '1') {
                    $allowPosition = $allowPosition === null ? $sequence : min($allowPosition, $sequence);
                }
            }
            if ($allowPosition !== null) {
                $position = $allowPosition;
                foreach ($model->rules->rule->iterateItems() as $rule) {
                    if ((int)(string)$rule->sequence >= $position) $rule->sequence = (string)((int)(string)$rule->sequence + 10);
                }
            }
            $item = $model->rules->rule->Add();
            $item->setNodes(['sequence'=>(string)$position,'enabled'=>'1','action'=>'pass','quick'=>'1','interface'=>'lan',
                'direction'=>'in','ipprotocol'=>'inet','source_net'=>'lan','destination_net'=>'XRAY_HOSTS',
                'gateway'=>$gateway,'description'=>'Xray policy routing']);
            foreach ($model->performValidation() as $error) throw new \RuntimeException((string)$error);
            $model->serializeToConfig();
            $config->save();
            return ['result'=>'saved'];
        } catch (\Throwable $error) {
            return ['result'=>'failed','message'=>$error->getMessage()];
        } finally { $config->unlock(); }
    }
}
