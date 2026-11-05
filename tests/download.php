<?php
require __DIR__ . '/../plugin/mvc/app/models/OPNsense/Xray/HttpsDownload.php';
use OPNsense\Xray\HttpsDownload;
foreach (['http://example.com', 'https://127.0.0.1', 'https://[::1]', 'https://10.0.0.1',
    'https://169.254.169.254', 'https://user:pass@example.com', 'https://example.com/a b',
    'https://example.com' . chr(92) . 'evil'] as $url) {
    $stream = fopen('php://temp', 'w+b');
    try {
        HttpsDownload::fetch($url, $stream, 1024);
        throw new RuntimeException('Accepted an invalid or private URL');
    } catch (InvalidArgumentException $expected) {
    } finally { fclose($stream); }
}
echo "Download URL regression checks passed\n";
