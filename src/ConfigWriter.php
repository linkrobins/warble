<?php

namespace LinkRobins\Chirp;

use Flarum\Foundation\Paths;

/**
 * Points flarum/realtime at the hosted Chirp service by writing the
 * `websocket.*` block into the forum's config.php.
 *
 * WHY config.php and not DB settings / a container binding: flarum/realtime
 * reads EVERY connection value from config('websocket.*') (see its
 * Websocket\Settings::defaults()). Its Settings object is rebuilt fresh in
 * several places — the browser boot payload (Websocket\Api\ForumAttributes) and
 * the backend Pusher trigger client — so a runtime container override only
 * catches some of them. config.php is the single source every consumer reads,
 * so writing it here is the ONLY reliable way to reach both the browser and the
 * php side. This mirrors exactly what Flarum's own installer does.
 *
 * A container restart is NOT required: after writing, we force opcache to drop
 * the cached config.php, so the next request recompiles it from disk.
 */
class ConfigWriter
{
    public function __construct(
        protected Paths $paths,
    ) {
    }

    protected function path(): string
    {
        return $this->paths->base . '/config.php';
    }

    /**
     * Write the Chirp connection into config.php. Merges into any existing
     * `websocket` block (so a self-hosted daemon's server-host/server-port are
     * left intact) and only overrides the client-facing keys. Returns true on a
     * successful write.
     */
    public function connect(string $host, string $key, string $secret, int $port = 443, bool $secure = true): bool
    {
        return $this->mutate(function (array $config) use ($host, $key, $secret, $port, $secure) {
            $config['websocket'] = array_merge($config['websocket'] ?? [], [
                'app-key'            => $key,
                'app-secret'         => $secret,
                // Browser (pusher-js) connects here.
                'js-client-host'     => $host,
                'js-client-port'     => $port,
                'js-client-secure'   => $secure,
                // Backend Pusher trigger targets the same host.
                'php-client-host'    => $host,
                'php-client-port'    => $port,
                'php-client-secure'  => $secure,
                'php-client-timeout' => 5,
            ]);

            return $config;
        });
    }

    /**
     * Remove the Chirp overrides (admin cleared the setup key). Realtime then
     * falls back to its own config-defaults.
     */
    public function disconnect(): bool
    {
        return $this->mutate(function (array $config) {
            foreach ([
                'app-key', 'app-secret',
                'js-client-host', 'js-client-port', 'js-client-secure',
                'php-client-host', 'php-client-port', 'php-client-secure', 'php-client-timeout',
            ] as $k) {
                unset($config['websocket'][$k]);
            }

            if (empty($config['websocket'])) {
                unset($config['websocket']);
            }

            return $config;
        });
    }

    /**
     * Read config.php, run $fn over the array, and write it back atomically.
     * A truncated config.php would kill the whole forum, so we write to a temp
     * file in the same directory and rename over the original (atomic on the
     * same filesystem), then invalidate opcache for the file.
     */
    protected function mutate(callable $fn): bool
    {
        $path = $this->path();
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            return false;
        }

        $config = include $path;
        if (!is_array($config)) {
            return false;
        }

        $config = $fn($config);

        $php = '<?php return ' . var_export($config, true) . ';' . PHP_EOL;

        $tmp = $path . '.chirp-' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            return false;
        }

        // Match the original file's permissions before swapping it in.
        $perms = @fileperms($path);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        // Force the next request to recompile config.php from disk even when
        // opcache.validate_timestamps is off — this is what removes the need
        // for a container/php-fpm restart.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }
}
