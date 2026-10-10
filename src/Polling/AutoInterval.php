<?php

/*
 * Warble: realtime for Flarum over polling.
 */

namespace LinkRobins\Warble\Polling;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * How often browsers poll, chosen from what polling actually costs this host.
 *
 * Every poll records how long its whole request took, into one row per
 * minute (warble_load). From the last few minutes comes the load: seconds of
 * PHP time spent on polls per second of clock time, i.e. how much of one PHP
 * process polling keeps busy. Load scales with 1/interval, so the interval
 * that keeps it under BUDGET is the measured load times the interval it was
 * measured at, divided by the budget.
 *
 * Recomputed at most once a minute. It rises at once when load is too high
 * (protecting a struggling host is urgent) and falls one second per minute
 * when there is room, so one slow request or a quiet spell never makes it
 * swing. A forum that wants faster than polling can give runs realtime's own
 * websocket server instead; there is deliberately no manual override.
 */
class AutoInterval
{
    /** Seconds. 3 rather than 2 keeps each tab under 20 requests a minute, below the rate limits some shared hosts apply per visitor. */
    public const MIN = 3;

    public const MAX = 30;

    /** Until there is anything to measure. */
    public const START = 3;

    /** Share of one PHP process polling may keep busy. */
    public const BUDGET = 0.5;

    public const WINDOW_MINUTES = 5;

    protected const CACHE_KEY = 'linkrobins-warble.auto-interval';

    protected const RECOMPUTE_SECONDS = 60;

    public function __construct(
        protected ConnectionInterface $db,
        protected Cache $cache
    ) {
    }

    /** Add one poll's request time to this minute's row. */
    public function record(float $seconds): void
    {
        $minute = intdiv(time(), 60);
        $ms = max(0, (int) round($seconds * 1000));

        try {
            $bump = fn () => $this->db->table('warble_load')->where('minute', $minute)->update([
                'polls' => $this->db->raw('polls + 1'),
                'busy_ms' => $this->db->raw('busy_ms + '.$ms),
            ]);

            if ($bump() === 0) {
                try {
                    $this->db->table('warble_load')->insert(['minute' => $minute, 'polls' => 1, 'busy_ms' => $ms]);
                } catch (\Illuminate\Database\QueryException) {
                    // Another poll created the row first.
                    $bump();
                }
            }
        } catch (\Throwable) {
            // Measuring must never break a poll.
        }
    }

    /** The interval browsers should use now, in seconds. */
    public function current(): int
    {
        $state = $this->cache->get(self::CACHE_KEY);

        if (is_array($state) && ($state['at'] ?? 0) > time() - self::RECOMPUTE_SECONDS) {
            return (int) $state['interval'];
        }

        $previous = is_array($state) ? (int) $state['interval'] : self::START;
        $interval = $this->next($previous, $this->load());

        $this->cache->forever(self::CACHE_KEY, ['interval' => $interval, 'at' => time()]);
        $this->prune();

        return $interval;
    }

    /**
     * One step of the rule: rise straight to what the load needs, fall at
     * most one second at a time.
     */
    public function next(int $previous, ?float $load): int
    {
        $previous = max(self::MIN, min(self::MAX, $previous));

        if ($load === null) {
            return $previous;
        }

        $needed = (int) ceil($load * $previous / self::BUDGET);
        $target = max(self::MIN, min(self::MAX, $needed));

        if ($target > $previous) {
            return $target;
        }

        return $target < $previous ? $previous - 1 : $previous;
    }

    /**
     * Share of one PHP process kept busy by polls over the window, or null
     * when nobody polled.
     */
    public function load(): ?float
    {
        $stats = $this->stats();

        return $stats['polls'] > 0 ? $stats['busy_seconds'] / $stats['span_seconds'] : null;
    }

    /**
     * What the window holds, for the admin checklist too.
     *
     * @return array{polls: int, busy_seconds: float, span_seconds: int}
     */
    public function stats(): array
    {
        $now = intdiv(time(), 60);
        $from = $now - self::WINDOW_MINUTES + 1;

        $row = $this->db->table('warble_load')
            ->where('minute', '>=', $from)
            ->selectRaw('coalesce(sum(polls), 0) as polls, coalesce(sum(busy_ms), 0) as busy_ms, min(minute) as first')
            ->first();

        $polls = (int) ($row->polls ?? 0);
        $first = $row->first ?? null;

        // From the start of the oldest minute with data to now, so a
        // half-finished current minute is not read as a full one.
        $span = $first === null ? 60 : max(60, time() - ((int) $first * 60));

        return ['polls' => $polls, 'busy_seconds' => ((int) ($row->busy_ms ?? 0)) / 1000, 'span_seconds' => $span];
    }

    protected function prune(): void
    {
        try {
            $this->db->table('warble_load')->where('minute', '<', intdiv(time(), 60) - self::WINDOW_MINUTES * 2)->delete();
        } catch (\Throwable) {
        }
    }
}
