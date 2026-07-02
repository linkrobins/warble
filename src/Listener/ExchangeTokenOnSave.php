<?php

namespace LinkRobins\Chirp\Listener;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Chirp\ChirpClient;
use LinkRobins\Chirp\ConfigWriter;

/**
 * When the admin saves the Chirp setup key, exchange it for connection config
 * and write flarum/realtime's `websocket.*` block into config.php (via
 * ConfigWriter). This is the ONLY setup step — the owner never touches
 * flarum/realtime's own settings, and never runs a websocket daemon.
 *
 * Fail-soft: a bad key or an unwritable config.php just leaves 'connected'
 * false (and a flag the admin banner surfaces); it never 500s the settings save.
 *
 * The resolved app-secret lives ONLY in config.php, never in the settings table
 * — so it can never leak into the forum's serialized boot payload.
 */
class ExchangeTokenOnSave
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ChirpClient $client,
        protected ConfigWriter $config,
    ) {
    }

    public function handle(Saved $event): void
    {
        // Only react when THIS save touched the setup token.
        if (!array_key_exists('linkrobins-chirp.setup-token', $event->settings)) {
            return;
        }

        $token = trim((string) $event->settings['linkrobins-chirp.setup-token']);

        // Cleared → disconnect (strip the Chirp block from config.php).
        if ($token === '') {
            $this->config->disconnect();
            $this->settings->set('linkrobins-chirp.connected', false);
            $this->settings->delete('linkrobins-chirp.host');
            $this->settings->delete('linkrobins-chirp.config-write-failed');
            return;
        }

        $cfg = $this->client->fetchConfig($token);
        if (!$cfg) {
            $this->settings->set('linkrobins-chirp.connected', false);
            return;
        }

        $ok = $this->config->connect(
            $cfg['host'],
            $cfg['key'],
            $cfg['secret'],
            (int) ($cfg['port'] ?? 443),
            ($cfg['scheme'] ?? 'https') === 'https',
        );

        // host is a public wss endpoint (not secret) — keep it in settings so the
        // admin banner can show where the forum is connected. The secret does not
        // go here; it's only in config.php.
        $this->settings->set('linkrobins-chirp.host', $cfg['host']);
        $this->settings->set('linkrobins-chirp.connected', $ok);

        if ($ok) {
            $this->settings->delete('linkrobins-chirp.config-write-failed');
        } else {
            // config.php wasn't writable — the admin needs to fix perms (or paste
            // the block manually). The banner reads this flag.
            $this->settings->set('linkrobins-chirp.config-write-failed', true);
        }
    }
}
