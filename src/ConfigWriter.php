<?php

namespace LinkRobins\Warble;

use Flarum\Foundation\Paths;
use Psr\Log\LoggerInterface;

/**
 * Points flarum/realtime at the hosted Warble service by writing the
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
 *
 * CONVENTION NOTE: this class deliberately uses native PHP filesystem functions
 * (file_put_contents/rename/chmod/opcache_invalidate) rather than a Filesystem
 * abstraction. config.php is a LOCAL bootstrap file, and the two properties the
 * whole design rests on — the atomic same-directory rename and the opcache
 * invalidation of a specific compiled path — have no equivalent in the
 * Flysystem-style abstractions (which target storage disks, not the app's own
 * boot files). Wrapping the rest in an abstraction while keeping these raw
 * would add indirection without portability.
 */
class ConfigWriter
{
    public function __construct(
        protected Paths $paths,
        protected LoggerInterface $log,
    ) {
    }

    protected function path(): string
    {
        return $this->paths->base . '/config.php';
    }

    /**
     * Write the Warble connection into config.php. Merges into any existing
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
     * Remove the Warble overrides (admin cleared the setup key). Realtime then
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
            $this->log->warning('Warble: config.php is not readable/writable', ['path' => $path]);
            return false;
        }

        $config = include $path;
        if (!is_array($config)) {
            $this->log->warning('Warble: config.php did not return an array', ['path' => $path]);
            return false;
        }

        $config = $fn($config);

        $php = '<?php return ' . var_export($config, true) . ';' . PHP_EOL;

        $tmp = $path . '.warble-' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            $this->log->warning('Warble: could not write temp config file', ['path' => $tmp]);
            return false;
        }

        // Match the original file's permissions before swapping it in.
        $perms = @fileperms($path);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }

        if (!@rename($tmp, $path)) {
            $this->log->warning('Warble: atomic rename over config.php failed', ['from' => $tmp, 'to' => $path]);
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
