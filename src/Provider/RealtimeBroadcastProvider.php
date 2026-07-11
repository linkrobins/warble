<?php

/*
 * Warble — hosted realtime for Flarum.
 */

namespace LinkRobins\Warble\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Realtime\Push\Payload\Generator;
use LinkRobins\Warble\Push\SlimBroadcastGenerator;

/**
 * Swap flarum/realtime's broadcast payload Generator for the light one in
 * [[SlimBroadcastGenerator]]. flarum-warble requires flarum/realtime, so this
 * class always resolves; the container rebind wins because dependencies boot
 * before the extension that requires them. flarum/realtime resolves the
 * Generator fresh for every SendGeneratedPayloadJob, so the rebind takes effect
 * for every broadcast.
 */
class RealtimeBroadcastProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(Generator::class, SlimBroadcastGenerator::class);
    }
}
