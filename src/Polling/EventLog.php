<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Polling;

use Illuminate\Database\ConnectionInterface;

/**
 * The polling wire: broadcasts and client events go in as rows, readers
 * collect them by id cursor. Plain query-builder throughout — rows are
 * ephemeral (RETENTION_SECONDS) and never joined, so Eloquent would be
 * ceremony.
 */
class EventLog
{
    /** Rows older than this are gone; late pollers catch up via refetch, not history. */
    public const RETENTION_SECONDS = 120;

    /** Cap per poll; a reader further behind than this reconnects instead. */
    public const MAX_EVENTS = 200;

    /** 1-in-N writes sweep expired rows, so no cron is needed. */
    protected const PRUNE_LOTTERY = 20;

    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    /**
     * @param array<int, string> $channels
     */
    public function write(array $channels, string $event, mixed $payload, ?int $userId = null, ?string $origin = null): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $rows = array_map(fn (string $channel) => [
            'channel' => mb_substr($channel, 0, 120),
            'event' => mb_substr($event, 0, 120),
            'payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES),
            'user_id' => $userId,
            'origin' => $origin,
            'created_at' => $now,
        ], $channels);

        if ($rows !== []) {
            $this->db->table('warble_events')->insert($rows);
        }

        if (random_int(1, self::PRUNE_LOTTERY) === 1) {
            $this->prune();
        }
    }

    public function latestId(): int
    {
        return (int) $this->db->table('warble_events')->max('id');
    }

    /**
     * Rows after the cursor on the given channels, oldest first, excluding
     * the caller's own (their `origin`) — Pusher never echoes a client event
     * back to the connection that sent it.
     *
     * @param array<int, string> $channels
     * @return array<int, object>
     */
    public function after(int $cursor, array $channels, ?string $origin): array
    {
        if ($channels === []) {
            return [];
        }

        $query = $this->db->table('warble_events')
            ->where('id', '>', $cursor)
            ->whereIn('channel', $channels)
            ->orderBy('id')
            ->limit(self::MAX_EVENTS);

        if ($origin !== null && $origin !== '') {
            $query->where(function ($q) use ($origin) {
                $q->whereNull('origin')->orWhere('origin', '!=', $origin);
            });
        }

        return $query->get()->all();
    }

    /** A channel with a poll this recent counts as occupied. */
    public const PRESENCE_SECONDS = 90;

    /**
     * Record that a poller is listening on these channels. One cross-database
     * upsert per poll; realtime's occupancy reads (via PollingPusher) answer
     * from this, deciding for example which users get notification payloads.
     *
     * @param array<int, string> $channels
     */
    public function touch(array $channels): void
    {
        if ($channels === []) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->db->table('warble_presence')->upsert(
            array_map(fn (string $c) => ['channel' => mb_substr($c, 0, 120), 'last_seen_at' => $now], $channels),
            ['channel'],
            ['last_seen_at']
        );
    }

    /**
     * Channels with a live poller, optionally filtered by prefix — the shape
     * of a Pusher getChannels answer.
     *
     * @return array<int, string>
     */
    public function occupied(string $prefix = ''): array
    {
        $query = $this->db->table('warble_presence')
            ->where('last_seen_at', '>=', gmdate('Y-m-d H:i:s', time() - self::PRESENCE_SECONDS));

        if ($prefix !== '') {
            $query->where('channel', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%');
        }

        return $query->pluck('channel')->all();
    }

    public function prune(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_SECONDS);

        $this->db->table('warble_events')->where('created_at', '<', $cutoff)->delete();

        $this->db->table('warble_presence')
            ->where('last_seen_at', '<', gmdate('Y-m-d H:i:s', time() - self::PRESENCE_SECONDS * 2))
            ->delete();
    }
}
