<?php

namespace LinkRobins\Warble;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;

/**
 * Exchanges the customer's Warble setup key for connection config, by POSTing it
 * to the Warble service (srvup) /warble/config endpoint. The setup key is the auth
 * (a per-app secret), so this is a single authenticated call. Returns
 * ['app_id','key','secret','host','port','scheme'] or null on any failure.
 */
class WarbleClient
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log,
    ) {
    }

    public function fetchConfig(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $base = rtrim((string) ($this->settings->get('linkrobins-warble.service-url') ?: 'https://linkrobins.com'), '/');

        try {
            $ch = curl_init($base . '/warble/config');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['token' => $token]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status !== 200 || !$body) {
                $this->log->warning('Warble: config exchange failed', ['status' => $status]);
                return null;
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['key']) || empty($data['secret']) || empty($data['host'])) {
                return null;
            }

            return [
                'app_id' => (string) Arr::get($data, 'app_id', ''),
                'key'    => (string) $data['key'],
                'secret' => (string) $data['secret'],
                'host'   => (string) $data['host'],
                'port'   => (int) Arr::get($data, 'port', 443),
                'scheme' => (string) Arr::get($data, 'scheme', 'https'),
            ];
        } catch (\Throwable $e) {
            $this->log->warning('Warble: config exchange threw', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
