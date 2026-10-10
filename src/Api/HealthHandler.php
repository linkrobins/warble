<?php

/*
 * Warble: realtime for Flarum over polling.
 */

namespace LinkRobins\Warble\Api;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ApplicationInfoProvider;
use Flarum\Foundation\Config;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Warble\Polling\EventLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/warble/health: the checklist on Warble's admin page.
 *
 * Each check is one thing realtime-over-polling depends on, answered from
 * what the forum can see right now: Realtime enabled, how the queue hands
 * updates over (the usual cause of "updates arrive minutes late"), whether
 * the scheduler runs when the queue needs it, and whether browsers are
 * polling and updates are being written. The page asks the poll endpoint
 * itself as well, which only the browser can.
 *
 * Statuses: ok, warn, fail, info. Admins only.
 */
class HealthHandler implements RequestHandlerInterface
{
    public function __construct(
        protected ExtensionManager $extensions,
        protected ApplicationInfoProvider $info,
        protected Cache $cache,
        protected ConnectionInterface $db,
        protected Config $config
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['checks' => [
            $this->realtime(),
            ...$this->queueAndScheduler(),
            $this->polling(),
            $this->updates(),
            ...$this->leftoverWebsocket(),
        ]]);
    }

    /** @return array{id: string, status: string, params?: array<string, string|int>} */
    protected function check(string $id, string $status, array $params = []): array
    {
        return ['id' => $id, 'status' => $status, 'params' => $params];
    }

    /** @return array{id: string, status: string, params?: array<string, string|int>} */
    protected function realtime(): array
    {
        return $this->extensions->isEnabled('flarum-realtime')
            ? $this->check('realtime', 'ok')
            : $this->check('realtime_missing', 'fail');
    }

    /**
     * realtime hands every update to Flarum's queue before Warble writes it.
     * `sync` runs it immediately; anything else waits for whatever processes
     * that queue, which is the scheduler on most shared hosts.
     *
     * @return list<array{id: string, status: string, params?: array<string, string|int>}>
     */
    protected function queueAndScheduler(): array
    {
        $driver = $this->info->identifyQueueDriver();

        if ($driver === 'sync') {
            return [$this->check('queue_sync', 'ok'), $this->check('scheduler_not_needed', 'info')];
        }

        $checks = [$this->check('queue_async', 'warn', ['driver' => $driver])];

        $lastRun = $this->cache->get('flarum:schedule:last_run');

        if (!$lastRun) {
            $checks[] = $this->check('scheduler_never', 'warn');
        } elseif (Carbon::parse($lastRun)->lt(Carbon::now()->subMinutes(5))) {
            $checks[] = $this->check('scheduler_stale', 'warn', ['at' => Carbon::parse($lastRun)->toIso8601String()]);
        } else {
            $checks[] = $this->check('scheduler_active', 'ok', ['at' => Carbon::parse($lastRun)->toIso8601String()]);
        }

        return $checks;
    }

    /** @return array{id: string, status: string, params?: array<string, string|int>} */
    protected function polling(): array
    {
        $last = $this->db->table('warble_presence')->max('last_seen_at');

        return $last
            ? $this->check('polling_seen', 'ok', ['at' => Carbon::parse($last)->toIso8601String()])
            : $this->check('polling_quiet', 'info', ['minutes' => intdiv(EventLog::RETENTION_SECONDS, 60)]);
    }

    /** @return array{id: string, status: string, params?: array<string, string|int>} */
    protected function updates(): array
    {
        $last = $this->db->table('warble_events')->max('created_at');

        return $last
            ? $this->check('updates_seen', 'ok', ['at' => Carbon::parse($last)->toIso8601String()])
            : $this->check('updates_quiet', 'info', ['minutes' => intdiv(EventLog::RETENTION_SECONDS, 60)]);
    }

    /**
     * A `websocket` block left in config.php (from the retired hosted
     * service) is ignored while Warble is enabled. Worth saying, because it
     * would point realtime at a dead server if Warble were disabled.
     *
     * @return list<array{id: string, status: string, params?: array<string, string|int>}>
     */
    protected function leftoverWebsocket(): array
    {
        $block = $this->config['websocket'] ?? null;

        return is_array($block) && trim((string) ($block['key'] ?? '')) !== ''
            ? [$this->check('websocket_leftover', 'info')]
            : [];
    }
}
