<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiControllerBase;

class ImportController extends ApiControllerBase
{
    public function parseAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        try {
            $content = trim($this->extractRequestData()['link']);
            if (preg_match('~^https://~i', $content)) {
                $content = $this->downloadSubscription($content);
            }
            return \OPNsense\Xray\Subscription::parse($content);
        } catch (\Throwable $error) {
            return ['status' => 'error', 'message' => $error->getMessage()];
        }
    }

    private function downloadSubscription(string $url): string
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        try {
            \OPNsense\Xray\HttpsDownload::fetch($url, $stream, 1048576);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }

    /**
     * Extracts subscription text from request body (transport Base64 or plain POST).
     * @return array{link: string}
     */
    private function extractRequestData(): array
    {
        $result = ['link' => ''];

        // JSON body ($.ajax contentType: application/json)
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $json = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (!empty($json['link_b64'])) {
                    $decoded = base64_decode($json['link_b64'], true);
                    if ($decoded !== false) {
                        $result['link'] = trim($decoded);
                    }
                } elseif (!empty($json['link'])) {
                    $result['link'] = trim($json['link']);
                }
                return $result;
            }
        }

        // POST param link_b64 (base64-safe, & in link is not a problem)
        $b64 = $this->request->getPost('link_b64', 'string', '');
        if (!empty($b64)) {
            $decoded = base64_decode($b64, true);
            if ($decoded !== false) {
                $result['link'] = trim($decoded);
            }
        }

        // Plain POST link
        if (empty($result['link'])) {
            $link = $this->request->getPost('link', 'string', '');
            if (!empty($link)) {
                $result['link'] = trim($link);
            }
        }

        return $result;
    }

}
