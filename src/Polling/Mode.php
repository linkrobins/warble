<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Polling;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Which transport this forum runs realtime over.
 *
 * `auto` (the default) means: a websocket block in config.php — a self-hosted
 * Reverb/soketi, or a connection written back when Warble was a hosted
 * service — keeps the socket path exactly as it was; without one, polling
 * turns on and realtime works with no server at all. The setting can force
 * either, for the admin who wants polling despite a configured socket (or
 * the reverse while debugging).
 */
class Mode
{
    public const SETTING = 'linkrobins-warble.transport';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Config $config
    ) {
    }

    public function polling(): bool
    {
        $forced = trim((string) $this->settings->get(self::SETTING, 'auto'));

        if ($forced === 'polling') {
            return true;
        }

        if ($forced === 'socket') {
            return false;
        }

        return trim((string) ($this->config['websocket']['key'] ?? '')) === '';
    }
}
