<?php
require __DIR__ . '/../plugin/mvc/app/models/OPNsense/Xray/Subscription.php';
require __DIR__ . '/../plugin/scripts/Xray/xray-config.php';
use OPNsense\Xray\Subscription;
function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$uuid = '11111111-2222-4333-8444-555555555555';
$vless = 'vless://' . $uuid . '@127.0.0.1:443?security=none#VLESS';
$vmessData = ['v' => '2', 'ps' => 'VMess', 'add' => 'example.com', 'port' => '443', 'id' => $uuid, 'aid' => '0', 'net' => 'ws', 'host' => 'example.com', 'path' => '/ws', 'tls' => 'tls', 'sni' => 'example.com'];
$vmess = 'vmess://' . base64_encode(json_encode($vmessData));
$trojan = 'trojan://' . rawurlencode('p+ass:word@') . '@[2001:db8::1]:443?sni=example.com&type=grpc&serviceName=grpc#Trojan';
$ss = 'ss://' . rtrim(strtr(base64_encode('aes-128-gcm:p+:ass'), '+/', '-_'), '=') . '@example.com:8388#Shadowsocks';
$text = implode("\r\n", [$vless, $vmess, $trojan, $ss]);
$data = Subscription::parse("\xEF\xBB\xBF" . $text);
expect($data['count'] === 4, 'Mixed subscription count');
$outbounds = json_decode($data['outbound_config'], true);
expect(array_column($outbounds, 'protocol') === ['vless', 'vmess', 'trojan', 'shadowsocks'], 'All protocols preserved');
expect($outbounds[0]['settings']['vnext'][0]['users'][0]['flow'] === '', 'Do not invent VLESS Vision flow');
expect($outbounds[2]['settings']['servers'][0]['password'] === 'p+ass:word@', 'Trojan password URL decoding');
expect($outbounds[2]['settings']['servers'][0]['address'] === '2001:db8::1', 'IPv6 URI');
expect($outbounds[3]['settings']['servers'][0]['password'] === 'p+:ass', 'Shadowsocks password with colon and plus');
expect(Subscription::parse(chunk_split(base64_encode($text), 60))['count'] === 4, 'Wrapped Base64 subscription');
expect(Subscription::parse(rtrim(strtr(base64_encode($text), '+/', '-_'), '='))['count'] === 4, 'Base64URL subscription');
expect(Subscription::parse('ss://' . base64_encode('aes-128-gcm:pass@example.com:8388') . '#Legacy')['count'] === 1, 'Legacy SS URI');
expect(Subscription::parse(str_replace('vless://', 'VLESS://', $vless))['count'] === 1, 'Scheme case-insensitive');
expect(Subscription::parse('ss://2022-blake3-aes-128-gcm:' . rawurlencode(base64_encode(str_repeat('a', 16))) . '@example.com:8388')['count'] === 1, 'AEAD-2022 plain userinfo');
foreach (['%%%bad%%%', 'hysteria2://secret@example.com:443', str_repeat($vless . "\n", 33), $ss . '?plugin=obfs-local', 'trojan://@example.com:443'] as $bad) {
    try {
        // Put plugin before the fragment in the actual test URI.
        $bad = str_replace('#Shadowsocks?plugin=obfs-local', '?plugin=obfs-local#Shadowsocks', $bad);
        Subscription::parse($bad);
        throw new RuntimeException('Invalid subscription accepted');
    } catch (InvalidArgumentException $expected) {
        expect(!str_contains($expected->getMessage(), 'secret@'), 'Error must not expose credentials');
    }
}
if (!is_dir(__DIR__ . '/output')) mkdir(__DIR__ . '/output');
file_put_contents(__DIR__ . '/output/subscription.json', json_encode(xray_build_config(['outbound_config' => $data['outbound_config']]), JSON_PRETTY_PRINT));
echo "Subscription regression checks passed (4 protocols, text/Base64/IPv6/passwords)\n";

$jsonOutbound = json_decode(Subscription::parse($vless)['outbound_config'], true);
$jsonConfig = ['inbounds'=>[['protocol'=>'socks']], 'routing'=>['rules'=>[]], 'outbounds'=>[$jsonOutbound,['protocol'=>'freedom'],['protocol'=>'blackhole']]];
$jsonParsed = Subscription::parse(json_encode([$jsonConfig,$jsonConfig]));
expect($jsonParsed['count'] === 1, 'JSON configurations deduplicate proxy endpoints');
expect(!isset(json_decode($jsonParsed['outbound_config'],true)[0]['inbounds']), 'Do not import subscription inbounds');
expect(Subscription::parse(base64_encode(json_encode($jsonConfig)))['count'] === 1, 'Base64 JSON subscription');
$jsonConfig['outbounds'][0]['proxySettings'] = ['tag'=>'chain'];
try { Subscription::parse(json_encode($jsonConfig)); throw new RuntimeException('Proxy chain accepted'); }
catch (InvalidArgumentException $expected) {}

$named = ['remarks'=>'🇳🇱 Нидерланды', 'outbounds'=>[$jsonOutbound,['protocol'=>'freedom']]];
$namedResult = Subscription::parse(json_encode([$jsonConfig = ['outbounds'=>[$jsonOutbound]],$named]));
expect($namedResult['servers'] === ['🇳🇱 Нидерланды'], 'Prefer remarks and preserve Unicode names');
expect(Subscription::parse($vless)['servers'] === ['VLESS'], 'URI fragment remains the display name');
