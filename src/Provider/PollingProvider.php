<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use LinkRobins\Warble\Polling\Mode;
use LinkRobins\Warble\Polling\PollingPusher;
use Pusher\Pusher;

/**
 * In polling mode, stand PollingPusher in for the `Pusher::class` singleton
 * that flarum/realtime binds (WebsocketProvider) and pushes every broadcast
 * through — notifications, discussion updates, asset revisions all become
 * rows in warble_events instead of HTTP calls to a socket server that does
 * not exist. warble requires flarum/realtime, so realtime's binding always
 * exists to be overridden, and this provider registers after it.
 *
 * In socket mode this provider does nothing at all: the hosted-era and
 * bring-your-own configurations keep the real client untouched.
 */
class PollingProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend(Pusher::class, function ($pusher, $container) {
            return $container->make(Mode::class)->polling()
                ? $container->make(PollingPusher::class)
                : $pusher;
        });
    }
}
