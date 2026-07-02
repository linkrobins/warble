<?php

namespace LinkRobins\Chirp\Listener;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\ChirpClient;

/**
 * When the admin saves the Chirp setup key, exchange it for connection config
 * and store the resolved app-key/secret/host (which RealtimeConfigProvider then
 * feeds into flarum/realtime). This is the ONLY setup step — the owner never
 * touches flarum/realtime's own settings. Fail-soft: a bad key just leaves
 * 'connected' false; it never 500s the settings save.
 */
class ExchangeTokenOnSave
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ChirpClient $client,
    ) {
    }

    public function handle(Saved $event): void
    {
        // Only react when THIS save touched the setup token.
        if (!array_key_exists('linkrobins-chirp.setup-token', $event->settings)) {
            return;
        }

        $token = trim((string) $event->settings['linkrobins-chirp.setup-token']);

        // Cleared → disconnect (blank the derived config so realtime falls back).
        if ($token === '') {
            $this->settings->set('linkrobins-chirp.connected', false);
            $this->settings->delete('linkrobins-chirp.app-key');
            $this->settings->delete('linkrobins-chirp.app-secret');
            $this->settings->delete('linkrobins-chirp.host');
            return;
        }

        $cfg = $this->client->fetchConfig($token);
        if (!$cfg) {
            $this->settings->set('linkrobins-chirp.connected', false);
            return;
        }

        // Store the resolved connection config (server-side only — never
        // serialized to the forum; the secret must not reach the browser).
        $this->settings->set('linkrobins-chirp.app-key', $cfg['key']);
        $this->settings->set('linkrobins-chirp.app-secret', $cfg['secret']);
        $this->settings->set('linkrobins-chirp.host', $cfg['host']);
        $this->settings->set('linkrobins-chirp.connected', true);
    }
}
