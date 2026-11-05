<?php
namespace OPNsense\Xray;

/** Offline subscription parser. Never downloads URLs or returns secrets in errors. */
class Subscription
{
    private static function decode64(string $value): string
    {
        $value = preg_replace('/\s+/', '', $value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9+\/_-]*={0,2}\z/', $value)) {
            throw new \InvalidArgumentException('Invalid Base64 subscription');
        }
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        if ($decoded === false) throw new \InvalidArgumentException('Invalid Base64 subscription');
        return $decoded;
    }

    public static function parse(string $content, int $limit = 32): array
    {
        if (strlen($content) > 1048576) throw new \InvalidArgumentException('Subscription file exceeds 1 MiB');
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content));
        if (str_starts_with($content, '{') || str_starts_with($content, '[')) return self::parseJson($content, $limit);
        if (!str_contains($content, '://')) $content = trim(self::decode64($content));
        if (str_starts_with($content, '{') || str_starts_with($content, '[')) return self::parseJson($content, $limit);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $content)), static fn($line) => $line !== '' && !str_starts_with($line, '#')));
        if (!$lines) throw new \InvalidArgumentException('Subscription contains no servers');
        if (count($lines) > $limit) throw new \InvalidArgumentException('Subscription exceeds the server limit');
        $outbounds = [];
        $names = [];
        foreach ($lines as $index => $line) {
            try {
                $scheme = strtolower(strstr($line, '://', true) ?: '');
                $line = $scheme . substr($line, strlen($scheme));
                $data = match ($scheme) {
                    'vless' => self::parseVless($line),
                    'vmess' => self::parseVmess($line),
                    'trojan' => self::parseTrojan($line),
                    'ss' => self::parseShadowsocks($line),
                    default => throw new \InvalidArgumentException('Supported protocols: VLESS, VMess, Trojan, Shadowsocks'),
                };
                if (isset($data['error'])) throw new \InvalidArgumentException($data['error']);
                $outbound = json_decode($data['outbound_config'], true, 512, JSON_THROW_ON_ERROR);
                $outbound['tag'] = 'proxy-' . ($index + 1);
                $outbounds[] = $outbound;
                $names[] = $data['name'] ?: strtoupper($scheme) . ' ' . ($index + 1);
            } catch (\Throwable $error) {
                // Do not include the source line: it contains passwords / UUIDs.
                throw new \InvalidArgumentException('Line ' . ($index + 1) . ': ' . $error->getMessage());
            }
        }
        return [
            'status' => 'ok', 'count' => count($outbounds), 'servers' => $names,
            'name' => count($outbounds) === 1 ? $names[0] : 'Proxy pool (' . count($outbounds) . ')',
            'outbound_config' => json_encode(count($outbounds) === 1 ? $outbounds[0] : $outbounds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    private static function parseJson(string $content, int $limit): array
    {
        try { $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new \InvalidArgumentException('Invalid Xray JSON subscription'); }
        if (!is_array($data)) throw new \InvalidArgumentException('Expected Xray JSON objects');
        $configs = array_is_list($data) ? $data : [$data];
        $unique = [];
        $displayNames = [];
        $namePriority = [];
        foreach ($configs as $config) {
            if (!is_array($config)) throw new \InvalidArgumentException('Invalid Xray configuration entry');
            $items = isset($config['protocol']) ? [$config] : ($config['outbounds'] ?? []);
            if (!is_array($items)) throw new \InvalidArgumentException('Invalid Xray outbounds');
            $proxyCount = count(array_filter($items, static fn($item) => is_array($item) && in_array($item['protocol'] ?? '', ['vless','vmess','trojan','shadowsocks'], true)));
            foreach ($items as $item) {
                if (!is_array($item)) throw new \InvalidArgumentException('Invalid Xray outbound');
                $protocol = $item['protocol'] ?? '';
                if (in_array($protocol, ['freedom', 'blackhole', 'dns'], true)) continue;
                if (!in_array($protocol, ['vless', 'vmess', 'trojan', 'shadowsocks'], true)) {
                    throw new \InvalidArgumentException('Unsupported protocol in Xray JSON subscription');
                }
                if (!empty($item['proxySettings']) || !empty($item['streamSettings']['sockopt']['dialerProxy'])) {
                    throw new \InvalidArgumentException('Chained proxy outbounds cannot be imported as independent endpoints');
                }
                if (empty($item['settings']) || !is_array($item['settings'])) throw new \InvalidArgumentException('Missing outbound settings');
                $outbound = array_intersect_key($item, array_flip(['protocol', 'settings', 'streamSettings', 'mux']));
                $key = json_encode($outbound, JSON_THROW_ON_ERROR);
                $unique[$key] = $outbound;
                $name = ''; $priority = 0;
                if ($proxyCount === 1 && is_string($config['remarks'] ?? null) && trim($config['remarks']) !== '') {
                    $name = trim($config['remarks']); $priority = 2;
                } elseif (is_string($item['tag'] ?? null) && !preg_match('/^proxy(?:-?[0-9]+)?$/iD', $item['tag'])) {
                    $name = trim($item['tag']); $priority = 1;
                }
                if ($name !== '' && $priority >= ($namePriority[$key] ?? 0)) {
                    $displayNames[$key] = $name;
                    $namePriority[$key] = $priority;
                }
            }
        }
        $outbounds = array_values($unique);
        if (!$outbounds) throw new \InvalidArgumentException('Subscription contains no supported proxy servers');
        if (count($outbounds) > $limit) throw new \InvalidArgumentException('Subscription exceeds the server limit');
        $names = [];
        foreach ($outbounds as $index => &$outbound) {
            $outbound['tag'] = 'proxy-' . ($index + 1);
            $names[] = $displayNames[array_keys($unique)[$index]] ?? strtoupper($outbound['protocol']) . ' ' . ($index + 1);
        }
        unset($outbound);
        return ['status'=>'ok', 'count'=>count($outbounds), 'servers'=>$names,
            'name'=>count($outbounds) === 1 ? $names[0] : 'Proxy pool (' . count($outbounds) . ')',
            'outbound_config'=>json_encode($outbounds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
    }

    private static function endpoint(string $link): array
    {
        try { $parts = parse_url($link); } catch (\Throwable $error) { $parts = false; }
        if (!$parts || empty($parts['host']) || empty($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535) {
            throw new \InvalidArgumentException('Invalid server address or port');
        }
        $params = [];
        parse_str($parts['query'] ?? '', $params);
        foreach ($params as $value) if (!is_string($value)) throw new \InvalidArgumentException('Query parameters must be scalar strings');
        $parts['host'] = trim($parts['host'], '[]');
        $parts['params'] = $params;
        $parts['name'] = rawurldecode($parts['fragment'] ?? '');
        return $parts;
    }

    private static function result(array $outbound, string $name): array
    {
        return ['name' => $name, 'outbound_config' => json_encode($outbound, JSON_THROW_ON_ERROR)];
    }

    private static function parseTrojan(string $link): array
    {
        $parts = self::endpoint($link);
        $password = rawurldecode(($parts['user'] ?? '') . (isset($parts['pass']) ? ':' . $parts['pass'] : ''));
        if ($password === '') throw new \InvalidArgumentException('Trojan password is empty');
        $params = $parts['params'];
        return self::result([
            'protocol' => 'trojan',
            'settings' => ['servers' => [['address' => $parts['host'], 'port' => $parts['port'], 'password' => $password]]],
            'streamSettings' => self::buildStreamSettings($params['type'] ?? 'tcp', $params['security'] ?? 'tls', $params),
        ], $parts['name']);
    }

    private static function parseVmess(string $link): array
    {
        try { $data = json_decode(self::decode64(substr($link, 8)), true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new \InvalidArgumentException('VMess expects Base64-encoded JSON'); }
        if (!is_array($data) || empty($data['add']) || !preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/', (string)($data['id'] ?? ''))) throw new \InvalidArgumentException('Invalid VMess address or UUID');
        $port = filter_var($data['port'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new \InvalidArgumentException('Invalid VMess port');
        if ((int)($data['aid'] ?? 0) !== 0) throw new \InvalidArgumentException('Legacy VMess alterId is unsupported by modern Xray');
        $params = ['host' => (string)($data['host'] ?? ''), 'path' => (string)($data['path'] ?? ''), 'sni' => (string)($data['sni'] ?? ''), 'fp' => (string)($data['fp'] ?? 'chrome')];
        foreach (['alpn', 'allowInsecure'] as $key) if (isset($data[$key])) $params[$key] = (string)$data[$key];
        $type = (string)($data['net'] ?? 'tcp');
        if ($type === 'grpc') $params['serviceName'] = $params['path'];
        if ($type === 'tcp' && ($data['type'] ?? 'none') === 'http') $params['headerType'] = 'http';
        return self::result([
            'protocol' => 'vmess',
            'settings' => ['vnext' => [['address' => (string)$data['add'], 'port' => $port, 'users' => [['id' => $data['id'], 'security' => (string)($data['scy'] ?? 'auto')]]]]],
            'streamSettings' => self::buildStreamSettings($type, ($data['tls'] ?? '') === 'tls' ? 'tls' : 'none', $params),
        ], (string)($data['ps'] ?? ''));
    }

    private static function parseShadowsocks(string $link): array
    {
        $rest = substr($link, 5);
        if (!str_contains(explode('#', $rest, 2)[0], '@')) {
            [$encoded, $fragment] = array_pad(explode('#', $rest, 2), 2, '');
            $link = 'ss://' . self::decode64($encoded) . ($fragment !== '' ? '#' . $fragment : '');
        }
        $parts = self::endpoint($link);
        if (!empty($parts['params']['plugin'])) throw new \InvalidArgumentException('Shadowsocks SIP003 plugins require a custom outbound configuration');
        $userinfo = ($parts['user'] ?? '') . (isset($parts['pass']) ? ':' . $parts['pass'] : '');
        if (!isset($parts['pass'])) $userinfo = self::decode64(rawurldecode($userinfo));
        else $userinfo = rawurldecode($userinfo);
        [$method, $password] = array_pad(explode(':', $userinfo, 2), 2, '');
        if ($method === '' || $password === '') throw new \InvalidArgumentException('Invalid Shadowsocks method/password');
        return self::result(['protocol' => 'shadowsocks', 'settings' => ['servers' => [['address' => $parts['host'], 'port' => $parts['port'], 'method' => $method, 'password' => $password]]]], $parts['name']);
    }

    private static function parseVless(string $link): array
    {
        // Убираем лишние пробелы и кавычки
        $link = trim($link, " \t\n\r\0\x0B\"'");

        if (strpos($link, 'vless://') !== 0) {
            return ['error' => 'Link must start with vless://'];
        }

        // Убираем схему
        $rest = substr($link, 8); // после "vless://"

        // Отделяем #name в конце
        $name = '';
        if (($hashPos = strrpos($rest, '#')) !== false) {
            $name = rawurldecode(substr($rest, $hashPos + 1));
            $rest = substr($rest, 0, $hashPos);
        }

        // Отделяем ?query
        $query = '';
        if (($qPos = strpos($rest, '?')) !== false) {
            $query = substr($rest, $qPos + 1);
            $rest  = substr($rest, 0, $qPos);
        }

        // Отделяем UUID@host:port
        // UUID — всё до последнего @ (на случай если @ есть в UUID — маловероятно, но надёжно)
        $atPos = strrpos($rest, '@');
        if ($atPos === false) {
            return ['error' => 'Missing @ separator between UUID and host'];
        }

        $uuid    = substr($rest, 0, $atPos);
        $hostport = substr($rest, $atPos + 1);

        // Разбираем host:port (с учётом IPv6 [::1]:port)
        if (substr($hostport, 0, 1) === '[') {
            // IPv6
            $closeBracket = strpos($hostport, ']');
            if ($closeBracket === false) {
                return ['error' => 'Invalid IPv6 address format'];
            }
            $host = substr($hostport, 1, $closeBracket - 1);
            $portStr = ltrim(substr($hostport, $closeBracket + 1), ':');
        } else {
            $lastColon = strrpos($hostport, ':');
            if ($lastColon === false) {
                return ['error' => 'Missing port in host:port'];
            }
            $host    = substr($hostport, 0, $lastColon);
            $portStr = substr($hostport, $lastColon + 1);
        }

        $port = (int)$portStr;
        if ($port <= 0 || $port > 65535) {
            return ['error' => 'Invalid server port'];
        }

        if (empty($uuid)) {
            return ['error' => 'UUID is empty'];
        }
        // BUG-10 FIX: валидация формата UUID до попадания в ответ.
        // Без этой проверки пользователь получал ошибку только в момент Apply через XML Mask —
        // непонятно далеко от точки ввода. Теперь ошибка сразу при парсинге.
        // \z вместо $ — строгий конец строки, не допускает трейлинг \n (баг PHP PCRE: $ совпадает перед \n)
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $uuid)) {
            return ['error' => 'Invalid UUID format (expected xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)'];
        }
        if (empty($host)) {
            return ['error' => 'Host is empty'];
        }

        // Парсим query string
        parse_str($query, $params);
        foreach ($params as $value) {
            if (!is_string($value)) throw new \InvalidArgumentException('Query parameters must be scalar strings');
        }

        $type     = $params['type']     ?? 'tcp';
        $security = $params['security'] ?? 'none';

        $flow = $params['flow'] ?? '';

        if (!in_array($type, ['tcp', 'raw'], true) || $security !== 'reality') {
            $flow = '';
        }

        $fp = $params['fp'] ?? '';
        if (empty($fp)) {
            $fp = 'chrome';
        }

        // flow and fp whitelisted to known values (RCE/injection guard)
        $allowedFlow = ['xtls-rprx-vision', 'none', ''];
        $allowedFp   = ['chrome', 'firefox', 'safari', 'edge', 'random'];
        $flow = in_array($flow, $allowedFlow, true) ? $flow : 'xtls-rprx-vision';
        $fp   = in_array($fp,   $allowedFp,   true) ? $fp   : 'chrome';

        // Build outbound object — raw values (json_encode handles escaping)
        $outbound = [
            'tag'      => 'proxy',
            'protocol' => 'vless',
            'settings' => [
                'vnext' => [[
                    'address' => $host,
                    'port'    => $port,
                    'users'   => [[
                        'id'         => $uuid,
                        'encryption' => $params['encryption'] ?? 'none',
                        'flow'       => $flow,
                    ]],
                ]],
            ],
            'streamSettings' => self::buildStreamSettings($type, $security, $params),
        ];

        return [
            'name'            => $name,
            'outbound_config' => json_encode($outbound, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Собирает streamSettings для xray-core конфига на основе transport type и security.
     */
    private static function buildStreamSettings(string $type, string $security, array $params): array
    {
        if (!in_array($type, ['tcp', 'raw', 'ws', 'grpc', 'xhttp', 'httpupgrade', 'h2', 'http', 'kcp'], true)) throw new \InvalidArgumentException('Unsupported transport');
        if (!in_array($security, ['none', 'tls', 'reality'], true)) throw new \InvalidArgumentException('Unsupported transport security');
        $ss = [
            'network'  => $type,
            'security' => $security,
        ];

        // ── Security settings ────────────────────────────────────────────
        if ($security === 'reality') {
            $ss['realitySettings'] = [
                'serverName'  => $params['sni'] ?? '',
                'fingerprint' => $params['fp']  ?? 'chrome',
                'show'        => false,
                'publicKey'   => $params['pbk'] ?? '',
                'shortId'     => $params['sid'] ?? '',
                'spiderX'     => $params['spx'] ?? '',
            ];
        } elseif ($security === 'tls') {
            $tls = [
                'serverName'  => $params['sni'] ?? '',
                'fingerprint' => $params['fp']  ?? 'chrome',
            ];
            if (!empty($params['alpn'])) {
                $tls['alpn'] = explode(',', $params['alpn']);
            }
            if (isset($params['allowInsecure'])) $tls['allowInsecure'] = in_array($params['allowInsecure'], ['1', 'true'], true);
            $ss['tlsSettings'] = $tls;
        }

        // ── Transport settings ───────────────────────────────────────────
        switch ($type) {
            case 'xhttp':
                $xhttp = [];
                if (!empty($params['path'])) $xhttp['path'] = $params['path'];
                if (!empty($params['host'])) $xhttp['host'] = $params['host'];
                if (!empty($params['mode'])) $xhttp['mode'] = $params['mode'];
                if (!empty($xhttp)) $ss['xhttpSettings'] = $xhttp;
                break;

            case 'ws':
                $ws = [];
                if (!empty($params['path'])) $ws['path'] = $params['path'];
                if (!empty($params['host'])) $ws['headers'] = ['Host' => $params['host']];
                if (!empty($ws)) $ss['wsSettings'] = $ws;
                break;

            case 'grpc':
                $grpc = [];
                if (!empty($params['serviceName'])) $grpc['serviceName'] = $params['serviceName'];
                if (!empty($params['mode']))        $grpc['multiMode']   = ($params['mode'] === 'multi');
                if (!empty($grpc)) $ss['grpcSettings'] = $grpc;
                break;

            case 'h2':
            case 'http':
                $h2 = [];
                if (!empty($params['path'])) $h2['path'] = $params['path'];
                if (!empty($params['host'])) $h2['host'] = [$params['host']];
                if (!empty($h2)) $ss['httpSettings'] = $h2;
                break;

            case 'kcp':
                $kcp = [];
                if (!empty($params['headerType'])) $kcp['header'] = ['type' => $params['headerType']];
                if (!empty($params['seed']))       $kcp['seed']   = $params['seed'];
                if (!empty($kcp)) $ss['kcpSettings'] = $kcp;
                break;

            case 'httpupgrade':
                $ss['httpupgradeSettings'] = ['host' => $params['host'] ?? '', 'path' => $params['path'] ?? '/'];
                break;
            case 'raw':
            case 'tcp':
                if (!empty($params['headerType']) && $params['headerType'] === 'http') {
                    $tcp = ['header' => ['type' => 'http']];
                    if (!empty($params['path'])) {
                        $tcp['header']['request'] = ['path' => explode(',', $params['path'])];
                    }
                    if (!empty($params['host'])) {
                        if (!isset($tcp['header']['request'])) $tcp['header']['request'] = [];
                        $tcp['header']['request']['headers'] = ['Host' => explode(',', $params['host'])];
                    }
                    $ss['tcpSettings'] = $tcp;
                }
                break;
        }

        return $ss;
    }
}
