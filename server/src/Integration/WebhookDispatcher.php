<?php

declare(strict_types=1);

namespace MuchoCore\Integration;

use MuchoCore\Core\Environment;

final class WebhookDispatcher
{
    public function enabledFor(string $event): bool
    {
        $url = trim((string)(
            Environment::get('MUCHO_WEBHOOK_URL', '')
        ));

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $raw = trim((string)(
            Environment::get('MUCHO_WEBHOOK_EVENTS', '*')
        ));

        if ($raw === '' || $raw === '*') {
            return true;
        }

        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $allowed) {
            if ($allowed === $event) {
                return true;
            }
        }

        return false;
    }

    public function dispatch(
        string $event,
        array $data,
        string $requestId = ''
    ): bool {
        if (!$this->enabledFor($event)) {
            return true;
        }

        $url = trim((string)(Environment::get('MUCHO_WEBHOOK_URL', '') ?? ''));
        $secret = (string)(
            Environment::get('MUCHO_WEBHOOK_SECRET', '')
        );

        $payload = json_encode([
            'event' => $event,
            'request_id' => $requestId,
            'sent_at' => gmdate('c'),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MuchoCore-Webhook/1.0',
            'X-MuchoCore-Event: ' . $event,
        ];

        if ($requestId !== '') {
            $headers[] = 'X-MuchoCore-Request-Id: ' . $requestId;
        }

        if ($secret !== '') {
            $headers[] = 'X-MuchoCore-Signature: sha256=' .
                hash_hmac('sha256', $payload, $secret);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $payload,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $status = (string)($http_response_header[0] ?? '');

        return $result !== false &&
            preg_match('/\s2\d\d\s/', $status) === 1;
    }
}
