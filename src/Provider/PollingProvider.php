<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use LinkRobins\Warble\Polling\PollingPusher;
use Pusher\Pusher;

/**
 * Stand PollingPusher in for the `Pusher::class` singleton
 * that flarum/realtime binds (WebsocketProvider) and pushes every broadcast
 * through — notifications, discussion updates, asset revisions all become
 * rows in warble_events instead of HTTP calls to a socket server that does
 * not exist. warble requires flarum/realtime, so realtime's binding always
 * exists to be overridden, and this provider registers after it.
 *
 * This happens even when config.php still carries a `websocket` block (a
 * connection left from the retired hosted service, say): with Warble enabled,
 * realtime always runs over polling. A forum that wants websockets disables
 * Warble and runs flarum/realtime's own server instead.
 */
class PollingProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend(Pusher::class, function ($pusher, $container) {
            return $container->make(PollingPusher::class);
        });
    }
}
