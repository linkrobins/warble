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
 *
 * KNOWN LIMITATION (verified 2026-07-02): this container-level override does NOT
 * reliably reach flarum/realtime's ForumAttributes (the browser boot payload) —
 * realtime is fundamentally config.php-driven (Settings::defaults() reads
 * config('websocket.*')). The reliable fix is to write the websocket.* block
 * into the forum's config.php on setup-token save (+ a restart to pick it up).
 * TODO: rework ExchangeTokenOnSave to write config.php instead of DB settings.
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

            return $settings;
        });
    }
}
