<?php

namespace LinkRobins\Chirp\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Realtime\Websocket\Settings as RealtimeSettings;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Points flarum/realtime at the hosted Chirp websocket service, so the forum's
 * browser client AND php backend both talk to Chirp instead of a local bundled
 * daemon. The owner never edits any of this by hand.
 *
 * How: flarum/realtime's Settings holds nothing until use() is called, and it's
 * resolved fresh in several places (the forum API payload, the backend Pusher
 * client). An afterResolving hook only catches one of those resolutions, so we
 * instead BIND Settings as a singleton with the Chirp config already applied —
 * every consumer then gets the same configured instance regardless of order.
 *
 * Only wired when flarum-realtime is enabled (Conditional in extend.php), so the
 * RealtimeSettings reference here is always safe.
 */
class RealtimeConfigProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RealtimeSettings::class, function (Container $container) {
            $settings = new RealtimeSettings($container->make('flarum.config'));

            $repo   = $container->make(SettingsRepositoryInterface::class);
            $key    = $repo->get('linkrobins-chirp.app-key');
            $secret = $repo->get('linkrobins-chirp.app-secret');
            $host   = $repo->get('linkrobins-chirp.host');

            // Connected → override the client-facing keys with the Chirp values.
            // Everything else falls back to realtime's own defaults.
            if ($key && $secret && $host) {
                $settings->use([
                    'app-key'          => $key,
                    'app-secret'       => $secret,
                    // Browser connects here (wss://{host}:443).
                    'js-client-host'   => $host,
                    'js-client-port'   => 443,
                    'js-client-secure' => true,
                    // Backend Pusher trigger targets the same host over https.
                    'php-client-host'    => $host,
                    'php-client-port'    => 443,
                    'php-client-secure'  => true,
                    'php-client-timeout' => 5,
                ]);
            }

            @error_log('[chirp] SINGLETON built key=' . ($key ? substr($key, 0, 6) : 'null') . ' host=' . ($host ?: 'null') . "\n", 3, '/tmp/chirp2.log');

            return $settings;
        });
    }
}
