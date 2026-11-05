<?php
namespace OPNsense\Xray;

/** Pure reconciliation engine used by the native catalog store. */
final class EndpointCatalog
{
    public const MAX_RECORDS = 1024;
    public const MAX_ACTIVE = 32;

    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$child) $child = self::canonical($child);
        return $value;
    }

    public static function identity(array $outbound): string
    {
        unset($outbound['tag']);
        return hash('sha256', json_encode(self::canonical($outbound), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function sourceRevision(array $source): string
    {
        return hash('sha256', json_encode(self::canonical($source), JSON_THROW_ON_ERROR));
    }

    private static function validate(array $outbound): void
    {
        if (!in_array($outbound['protocol'] ?? '', ['vless', 'vmess', 'trojan', 'shadowsocks', 'socks', 'http'], true)
            || empty($outbound['settings']) || !is_array($outbound['settings'])
            || isset($outbound['proxySettings']) || isset($outbound['streamSettings']['sockopt']['dialerProxy'])) {
            throw new \InvalidArgumentException('Invalid or chained endpoint');
        }
    }

    public static function migrate(array $state, array $legacy): array
    {
        if (($state['version'] ?? 0) === 1) return $state;
        if (!empty($state)) throw new \RuntimeException('Unknown catalog version');
        if (count($legacy) > self::MAX_ACTIVE) throw new \RuntimeException('Too many legacy endpoints');
        $state = ['version'=>1, 'sources'=>[], 'endpoints'=>[]];
        foreach ($legacy as $outbound) {
            self::validate($outbound);
            $identity = self::identity($outbound);
            $key = hash('sha256', 'manual:' . $identity);
            $state['endpoints'][$key] = ['source'=>'', 'identity'=>$identity,
                'name'=>(string)($outbound['tag'] ?? ''), 'outbound'=>$outbound,
                'enabled'=>true, 'present'=>true, 'removed'=>false];
        }
        return $state;
    }

    /** Re-read state after download; only the source revision must still match. */
    public static function reconcile(array $state, string $sourceId, string $expectedRevision, array $incoming): array
    {
        $source = $state['sources'][$sourceId] ?? null;
        if (!$source || !hash_equals(self::sourceRevision($source), $expectedRevision)) {
            throw new \RuntimeException('Source changed during download; refresh again');
        }
        if (!$incoming || count($incoming) > self::MAX_RECORDS) {
            throw new \InvalidArgumentException('Empty or oversized subscription');
        }
        $parsed = [];
        foreach ($incoming as $outbound) {
            self::validate($outbound);
            $identity = self::identity($outbound);
            $key = hash('sha256', $sourceId . ':' . $identity);
            $parsed[$key] = ['source'=>$sourceId, 'identity'=>$identity,
                'name'=>(string)($outbound['tag'] ?? ''), 'outbound'=>$outbound];
        }
        foreach ($state['endpoints'] as &$record) {
            if ($record['source'] === $sourceId) $record['present'] = false;
        }
        unset($record);
        foreach ($parsed as $key=>$record) {
            $old = $state['endpoints'][$key] ?? [];
            $state['endpoints'][$key] = $record + ['enabled'=>$old['enabled'] ?? false,
                'present'=>true, 'removed'=>$old['removed'] ?? false];
        }
        if (count($state['endpoints']) > self::MAX_RECORDS) {
            throw new \RuntimeException('Catalog limit reached; remove obsolete sources before refreshing');
        }
        self::active($state);
        return $state;
    }

    public static function select(array $state, string $key, bool $enabled): array
    {
        if (!isset($state['endpoints'][$key])) throw new \InvalidArgumentException('Endpoint not found');
        $state['endpoints'][$key]['enabled'] = $enabled;
        if ($enabled) $state['endpoints'][$key]['removed'] = false;
        self::active($state);
        return $state;
    }

    public static function remove(array $state, string $key): array
    {
        if (!isset($state['endpoints'][$key])) throw new \InvalidArgumentException('Endpoint not found');
        if ($state['endpoints'][$key]['source'] === '') unset($state['endpoints'][$key]);
        else {
            // Keep a tombstone so refresh cannot recreate a server the user removed.
            $state['endpoints'][$key]['enabled'] = false;
            $state['endpoints'][$key]['removed'] = true;
        }
        return $state;
    }

    public static function active(array $state): array
    {
        $result = [];
        $seen = [];
        foreach ($state['endpoints'] as $record) {
            $source = $record['source'];
            if (!$record['enabled'] || !$record['present'] || $record['removed']
                || ($source !== '' && !($state['sources'][$source]['enabled'] ?? false))) continue;
            if (isset($seen[$record['identity']])) continue;
            $seen[$record['identity']] = true;
            $result[] = $record['outbound'];
        }
        if (count($result) > self::MAX_ACTIVE) throw new \RuntimeException('Select at most 32 distinct endpoints');
        return $result;
    }
}
