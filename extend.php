<?php

/*
 * Warble — hosted realtime for Flarum.
 *
 * This extension does NOT implement realtime itself — flarum/realtime does all
 * the work (live discussions, typing, presence, notification push). Warble's job
 * is to point flarum/realtime at the managed Warble websocket service with a
 * single setup key, so the forum owner never runs a websocket daemon.
 *
 * Flow: admin pastes their Warble setup key → ExchangeTokenOnSave swaps it (via
 * srvup /warble/config) for {app-key, app-secret, host} → ConfigWriter writes
 * flarum/realtime's `websocket.*` block into the forum's config.php, pointing
 * both the browser client and the php trigger at the hosted Warble service.
 * config.php is realtime's single source of truth (its Settings reads
 * config('websocket.*')), so there is no runtime container hook to get wrong and
 * nothing breaks on a forum that hasn't enabled flarum/realtime.
 */

use Flarum\Extend;
use Flarum\Settings\Event\Saved;
use LinkRobins\Warble\Listener\ExchangeTokenOnSave;
use LinkRobins\Warble\Provider\RealtimeBroadcastProvider;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Broadcast a LIGHT discussion payload (no full post stream) so realtime
    // stays under the server's request limit and is cheap enough to run inline
    // on the stock `sync` queue — no worker/redis/cron needed. See the provider.
    (new Extend\ServiceProvider())
        ->register(RealtimeBroadcastProvider::class),

    // Default the service URL; the setup token + resolved creds are written at
    // runtime (never serialized to the forum — they're admin/server-only).
    (new Extend\Settings())
        ->default('linkrobins-warble.service-url', 'https://linkrobins.com'),

    // Exchange the pasted setup key for connection config + write config.php
    // whenever it's saved.
    (new Extend\Event())
        ->listen(Saved::class, ExchangeTokenOnSave::class),
];
