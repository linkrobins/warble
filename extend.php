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
use LinkRobins\Warble\Frontend\FallbackScripts;
use LinkRobins\Warble\Listener\ExchangeTokenOnSave;
use LinkRobins\Warble\Middleware\HealAdminAssets;
use LinkRobins\Warble\Api\ClientEventHandler;
use LinkRobins\Warble\Api\PollHandler;
use LinkRobins\Warble\Polling\Mode;
use LinkRobins\Warble\Provider\PollingProvider;
use LinkRobins\Warble\Provider\RealtimeBroadcastProvider;

return [
    // The settings panel, plus server-rendered inline fallbacks that still
    // work when the compiled admin bundle is stale or broken (the "This
    // extension has no configuration" support cases): they detect that
    // Warble's module never registered, explain why in plain language, and
    // offer the one-click rebuild.
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->content(FallbackScripts::class),

    // Restores the typist's name in typing events on realtime >= 2.0.0-rc.6,
    // whose client stopped sending identity (its bundled websocket server
    // injects it; Warble, a Pusher-protocol relay, cannot). See js/src/forum.ts.
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js'),

    // Self-heal: if the served admin bundle provably predates Warble being
    // enabled (core's post-enable asset flush failed on this host), flush it
    // so this same page load recompiles it. No SSH, no user action.
    (new Extend\Middleware('admin'))
        ->add(HealAdminAssets::class),

    new Extend\Locales(__DIR__ . '/locale'),

    // Broadcast a LIGHT discussion payload (no full post stream) so realtime
    // stays under the server's request limit and is cheap enough to run inline
    // on the stock `sync` queue — no worker/redis/cron needed. See the provider.
    (new Extend\ServiceProvider())
        ->register(RealtimeBroadcastProvider::class)
        // In polling mode, realtime's Pusher singleton becomes a row writer;
        // in socket mode this registers nothing. See PollingProvider.
        ->register(PollingProvider::class),

    // The polling transport's wire: browsers read events by cursor and post
    // client events (typing) here. Both 404 in socket mode.
    (new Extend\Routes('api'))
        ->get('/warble/poll', 'warble.poll', PollHandler::class)
        ->post('/warble/event', 'warble.event', ClientEventHandler::class),

    // Tells the forum frontend which transport runs, so it knows whether to
    // stand the polling shim in for the socket client. Serialized for every
    // viewer; guests poll public channels too.
    (new Extend\ApiResource(Flarum\Api\Resource\ForumResource::class))
        ->fields(fn () => [
            Flarum\Api\Schema\Str::make('warbleTransport')
                ->get(fn () => resolve(Mode::class)->polling() ? 'polling' : 'socket'),
        ]),

    // Default the service URL; the setup token + resolved creds are written at
    // runtime (never serialized to the forum — they're admin/server-only).
    (new Extend\Settings())
        ->default('linkrobins-warble.service-url', 'https://linkrobins.com')
        // auto: polling unless config.php carries a websocket block (a
        // self-hosted socket, or a connection from the hosted era). Can be
        // forced to 'polling' or 'socket'.
        ->default('linkrobins-warble.transport', 'auto')
        // Seconds between polls while a tab is active; hidden tabs stop
        // entirely and idle ones stretch this out client-side.
        ->default('linkrobins-warble.poll-interval', 3),

    // Exchange the pasted setup key for connection config + write config.php
    // whenever it's saved.
    (new Extend\Event())
        ->listen(Saved::class, ExchangeTokenOnSave::class),
];
