<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Polling;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Pusher\Pusher;

/**
 * The Pusher client that never leaves the building.
 *
 * flarum/realtime pushes every broadcast through the `Pusher::class`
 * singleton (WebsocketProvider). In polling mode this stands in for it:
 * triggers become rows in warble_events, which browsers collect by cursor.
 * Extending the real client rather than duck-typing keeps every type-hint
 * in realtime satisfied without touching it.
 *
 * Only the trigger surface is rerouted — those are the only methods
 * realtime calls (SendGeneratedPayloadJob, BroadcastAssetsRevisionJob, the
 * websocket:info diagnostic). Anything else would go to the dummy
 * credentials below and fail, loudly, which is the correct behaviour for a
 * path that should not exist in polling mode.
 */
class PollingPusher extends Pusher
{
    public function __construct(
        protected EventLog $log
    ) {
        parent::__construct('warble-polling', 'warble-polling', '1', ['host' => '127.0.0.1']);
    }

    public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
    {
        $this->log->write(
            array_map('strval', (array) $channels),
            $event,
            $already_encoded && is_string($data) ? json_decode($data) : $data
        );

        return (object) ['channels' => (object) []];
    }

    public function triggerAsync($channels, string $event, $data, array $params = [], bool $already_encoded = false): PromiseInterface
    {
        return new FulfilledPromise($this->trigger($channels, $event, $data, $params, $already_encoded));
    }

    public function triggerBatch(array $batch = [], bool $already_encoded = false): object
    {
        foreach ($batch as $entry) {
            $this->trigger(
                $entry['channel'] ?? ($entry['channels'] ?? []),
                (string) ($entry['name'] ?? ''),
                $entry['data'] ?? null,
                [],
                $already_encoded
            );
        }

        return (object) [];
    }

    public function triggerBatchAsync(array $batch = [], bool $already_encoded = false): PromiseInterface
    {
        return new FulfilledPromise($this->triggerBatch($batch, $already_encoded));
    }

    /**
     * Occupancy, answered from poll activity instead of socket connections.
     * realtime's push jobs ask "which private-user= channels are connected?"
     * to decide who gets notification payloads; a channel polled within the
     * presence window is connected in every sense that matters here.
     *
     * @param array<string, mixed> $params
     */
    public function getChannels(array $params = []): object
    {
        $prefix = (string) ($params['filter_by_prefix'] ?? '');

        $channels = [];

        foreach ($this->log->occupied($prefix) as $name) {
            $channels[$name] = (object) [];
        }

        return (object) ['channels' => (object) $channels];
    }
}
