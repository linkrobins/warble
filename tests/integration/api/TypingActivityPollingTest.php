<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * realtime 2.0's forum-wide typing feed (`private-typing-activity`, read by
 * Flarum Deck's typing column) over polling, with the per-subscriber rules
 * of realtime's TypingActivity: only holders of view-all-typing subscribe,
 * typing in a discussion a reader can't see is withheld, a new discussion's
 * tags are trimmed per reader, a hidden typist is named only to readers with
 * user.viewLastSeenAt, and nobody is shown their own typing.
 */
class TypingActivityPollingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const FEED = 'private-typing-activity';

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-realtime', 'linkrobins-warble');

        $this->setting('flarum-realtime.typing-indicator', '1');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2: a member
                ['id' => 3, 'username' => 'hidden', 'email' => 'hidden@machine.local', 'password' => '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim', 'is_email_confirmed' => 1, 'preferences' => json_encode(['discloseOnline' => false])],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 4], // a moderator: sees the secret tag
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Open', 'slug' => 'open', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Secret', 'slug' => 'secret', 'position' => 1, 'is_restricted' => 1],
            ],
            'group_permission' => [
                ['group_id' => 3, 'permission' => 'flarum-realtime.view-all-typing'],
                ['group_id' => 4, 'permission' => 'tag2.viewForum'],
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Public thread', 'slug' => 'public-thread', 'first_post_id' => 1, 'last_post_id' => 1, 'comment_count' => 1, 'user_id' => 2, 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00'],
                ['id' => 2, 'title' => 'Secret thread', 'slug' => 'secret-thread', 'first_post_id' => 2, 'last_post_id' => 2, 'comment_count' => 1, 'user_id' => 1, 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:00:00'],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'content' => '<r><p>a</p></r>'],
                ['id' => 2, 'discussion_id' => 2, 'user_id' => 1, 'type' => 'comment', 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'content' => '<r><p>b</p></r>'],
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1],
                ['discussion_id' => 2, 'tag_id' => 2],
            ],
        ]);
    }

    /** @return array<int, array<string, mixed>> the feed's events this reader receives */
    private function feed(?int $actor, int $cursor = 0): array
    {
        $options = $actor === null ? [] : ['authenticatedAs' => $actor];
        $query = $cursor < 0 ? '' : "&cursor={$cursor}";

        $response = $this->send($this->request('GET', '/api/warble/poll?channels='.self::FEED.$query, $options));

        $this->assertEquals(200, $response->getStatusCode());

        $events = json_decode($response->getBody()->getContents(), true)['events'];

        return array_values(array_map(fn ($e) => $e['data'], array_filter($events, fn ($e) => $e['event'] === 'typing-activity')));
    }

    /** A first poll: the subscriber starts listening, as a socket would on connect. */
    private function watch(int $actor): void
    {
        $this->feed($actor, -1);
    }

    private function type(int $actor, string $channel, string $event, array $data): void
    {
        $response = $this->send($this->request('POST', '/api/warble/event', [
            'authenticatedAs' => $actor,
            'json' => ['channel' => $channel, 'event' => $event, 'data' => $data],
        ]));

        $this->assertEquals(204, $response->getStatusCode());
    }

    /**
     * @test
     */
    #[Test]
    public function a_watcher_sees_who_is_replying_where()
    {
        $this->watch(1);
        $this->type(2, 'private-typing=1', 'client-typing', ['time' => 1234]);

        $this->assertSame(
            [['discussionId' => 1, 'tagIds' => null, 'time' => 1234, 'userId' => 2, 'displayName' => 'normal']],
            $this->feed(1)
        );
    }

    /**
     * @test
     */
    #[Test]
    public function nobody_is_shown_their_own_typing()
    {
        $this->watch(2);
        $this->type(2, 'private-typing=1', 'client-typing', ['time' => 1]);

        $this->assertSame([], $this->feed(2));
    }

    /**
     * @test
     */
    #[Test]
    public function typing_in_a_discussion_the_reader_cannot_see_is_withheld()
    {
        $this->watch(2);
        $this->type(1, 'private-typing=2', 'client-typing', ['time' => 1]);

        $this->assertSame([], $this->feed(2), 'the member cannot see the secret thread');
        $this->assertCount(1, $this->feed(3), 'the moderator can');
    }

    /**
     * @test
     */
    #[Test]
    public function a_hidden_typist_is_named_only_to_those_who_see_through_it()
    {
        $this->watch(2);
        $this->type(3, 'private-typing=1', 'client-typing', ['time' => 1]);

        $member = $this->feed(2);
        $this->assertCount(1, $member);
        $this->assertNull($member[0]['userId']);
        $this->assertNull($member[0]['displayName']);

        $admin = $this->feed(1);
        $this->assertSame(3, $admin[0]['userId'], 'admins hold user.viewLastSeenAt');
        $this->assertSame('hidden', $admin[0]['displayName']);
    }

    /**
     * @test
     */
    #[Test]
    public function a_new_discussions_tags_are_trimmed_to_what_each_reader_can_see()
    {
        $this->watch(2);
        $this->type(3, 'private-user=3', 'client-index-typing-tags', ['tags' => [1, 2, 99]]);

        $this->assertSame([1], $this->feed(2)[0]['tagIds']);
        $this->assertSame([1, 2], $this->feed(1)[0]['tagIds']);
        $this->assertNull($this->feed(1)[0]['discussionId']);
    }

    /**
     * @test
     */
    #[Test]
    public function only_holders_of_the_permission_may_subscribe()
    {
        $this->watch(1);
        $this->type(2, 'private-typing=1', 'client-typing', ['time' => 1]);

        $this->assertSame([], $this->feed(null), 'guests');
    }

    /**
     * @test
     */
    #[Test]
    public function the_feed_is_off_while_the_typing_indicator_is()
    {
        $this->setting('flarum-realtime.typing-indicator', '');

        $this->watch(1);
        $this->type(2, 'private-typing=1', 'client-typing', ['time' => 1]);

        $this->assertSame([], $this->feed(1));
    }

    /**
     * @test
     */
    #[Test]
    public function nothing_is_recorded_while_nobody_watches()
    {
        $this->type(2, 'private-typing=1', 'client-typing', ['time' => 1]);

        $this->assertSame([], $this->feed(1));
    }
}
