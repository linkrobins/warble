<?php

/*
 * Chirp — hosted realtime for Flarum.
 *
 * This extension does NOT implement realtime itself — flarum/realtime does all
 * the work (live discussions, typing, presence, notification push). Chirp's job
 * is to point flarum/realtime at the managed Chirp websocket service with a
 * single setup key, so the forum owner never runs a websocket daemon.
 *
 * Flow: admin pastes their Chirp setup key → ExchangeTokenOnSave swaps it (via
 * srvup /chirp/config) for {app-key, app-secret, host} and stores them → the
 * RealtimeConfigProvider injects those into flarum/realtime's Settings at
 * runtime (js-client-* + php-client-* + app-key/secret), overriding its
 * bundled-server defaults. Realtime-touching extenders are Conditional on
 * flarum-realtime being enabled, so this never breaks a forum without it.
 */

use Flarum\Extend;
use Flarum\Settings\Event\Saved;
use LinkRobins\Chirp\Listener\ExchangeTokenOnSave;
use LinkRobins\Chirp\Provider\RealtimeConfigProvider;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Default the service URL; the setup token + resolved creds are written at
    // runtime (never serialized to the forum — they're admin/server-only).
    (new Extend\Settings())
        ->default('linkrobins-chirp.service-url', 'https://linkrobins.com'),

    // Exchange the pasted setup key for connection config whenever it's saved.
    (new Extend\Event())
        ->listen(Saved::class, ExchangeTokenOnSave::class),

    // Only touch flarum/realtime when it's actually installed + enabled.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-realtime', fn () => [
            (new Extend\ServiceProvider())
                ->register(RealtimeConfigProvider::class),
        ]),
];
