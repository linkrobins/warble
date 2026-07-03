<?php

namespace LinkRobins\Warble;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;

/**
 * Exchanges the customer's Warble setup key for connection config, by POSTing it
 * to the Warble service (srvup) /warble/config endpoint. The setup key is the auth
 * (a per-app secret), so this is a single authenticated call. Returns
 * ['app_id','key','secret','host','port','scheme'] or null on any failure.
 *
 * This call runs SYNCHRONOUSLY inside the admin's settings-save request, by
 * design: stock Flarum defaults to the `sync` queue driver, where a dispatched
 * job would run inline in the same request anyway — queueing buys nothing on a
 * default install and adds pending-state UX for a once-per-setup admin action.
 * Instead the worst case is capped hard (3s connect + 5s total) and every
 * failure path is fail-soft: the admin sees the disconnected banner, never an
 * error page.
 */
class WarbleClient
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log,
        protected Client $http,
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
            $response = $this->http->post($base . '/warble/config', [
                'form_params'     => ['token' => $token],
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 3,
                'timeout'         => 5,
                'http_errors'     => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->log->warning('Warble: config exchange failed', ['status' => $response->getStatusCode()]);
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);
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
