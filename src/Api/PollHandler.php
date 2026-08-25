<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Api;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Warble\Polling\ChannelGate;
use LinkRobins\Warble\Polling\EventLog;
use LinkRobins\Warble\Polling\Mode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /warble/poll — the polling transport's read side.
 *
 * ?channels=a,b,c&cursor=N&origin=xyz. Channels the actor may not read are
 * dropped silently (same outcome as a refused socket subscription: no
 * events, no error). Without a cursor the response is just the current head
 * plus the suggested interval — the caller starts listening from now, like
 * a socket that just connected.
 *
 * Typing events are stored as {time} plus the authenticated sender's id,
 * and the name is decided here, per reader, at read time: a disclosing
 * typist is named to everyone; a hidden one is named only to readers who
 * hold core's see-through permission (mirroring rc.6's identified channel)
 * and stays anonymous for the rest. The sender's client asserts nothing.
 */
class PollHandler implements RequestHandlerInterface
{
    public function __construct(
        protected Mode $mode,
        protected EventLog $log,
        protected ChannelGate $gate,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mode->polling()) {
            return new JsonResponse(['error' => 'polling_disabled'], 404);
        }

        $actor = RequestUtil::getActor($request);

        // getQueryParams() is only as populated as the server request was
        // built; fall back to the URI so the handler doesn't depend on which
        // stack constructed the request.
        $params = $request->getQueryParams();

        if ($params === [] && $request->getUri()->getQuery() !== '') {
            parse_str($request->getUri()->getQuery(), $params);
        }

        $channels = array_values(array_filter(
            array_map('trim', explode(',', (string) ($params['channels'] ?? ''))),
            fn (string $c) => $c !== '' && $this->gate->allows($actor, $c)
        ));

        $interval = max(2, min(30, (int) $this->settings->get('linkrobins-warble.poll-interval', 3)));

        // Every poll is a liveness signal: realtime's occupancy reads decide
        // from this which users are connected (see PollingPusher::getChannels).
        $this->log->touch($channels);

        if (!isset($params['cursor']) || !is_numeric($params['cursor'])) {
            return new JsonResponse([
                'cursor' => $this->log->latestId(),
                'interval' => $interval,
                'events' => [],
            ]);
        }

        $cursor = (int) $params['cursor'];
        $origin = mb_substr((string) ($params['origin'] ?? ''), 0, 32);

        $rows = $this->log->after($cursor, $channels, $origin);

        $events = [];
        $last = $cursor;

        foreach ($rows as $row) {
            $last = max($last, (int) $row->id);

            $data = $row->payload === null ? null : json_decode((string) $row->payload, true);

            if ($row->user_id !== null && str_starts_with((string) $row->event, 'client-')) {
                $data = $this->identify((array) $data, (int) $row->user_id, (string) $row->channel, $actor);

                if ($data === null) {
                    continue;
                }
            }

            $events[] = [
                'channel' => (string) $row->channel,
                'event' => (string) $row->event,
                'data' => $data,
            ];
        }

        // The cursor never lags the head: rows on other channels (or our own
        // origin) must not be re-scanned forever.
        if (count($rows) < EventLog::MAX_EVENTS) {
            $last = max($last, $this->log->latestId());
        }

        return new JsonResponse([
            'cursor' => $last,
            'interval' => $interval,
            'events' => $events,
        ]);
    }

    /**
     * The rc.6 identity contract, applied at read time. Returns null when the
     * event should be withheld from this reader entirely (a sender whose
     * account has vanished fails closed to anonymous, matching upstream).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    protected function identify(array $data, int $senderId, string $channel, User $actor): ?array
    {
        $sender = $this->sender($senderId);

        $disclose = $sender !== null && (bool) ($sender->getPreference('discloseOnline') ?? true);

        if ($disclose) {
            $data['displayName'] = $sender->display_name;
            $data['discloseOnline'] = true;

            return $data;
        }

        // Hidden: named only through the see-through permission, scoped the
        // way the identified channel is (per discussion).
        $seesThrough = preg_match('~^private-typing=(\d+)$~', $channel, $m)
            && $this->gate->allows($actor, 'private-typingIdentified='.$m[1]);

        $data['displayName'] = $seesThrough && $sender !== null ? $sender->display_name : null;
        $data['discloseOnline'] = false;

        return $data;
    }

    /** @var array<int, ?User> */
    protected array $senders = [];

    protected function sender(int $id): ?User
    {
        return $this->senders[$id] ??= User::query()->find($id);
    }
}
