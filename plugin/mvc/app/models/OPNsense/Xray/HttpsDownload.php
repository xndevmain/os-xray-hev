<?php
namespace OPNsense\Xray;

/** HTTPS downloader for untrusted source URLs. Pins DNS and validates every redirect. */
final class HttpsDownload
{
    public static function fetch(string $url, $stream, int $limit, int $timeout = 20): void
    {
        $deadline = microtime(true) + $timeout;
        for ($redirect = 0; $redirect <= 5; $redirect++) {
            $parts = parse_url($url);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 8192
                || preg_match('/[\\s\\\\]/', $url)) {
                throw new \InvalidArgumentException('Source must be a valid HTTPS URL without credentials');
            }
            $host = trim($parts['host'], '[]');
            $port = $parts['port'] ?? 443;
            $literal = filter_var($host, FILTER_VALIDATE_IP);
            $ips = $literal ? [$host] : [];
            if (!$literal) {
                if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                    || !preg_match('/[a-z]/i', $host) || !str_contains($host, '.')) {
                    throw new \InvalidArgumentException('Invalid source hostname');
                }
                foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ip'])) $ips[] = $record['ip'];
                    if (isset($record['ipv6'])) $ips[] = $record['ipv6'];
                }
            }
            if (!$ips) throw new \RuntimeException('Source hostname could not be resolved');
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                    throw new \InvalidArgumentException('Source must resolve only to public IP addresses');
                }
            }
            $remaining = (int)ceil($deadline - microtime(true));
            if ($remaining <= 0) throw new \RuntimeException('Source download timed out');
            $received = 0;
            $location = '';
            ftruncate($stream, 0);
            rewind($stream);
            $handle = curl_init($url);
            $options = [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(5, $remaining), CURLOPT_TIMEOUT => $remaining,
                CURLOPT_USERAGENT => 'v2rayN', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$location) {
                    if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use ($stream, $limit, &$received) {
                    $received += strlen($chunk);
                    return $received > $limit ? 0 : fwrite($stream, $chunk);
                },
            ];
            if (!$literal) {
                $ip = $ips[0];
                $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
            }
            curl_setopt_array($handle, $options);
            $ok = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($handle);
            if (!$ok) throw new \RuntimeException($received > $limit ? 'Source exceeds size limit'
                : sprintf('Source download failed (curl %d)', $errno));
            if ($status === 200 && $received > 0) { rewind($stream); return; }
            if (!in_array($status, [301, 302, 303, 307, 308], true) || !$location) {
                throw new \RuntimeException(sprintf('Source download failed (HTTP %d)', $status));
            }
            if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
                $location = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $port : '') . $location;
            }
            $url = $location;
        }
        throw new \RuntimeException('Too many source redirects');
    }
}
