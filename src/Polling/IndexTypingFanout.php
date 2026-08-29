<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Polling;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Discussion-list typing dots over polling.
 *
 * In socket mode these are fanned out by realtime's bundled websocket server
 * (IndexTypingPresence): a typing ping in a discussion, or compose-typing in
 * a new discussion, becomes `index-typing` events on the list channels. A
 * protocol relay can't do that, and neither could polling v1 — but polling's
 * ingest runs inside Flarum, so the same fan-out can happen right here.
 *
 * The channel-resolution rules mirror IndexTypingPresence::resolveChannels
 * and broadcastTagTyping exactly, audience for audience: a guest-visible
 * discussion's dot goes to the public channel with all its tags; a restricted
 * discussion's dot goes only to each restricted tag's own channel, carrying
 * only that tag. Compose-typing claims are re-authorised against the sender
 * before anything is surfaced.
 *
 * No `typing: false` is ever sent: the receiving IndexTypingState expires a
 * dot on its own six seconds after the last event, so touches are all the
 * wire needs to carry. The sender's own pings are already throttled
 * client-side, which bounds the row rate.
 */
class IndexTypingFanout
{
    public const PUBLIC_CHANNEL = 'public-index-typing';

    public function __construct(
        protected EventLog $log,
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db
    ) {
    }

    /** A typing ping inside discussion $id: light the list dot for it. */
    public function discussionTyping(int $id): void
    {
        if (!$this->settings->get('flarum-realtime.index-typing-indicator')) {
            return;
        }

        foreach ($this->channelsFor($id) as $channel => $tagIds) {
            $this->log->write([$channel], 'index-typing', [
                'id' => $id,
                'typing' => true,
                'tags' => $tagIds,
            ]);
        }
    }

    /**
     * Compose-typing for a discussion that doesn't exist yet: the sender
     * claims tag ids; only the ones actually visible to them are surfaced,
     * each to the audience allowed to see that tag. `source` is a per-typer
     * dedup key, mirroring the bundled server's payload.
     *
     * @param array<int, mixed> $claimedTagIds
     */
    public function composeTyping(User $sender, array $claimedTagIds): void
    {
        if (!$this->settings->get('flarum-realtime.index-typing-indicator')
            || !class_exists(\Flarum\Tags\Tag::class)) {
            return;
        }

        $visible = \Flarum\Tags\Tag::whereVisibleTo($sender)
            ->whereIn('id', array_map('intval', $claimedTagIds))
            ->pluck('id');

        foreach ($visible as $tagId) {
            $channel = $this->tagIsGuestVisible((int) $tagId)
                ? self::PUBLIC_CHANNEL
                : 'private-index-typing-tag='.$tagId;

            $this->log->write([$channel], 'index-typing', [
                'source' => 'u'.$sender->id,
                'typing' => true,
                'tags' => [(int) $tagId],
            ]);
        }
    }

    /**
     * Channel → visible-tag-ids map for a discussion's dot. Mirrors
     * IndexTypingPresence::resolveChannels: public with all tags when the
     * discussion is guest-visible, otherwise one channel per restricted tag
     * (each disclosing only its own tag), and nothing at all when the
     * restricted variant is off.
     *
     * @return array<string, int[]>
     */
    protected function channelsFor(int $discussionId): array
    {
        if (Discussion::whereVisibleTo(new Guest())->where('id', $discussionId)->exists()) {
            $tagIds = class_exists(\Flarum\Tags\Tag::class)
                ? $this->tagIdsFor($discussionId, false)
                : [];

            return [self::PUBLIC_CHANNEL => $tagIds];
        }

        if (!$this->settings->get('flarum-realtime.index-typing-indicator-restricted')
            || !class_exists(\Flarum\Tags\Tag::class)) {
            return [];
        }

        $channels = [];

        foreach ($this->tagIdsFor($discussionId, true) as $tagId) {
            $channels['private-index-typing-tag='.$tagId] = [$tagId];
        }

        return $channels;
    }

    /** @return int[] */
    protected function tagIdsFor(int $discussionId, bool $restrictedOnly): array
    {
        $query = $this->db->table('discussion_tag')
            ->join('tags', 'tags.id', '=', 'discussion_tag.tag_id')
            ->where('discussion_tag.discussion_id', $discussionId);

        if ($restrictedOnly) {
            $query->where('tags.is_restricted', true);
        }

        return $query->pluck('tags.id')->map(fn ($id) => (int) $id)->all();
    }

    protected function tagIsGuestVisible(int $tagId): bool
    {
        return class_exists(\Flarum\Tags\Tag::class)
            && \Flarum\Tags\Tag::whereVisibleTo(new Guest())->where('id', $tagId)->exists();
    }
}
