<?php

/*
 * Warble: realtime for Flarum over polling.
 *
 * flarum/realtime does all the realtime work (live discussions, typing,
 * notifications). Warble replaces its transport: broadcasts are written to a
 * short-lived table and each browser collects them every few seconds, so a
 * forum needs no websocket server. A forum that wants websockets disables
 * Warble and runs realtime's own server instead.
 */

use Flarum\Extend;
use LinkRobins\Warble\Frontend\FallbackScripts;
use LinkRobins\Warble\Middleware\HealAdminAssets;
use LinkRobins\Warble\Api\ClientEventHandler;
use LinkRobins\Warble\Api\PollHandler;
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

    // Stands the polling client in for realtime's websocket. See js/src/forum.ts.
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js'),

    // Self-heal: if the served admin bundle provably predates Warble being
    // enabled (core's post-enable asset flush failed on this host), flush it
    // so this same page load recompiles it. No SSH, no user action.
    (new Extend\Middleware('admin'))
        ->add(HealAdminAssets::class),

    new Extend\Locales(__DIR__ . '/locale'),

    // Broadcast a LIGHT discussion payload (no full post stream), cheap enough
    // to write inline on the stock `sync` queue. See the provider.
    (new Extend\ServiceProvider())
        ->register(RealtimeBroadcastProvider::class)
        // realtime's Pusher singleton becomes a row writer. See PollingProvider.
        ->register(PollingProvider::class),

    // The polling transport's wire: browsers read events by cursor and post
    // client events (typing) here.
    (new Extend\Routes('api'))
        ->get('/warble/poll', 'warble.poll', PollHandler::class)
        ->post('/warble/event', 'warble.event', ClientEventHandler::class),

    // Seconds between polls while a tab is active; hidden tabs stop entirely
    // and idle ones stretch this out client-side.
    (new Extend\Settings())
        ->default('linkrobins-warble.poll-interval', 3),
];
