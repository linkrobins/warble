<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Polling;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * realtime 2.0's forum-wide feed of who is typing where, over polling.
 *
 * In socket mode realtime's bundled server relays every typing ping to the
 * `private-typing-activity` channel (TypingActivity), for holders of
 * `flarum-realtime.view-all-typing`; Flarum Deck's typing column reads it.
 * Polling's ingest runs inside Flarum, so the same relay happens here.
 *
 * Upstream sends each subscriber only what they could find out anyway, so
 * the feed is delivered per reader rather than broadcast. Polling does the
 * same at read time: the row stores who typed where (as far as the typist
 * can see), and {@see forReader()} decides what each reader is told:
 *
 *   - typing in a discussion the reader can't see is withheld;
 *   - a new discussion's tags are trimmed to the ones the reader can see;
 *   - a typist hiding their online status is named only to readers holding
 *     core's `user.viewLastSeenAt`;
 *   - nobody is shown their own typing.
 *
 * Private-message typing never comes here. Nothing is written while nobody
 * is polling the channel, so a forum without a watcher pays nothing.
 */
class TypingActivityFeed
{
    public const CHANNEL = 'private-typing-activity';
    public const EVENT = 'typing-activity';

    /** @var array<int, ?User> */
    protected array $users = [];

    /** @var array<string, bool> "reader:discussion" => visible */
    protected array $visible = [];

    public function __construct(
        protected EventLog $log,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /** Whether a reader may subscribe: AuthController::typingActivity. */
    public function allows(User $actor): bool
    {
        return !$actor->isGuest()
            && (bool) $this->settings->get('flarum-realtime.typing-indicator')
            && $actor->hasPermission('flarum-realtime.view-all-typing');
    }

    /** Someone is replying in discussion $discussionId. */
    public function discussion(User $typist, int $discussionId, mixed $time): void
    {
        if (!$this->watched()) {
            return;
        }

        $this->log->write([self::CHANNEL], self::EVENT, [
            'discussionId' => $discussionId,
            'tagIds' => null,
            'time' => is_scalar($time) ? $time : null,
        ], $typist->id);
    }

    /**
     * Someone is writing a new discussion in these tags. The claim is cut to
     * the tags the typist can see before it is stored; readers then see only
     * their own share of what is left.
     *
     * @param array<int, mixed> $claimedTagIds
     */
    public function newDiscussion(User $typist, array $claimedTagIds): void
    {
        if (!$this->watched()) {
            return;
        }

        $this->log->write([self::CHANNEL], self::EVENT, [
            'discussionId' => null,
            'tagIds' => $this->visibleTags($typist, $claimedTagIds),
            'time' => null,
        ], $typist->id);
    }

    /**
     * One stored row as this reader may see it, or null to withhold it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function forReader(array $data, int $typistId, User $reader): ?array
    {
        if ($reader->isGuest() || $reader->id === $typistId) {
            return null;
        }

        $typist = $this->user($typistId);

        // Unidentifiable: say nothing, rather than guess.
        if ($typist === null) {
            return null;
        }

        $discussionId = isset($data['discussionId']) ? (int) $data['discussionId'] : null;

        if ($discussionId !== null && !$this->canSee($reader, $discussionId)) {
            return null;
        }

        $tagIds = $discussionId === null ? $this->visibleTags($reader, (array) ($data['tagIds'] ?? [])) : null;

        $named = (bool) ($typist->getPreference('discloseOnline') ?? true)
            || $reader->hasPermission('user.viewLastSeenAt');

        return [
            'discussionId' => $discussionId,
            'tagIds' => $tagIds,
            'time' => $data['time'] ?? null,
            'userId' => $named ? $typist->id : null,
            'displayName' => $named ? $typist->display_name : null,
        ];
    }

    protected function watched(): bool
    {
        return (bool) $this->settings->get('flarum-realtime.typing-indicator')
            && $this->log->occupied(self::CHANNEL) !== [];
    }

    protected function canSee(User $reader, int $discussionId): bool
    {
        return $this->visible[$reader->id.':'.$discussionId] ??= Discussion::whereVisibleTo($reader)
            ->whereKey($discussionId)
            ->exists();
    }

    /**
     * @param array<int, mixed> $tagIds
     * @return int[]
     */
    protected function visibleTags(User $user, array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));

        if ($tagIds === [] || !class_exists(\Flarum\Tags\Tag::class)) {
            return [];
        }

        return \Flarum\Tags\Tag::whereVisibleTo($user)
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function user(int $id): ?User
    {
        return array_key_exists($id, $this->users) ? $this->users[$id] : ($this->users[$id] = User::query()->find($id));
    }
}
