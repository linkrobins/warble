<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Tests\integration;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Realtime\Push\Payload\Generator;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use LinkRobins\Warble\Push\SlimBroadcastGenerator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The light broadcast payload still carries the post an event is about, as
 * flarum/realtime's own does (Flarum Deck's post columns place it from there),
 * and still sends nothing about a post the recipient can't see.
 */
class SlimBroadcastGeneratorTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-realtime', 'linkrobins-warble');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Open', 'slug' => 'open', 'first_post_id' => 1, 'last_post_id' => 2, 'comment_count' => 2, 'user_id' => 1, 'created_at' => '2026-01-01 00:00:00', 'last_posted_at' => '2026-01-01 00:01:00'],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 1, 'type' => 'comment', 'number' => 1, 'created_at' => '2026-01-01 00:00:00', 'content' => '<r><p>first</p></r>'],
                ['id' => 2, 'discussion_id' => 1, 'user_id' => 1, 'type' => 'comment', 'number' => 2, 'created_at' => '2026-01-01 00:01:00', 'content' => '<r><p>reply</p></r>'],
                // Hidden: only moderators and its author can see it.
                ['id' => 3, 'discussion_id' => 1, 'user_id' => 1, 'type' => 'comment', 'number' => 3, 'created_at' => '2026-01-01 00:02:00', 'content' => '<r><p>hidden</p></r>', 'hidden_at' => '2026-01-01 00:03:00', 'hidden_user_id' => 1],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(string $model, int $id, ?int $recipientId): ?array
    {
        $container = $this->app()->getContainer();
        $generator = $container->make(Generator::class);

        $this->assertInstanceOf(SlimBroadcastGenerator::class, $generator);

        $subject = $model::query()->findOrFail($id);
        $recipient = $recipientId ? User::query()->findOrFail($recipientId) : null;

        return $generator($subject, $recipient);
    }

    #[Test]
    public function a_reply_carries_its_post_last_with_its_author(): void
    {
        $payload = $this->payload(Post::class, 2, 2);

        $this->assertSame(['type' => 'discussions', 'id' => '1'], ['type' => $payload['data']['type'], 'id' => $payload['data']['id']]);

        $last = end($payload['included']);
        $this->assertSame('posts', $last['type']);
        $this->assertSame('2', $last['id']);
        $this->assertSame('1', $last['relationships']['user']['data']['id']);

        // Its author arrives too, once.
        $users = array_filter($payload['included'], fn ($r) => $r['type'] === 'users' && $r['id'] === '1');
        $this->assertCount(1, $users);
    }

    #[Test]
    public function a_reply_to_a_guest_carries_its_post_too(): void
    {
        $payload = $this->payload(Post::class, 2, null);

        $last = end($payload['included']);
        $this->assertSame(['posts', '2'], [$last['type'], $last['id']]);
    }

    #[Test]
    public function nothing_is_sent_about_a_post_the_recipient_cannot_see(): void
    {
        $this->assertNull($this->payload(Post::class, 3, 2));
        $this->assertNull($this->payload(Post::class, 3, null));
    }

    #[Test]
    public function the_author_of_a_hidden_post_still_gets_it(): void
    {
        $payload = $this->payload(Post::class, 3, 1);

        $last = end($payload['included']);
        $this->assertSame(['posts', '3'], [$last['type'], $last['id']]);
    }

    #[Test]
    public function a_discussion_event_stays_light(): void
    {
        $payload = $this->payload(Discussion::class, 1, 2);

        $this->assertSame('1', $payload['data']['id']);
        $this->assertEmpty(array_filter($payload['included'] ?? [], fn ($r) => $r['type'] === 'posts'));
    }
}
