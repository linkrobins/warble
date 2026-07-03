<?php

namespace LinkRobins\Warble\Listener;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Warble\WarbleClient;
use LinkRobins\Warble\ConfigWriter;

/**
 * When the admin saves the Warble setup key, exchange it for connection config
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
        protected WarbleClient $client,
        protected ConfigWriter $config,
    ) {
    }

    public function handle(Saved $event): void
    {
        // Only react when THIS save touched the setup token.
        if (!array_key_exists('linkrobins-warble.setup-token', $event->settings)) {
            return;
        }

        $token = trim((string) $event->settings['linkrobins-warble.setup-token']);

        // Cleared → disconnect (strip the Warble block from config.php).
        // Status flags are stored as '1'/'0' STRINGS: settings values round-trip
        // through the DB as strings (a PHP false becomes "0"), and "0" is truthy
        // in JS — the admin banner compares === '1'.
        if ($token === '') {
            $this->config->disconnect();
            $this->settings->set('linkrobins-warble.connected', '0');
            $this->settings->delete('linkrobins-warble.host');
            $this->settings->delete('linkrobins-warble.config-write-failed');
            return;
        }

        $cfg = $this->client->fetchConfig($token);
        if (!$cfg) {
            $this->settings->set('linkrobins-warble.connected', '0');
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
        $this->settings->set('linkrobins-warble.host', $cfg['host']);
        $this->settings->set('linkrobins-warble.connected', $ok ? '1' : '0');

        if ($ok) {
            $this->settings->delete('linkrobins-warble.config-write-failed');
        } else {
            // config.php wasn't writable — the admin needs to fix perms (or paste
            // the block manually). The banner reads this flag.
            $this->settings->set('linkrobins-warble.config-write-failed', '1');
        }
    }
}
