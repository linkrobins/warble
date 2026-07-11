<?php

/*
 * Warble — hosted realtime for Flarum.
 */

namespace LinkRobins\Warble\Push;

use Flarum\Api\Client;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\Realtime\Push\Payload\Generator;
use Flarum\Realtime\Push\RealtimeRegistry;
use Flarum\User\Guest;
use Flarum\User\User;

/**
 * A drop-in replacement for flarum/realtime's payload Generator that broadcasts
 * a LIGHT discussion payload instead of the whole discussion.
 *
 * WHY: on every reply flarum/realtime serializes the ENTIRE discussion — a full
 * page of posts (with content), every participating user, and whatever the
 * viewer's permissions expose (fof-geoip per user, moderator fields, SEO meta) —
 * once PER connected recipient, then POSTs it to the realtime server. That
 * payload is unbounded (it grows with the discussion) and on a busy/admin
 * discussion overflows the server's request limit → 413. On the stock `sync`
 * queue driver (the shared-hosting default) that broadcast runs inline in the
 * reply request, so a slow or failed broadcast slows — and on overflow, breaks —
 * the reply itself. Getting off `sync` normally means a redis worker or a
 * database-queue cron, neither of which shared hosting can run comfortably.
 *
 * The fix is to send almost nothing. The flarum/realtime CLIENT already refetches
 * the discussion from the API with the viewer's own permissions before rendering
 * (forum/extend/Discussion/NewActivity.ts calls app.store.find('discussions', id)
 * on receipt); the discussion-LIST view only reads the discussion's own
 * attributes + tags/author to render a "new activity" row. So a discussion
 * serialized with a minimal include set — no post stream, no participant list —
 * is all either view needs. The payload becomes small and bounded, which:
 *   • removes the 413 entirely, and
 *   • makes the broadcast cheap enough to run inline on `sync` — so a plain
 *     shared-hosting forum needs no queue worker, redis, or cron.
 *
 * Visibility is unchanged: the discussion is still serialized THROUGH the API as
 * the recipient, so a discussion they can't see returns non-200 and is not
 * broadcast — the same guarantee the parent relied on. User/Notification
 * broadcasts are small and consumed directly by the client, so they are left to
 * the parent untouched. Any unexpected failure of the light path falls back to
 * the parent, so realtime can never be worse off than stock.
 */
class SlimBroadcastGenerator extends Generator
{
    public function __construct(
        private Client $api,
        private ExtensionManager $extensions,
        RealtimeRegistry $registry,
    ) {
        parent::__construct($api, $registry);
    }

    public function __invoke(AbstractModel $subject, ?User $recipient = null, ?array $includes = null): ?array
    {
        // Only discussion/post broadcasts carry the heavy whole-discussion
        // serialization; everything else stays exactly as flarum/realtime built it.
        if (! $subject instanceof Post && ! $subject instanceof Discussion) {
            return parent::__invoke($subject, $recipient, $includes);
        }

        $discussion = $subject instanceof Post ? $subject->discussion : $subject;
        $id = $discussion?->getAttribute('id');

        if ($id === null) {
            return null;
        }

        try {
            $response = $this->api
                ->withActor($recipient ?? new Guest)
                ->withQueryParams(['include' => $this->includes()])
                ->get("/discussions/$id");

            if ($response->getStatusCode() === 200) {
                $decoded = json_decode((string) $response->getBody(), true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            // Non-200 for a visible discussion is unexpected; for an invisible
            // one the parent returns null all the same. Either way, defer.
        } catch (\Throwable) {
            // Never let the optimisation break realtime — fall through to stock.
        }

        return parent::__invoke($subject, $recipient, $includes);
    }

    /**
     * The relationships the discussion-list client reads to render a row + filter
     * by tag. `tags` is only requested when flarum/tags is actually enabled, so
     * the include never 400s on a tag-less forum. The open-thread client needs
     * nothing beyond the id (it refetches), and everything here is small and
     * fixed-size regardless of how long the discussion is.
     */
    private function includes(): string
    {
        $relationships = ['user', 'lastPostedUser'];

        if ($this->extensions->isEnabled('flarum-tags')) {
            $relationships[] = 'tags';
        }

        return implode(',', $relationships);
    }
}
