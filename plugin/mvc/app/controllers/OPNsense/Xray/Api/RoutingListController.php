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
        $response = $this->searchBase('list', ['enabled', 'name', 'preset', 'urls', 'networks', 'ports', 'refresh']);
        foreach ($response['rows'] ?? [] as &$row) {
            $row['alias'] = $this->alias((string)$row['uuid']);
            $row['sources'] = count(array_filter(preg_split('/[\r\n]+/', (string)($row['urls'] ?? ''))));
            $cache = '/var/db/xray-routing-lists/' . $row['alias'] . '.json';
            $data = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : [];
            $row['entries'] = is_array($data) ? count($data) : 0;
            $row['updated'] = is_file($cache) ? filemtime($cache) : 0;
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
        return $this->delBase('list', $uuid);
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('list', $uuid, $enabled);
    }
}
