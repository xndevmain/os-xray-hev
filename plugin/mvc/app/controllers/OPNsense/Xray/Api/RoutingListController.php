<?php
namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class RoutingListController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\RoutingList';
    protected static $internalModelName = 'list';

    private function alias(string $uuid): string
    {
        if (!preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', $uuid)) return '';
        return 'XRAY_' . substr(str_replace('-', '', strtolower($uuid)), 0, 12);
    }

    private function validateInput(): array
    {
        $item = $this->request->getPost('list');
        if (!is_array($item)) return ['list' => 'Routing-list data is required'];
        $errors = [];
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '' || strlen($name) > 128 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            $errors['list.name'] = 'Enter a name up to 128 characters';
        }
        $kind = strtolower(trim((string)($item['kind'] ?? 'ip')));
        if (!in_array($kind, ['ip', 'domain'], true)) $errors['list.kind'] = 'Choose IP or Domain';
        $urls = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($item['urls'] ?? '')))));
        if (count($urls) > 16) $errors['list.urls'] = 'Maximum 16 URLs per routing list';
        foreach ($urls as $url) {
            $parts = parse_url($url);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 8192
                || preg_match('/[\\s\\\\]/', $url)) {
                $errors['list.urls'] = 'Enter one HTTPS list URL without credentials per line';
                break;
            }
        }
        if ($kind === 'domain') {
            // Domain lists learn addresses through DNS; inline networks and UDP
            // ports are IP-list metadata and must not silently affect them.
            if (trim((string)($item['networks'] ?? '')) !== '') {
                $errors['list.networks'] = 'Inline networks apply only to IP routing lists';
            }
            if (trim((string)($item['ports'] ?? '')) !== '') {
                $errors['list.ports'] = 'UDP ports apply only to IP routing lists';
            }
            $domains = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($item['domains'] ?? '')))));
            foreach ($domains as $domain) {
                $domain = strtolower($domain);
                if (str_starts_with($domain, '*.')) $domain = substr($domain, 2);
                $domain = ltrim($domain, '.');
                $domain = rtrim($domain, '.');
                $labels = explode('.', $domain);
                $valid = strlen($domain) <= 253 && count($labels) >= 2 && !preg_match('/[\s,]/', $domain)
                    && !preg_match('/[^a-z0-9.-]/D', $domain)
                    && !array_filter($labels, static fn(string $label): bool =>
                        !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label));
                if (!$valid || filter_var($domain, FILTER_VALIDATE_IP)) {
                    $errors['list.domains'] = 'Enter one valid domain name per line';
                    break;
                }
            }
            if (!$urls && !$domains) $errors['list.urls'] = 'Add at least one HTTPS source or inline domain';
            return $errors;
        }
        // Inline domains are domain-list metadata; on IP-kind lists they must
        // not silently change semantics.
        if (trim((string)($item['domains'] ?? '')) !== '') {
            $errors['list.domains'] = 'Inline domains apply only to domain routing lists';
        }
        $networks = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string)($item['networks'] ?? '')))));
        foreach ($networks as $network) {
            [$address, $prefix] = array_pad(explode('/', $network, 2), 2, null);
            $version = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4
                : (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 6 : 0);
            if ($version === 0 || ($prefix !== null && (!ctype_digit($prefix) || (int)$prefix > ($version === 4 ? 32 : 128)))) {
                $errors['list.networks'] = 'Enter one valid IP address or CIDR network per line';
                break;
            }
        }
        if (!$urls && !$networks) $errors['list.urls'] = 'Add at least one HTTPS source or inline network';
        foreach (array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string)($item['ports'] ?? ''))))) as $range) {
            $parts = explode(':', $range);
            if (count($parts) > 2 || array_filter($parts, static fn($port) => !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)
                || (count($parts) === 2 && (int)$parts[0] > (int)$parts[1])) {
                $errors['list.ports'] = 'Enter one valid destination port or range per line';
                break;
            }
        }
        return $errors;
    }

    public function searchItemAction()
    {
        $response = $this->searchBase('list', ['enabled', 'name', 'kind', 'preset', 'urls', 'networks', 'domains', 'ports', 'refresh']);
        foreach ($response['rows'] ?? [] as &$row) {
            $row['alias'] = $this->alias((string)$row['uuid']);
            $row['kind'] = in_array((string)($row['kind'] ?? ''), ['ip', 'domain'], true) ? (string)$row['kind'] : 'ip';
            $row['sources'] = count(array_filter(preg_split('/[\r\n]+/', (string)($row['urls'] ?? ''))));
            $cache = '/var/db/xray-routing-lists/' . $row['alias'] . '.json';
            $row['entries'] = 0;
            $row['updated'] = 0;
            if (!is_file($cache)) continue;
            $row['updated'] = filemtime($cache);
            $data = json_decode((string)file_get_contents($cache), true);
            // Typed caches report entries only for the matching kind; legacy
            // plain arrays count as IP caches. Anything else fails closed.
            if (is_array($data) && array_is_list($data)) {
                $cachedKind = 'ip';
                $cachedEntries = $data;
            } elseif (is_array($data) && isset($data['kind'], $data['entries']) && is_array($data['entries'])) {
                $cachedKind = (string)$data['kind'];
                $cachedEntries = $data['entries'];
            } else {
                continue;
            }
            if ($cachedKind === $row['kind']) $row['entries'] = count($cachedEntries);
        }
        unset($row);
        return $response;
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('list', 'list', $uuid);
    }

    public function addItemAction()
    {
        if ($errors = $this->validateInput()) return ['result'=>'failed', 'validations'=>$errors];
        return $this->addBase('list', 'list');
    }

    public function setItemAction($uuid)
    {
        if ($errors = $this->validateInput()) return ['result'=>'failed', 'validations'=>$errors];
        return $this->setBase('list', 'list', $uuid);
    }

    public function delItemAction($uuid)
    {
        if (!preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', (string)$uuid)) {
            return ['result' => 'failed', 'message' => 'Invalid routing list UUID'];
        }
        $list = null;
        foreach ((new \OPNsense\Xray\RoutingList())->list->iterateItems() as $key => $node) {
            if ($key === $uuid) { $list = $node; break; }
        }
        if ($list === null) return ['result' => 'failed', 'message' => 'Routing list not found'];
        // IP lists keep the backward-compatible plain deletion; domain lists
        // additionally clean up every dependency they added to active state.
        if ((string)($list->kind ?? 'ip') !== 'domain') return $this->delBase('list', $uuid);
        $name = (string)$list->name;
        $alias = $this->alias($uuid);
        $fields = [
            'enabled' => (string)$list->enabled, 'name' => $name, 'kind' => 'domain',
            'preset' => (string)($list->preset ?? 'custom'), 'urls' => (string)($list->urls ?? ''),
            'networks' => (string)($list->networks ?? ''), 'domains' => (string)($list->domains ?? ''),
            'ports' => (string)($list->ports ?? ''), 'refresh' => (string)$list->refresh,
        ];
        $base = $this->delBase('list', $uuid);
        if (($base['result'] ?? '') !== 'deleted') return $base;
        session_write_close();

        // First remove the downloaded cache, DNS matcher and broker route while
        // the helper rule still references the alias. The routing transaction
        // therefore leaves a safely empty alias until its owned rule is gone.
        $reply = $this->runCleanupCommand('xray iplistsupdate cleanup-prepare');
        if (($reply['result'] ?? '') !== 'ok') {
            // Cleanup mode retained the orphan cache on failure, so the
            // restored list reapplies from cache without network access.
            $restored = $this->restoreDeletedItem($uuid, $fields);
            if ($restored) $this->runCleanupCommand('xray iplistsupdate cleanup-prepare');
            $suffix = $restored ? '' : ' Automatic rollback failed; restore the entry from the configuration history backup.';
            return ['result' => 'failed',
                'message' => 'The routing list was deleted but active-route cleanup failed: '
                    . ($reply['message'] ?? 'Routing-list cleanup apply failed') . $suffix,
                'cleanup' => ['rules' => 0, 'applied' => false]];
        }

        $removedRules = 0;
        $ruleCleanupError = '';
        $config = \OPNsense\Core\Config::getInstance();
        $config->lock();
        try {
            // Remove only plugin-owned helper rules for this alias; rules the
            // user created or edited on the same alias stay untouched.
            $filter = new \OPNsense\Firewall\Filter();
            foreach ($filter->rules->rule->iterateItems() as $key => $rule) {
                if (\OPNsense\Xray\RoutingListRule::ownedByDomainList(
                    (string)$rule->destination_net, (string)$rule->description, $alias)) {
                    $filter->rules->rule->del($key);
                    $removedRules++;
                }
            }
            if ($removedRules) {
                foreach ($filter->performValidation() as $error) throw new \RuntimeException((string)$error);
                $filter->serializeToConfig();
                $config->save();
            }
        } catch (\Throwable $error) {
            $ruleCleanupError = $error->getMessage();
        } finally {
            $config->unlock();
        }
        if ($ruleCleanupError !== '') {
            $restored = $this->restoreDeletedItem($uuid, $fields);
            if ($restored) $this->runCleanupCommand('xray iplistsupdate cleanup-prepare');
            $suffix = $restored ? '' : ' Automatic rollback failed; restore the entry from the configuration history backup.';
            return ['result' => 'failed', 'message' => 'Helper-rule cleanup failed: ' . $ruleCleanupError . $suffix,
                'cleanup' => ['rules' => 0, 'applied' => false]];
        }

        // With the owned rule removed, a second cleanup-mode apply can delete
        // the now unreferenced external alias. Cleanup mode always applies
        // (even when the route signature is unchanged) because removing the
        // helper rule changed the firewall, not the routes. Custom user rules
        // are preserved; when one still references the alias the routing
        // transaction keeps it present but empty so firewall compilation
        // remains safe. The retained orphan cache and the invalidated apply
        // signature let a later update retry the cleanup.
        $reply = $this->runCleanupCommand('xray iplistsupdate cleanup');
        if (($reply['result'] ?? '') !== 'ok') {
            return ['result' => 'deleted',
                'message' => 'The routing list and helper rules were removed, but final alias cleanup failed: '
                    . ($reply['message'] ?? 'Routing-list cleanup apply failed')
                    . ' Alias cleanup will be retried on the next routing-list update.',
                'cleanup' => ['rules' => $removedRules, 'applied' => false]];
        }
        return ['result' => 'deleted',
            'message' => 'Routing list, helper rules, downloaded cache and managed alias were removed and applied.',
            'cleanup' => ['rules' => $removedRules, 'applied' => true]];
    }

    private function runCleanupCommand(string $command): array
    {
        $output = trim((new \OPNsense\Core\Backend())->configdRun($command));
        $reply = json_decode($output, true);
        return is_array($reply) ? $reply : ['result' => 'failed', 'message' => 'No valid cleanup response'];
    }

    private function restoreDeletedItem(string $uuid, array $fields): bool
    {
        try {
            $config = \OPNsense\Core\Config::getInstance();
            $config->lock();
            try {
                $root = $config->object();
                if (isset($root->OPNsense->xray->routinglists)) {
                    $node = $root->OPNsense->xray->routinglists->addChild('list');
                    $node->addAttribute('uuid', $uuid);
                    foreach ($fields as $key => $value) $node->addChild($key, (string)$value);
                    $config->save();
                    return true;
                }
            } finally {
                $config->unlock();
            }
        } catch (\Throwable $ignored) {
        }
        return false;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('list', $uuid, $enabled);
    }
}
