<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use LinkRobins\Warble\Polling\ChannelGate;
use LinkRobins\Warble\Polling\EventLog;
use LinkRobins\Warble\Polling\IndexTypingFanout;
use LinkRobins\Warble\Polling\Mode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /warble/event — the polling transport's client-event ingest.
 *
 * Body: {channel, event, data, origin}. Only `client-*` events are accepted
 * (Pusher's rule), only from authenticated users, and only on channels the
 * sender could themselves read — the same bar a socket would set for
 * triggering. Nothing identifying in `data` is trusted or stored: the row
 * carries the authenticated sender's id, and PollHandler decides per reader
 * what to disclose. A payload is capped small because these are typing
 * pings, not documents.
 */
class ClientEventHandler implements RequestHandlerInterface
{
    protected const MAX_PAYLOAD_BYTES = 4096;

    public function __construct(
        protected Mode $mode,
        protected EventLog $log,
        protected ChannelGate $gate,
        protected IndexTypingFanout $indexTyping
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mode->polling()) {
            return new EmptyResponse(404);
        }

        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return new EmptyResponse(401);
        }

        $body = (array) $request->getParsedBody();

        $channel = (string) ($body['channel'] ?? '');
        $event = (string) ($body['event'] ?? '');
        $data = $body['data'] ?? null;
        $origin = mb_substr((string) ($body['origin'] ?? ''), 0, 32);

        if (!str_starts_with($event, 'client-') || $channel === '' || !$this->gate->allows($actor, $channel)) {
            return new EmptyResponse(403);
        }

        if (strlen(json_encode($data) ?: '') > self::MAX_PAYLOAD_BYTES) {
            return new EmptyResponse(413);
        }

        // Strip anything identity-shaped the client asserted; the reader-side
        // serialization is the only authority (see PollHandler::identify).
        if (is_array($data)) {
            unset($data['displayName'], $data['discloseOnline']);
        }

        $this->log->write([$channel], $event, $data, $actor->id, $origin !== '' ? $origin : null);

        // The list dots the bundled websocket server would fan out; polling's
        // ingest runs inside Flarum, so the same fan-out happens here.
        if ($event === 'client-typing' && preg_match('~^private-typing=(\d+)$~', $channel, $m)) {
            $this->indexTyping->discussionTyping((int) $m[1]);
        } elseif ($event === 'client-index-typing-tags' && preg_match('~^private-user=\d+$~', $channel)) {
            $tags = is_array($data) && is_array($data['tags'] ?? null) ? $data['tags'] : [];
            $this->indexTyping->composeTyping($actor, $tags);
        }

        return new EmptyResponse(204);
    }
}
