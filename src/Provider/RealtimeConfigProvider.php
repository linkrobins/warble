<?php

namespace LinkRobins\Chirp\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Realtime\Websocket\Settings as RealtimeSettings;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Injects the resolved Chirp connection config into flarum/realtime's Settings
 * at runtime, so the forum's browser client AND php backend both talk to the
 * hosted Chirp websocket service instead of a local bundled daemon. The owner
 * never edits any of this by hand.
 *
 * flarum/realtime reads its config through Settings, which is populated via an
 * afterResolving `use()` hook. We register our own afterResolving that fires
 * (this extension loads after flarum-realtime) and overrides the client-facing
 * keys with the stored Chirp values. Only the js-client, php-client and app
 * keys are set; everything else falls back to realtime's own defaults.
 *
 * Only wired when flarum-realtime is enabled (Conditional in extend.php), so the
 * RealtimeSettings class reference here is always safe.
 */
class RealtimeConfigProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->afterResolving(RealtimeSettings::class, function (RealtimeSettings $settings, Container $container) {
            $repo = $container->make(SettingsRepositoryInterface::class);

            $key    = $repo->get('linkrobins-chirp.app-key');
            $secret = $repo->get('linkrobins-chirp.app-secret');
            $host   = $repo->get('linkrobins-chirp.host');

            error_log('[chirp] provider fired key=' . ($key ? substr($key, 0, 6) : 'null') . ' host=' . ($host ?: 'null') . ' secret=' . ($secret ? 'yes' : 'no'));

            // Not connected yet → leave realtime on its own defaults.
            if (!$key || !$secret || !$host) {
                return;
            }
            error_log('[chirp] injecting Chirp config into realtime host=' . $host);

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
        });
    }
}
