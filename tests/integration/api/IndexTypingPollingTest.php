<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Discussion-list typing dots over polling: typing in a discussion (or
 * composing a new one) surfaces `index-typing` events on the list channels,
 * with the same audiencing the bundled websocket server applies — public
 * dots carry a guest-visible discussion's tags, restricted discussions light
 * only their own restricted-tag channels, and compose-typing claims are
 * re-authorised against the sender.
 */
class IndexTypingPollingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-realtime', 'linkrobins-warble');

        $this->setting('flarum-realtime.typing-indicator', '1');
        $this->setting('flarum-realtime.index-typing-indicator', '1');
        $this->setting('flarum-realtime.index-typing-indicator-restricted', '1');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Open', 'slug' => 'open', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Secret', 'slug' => 'secret', 'position' => 1, 'is_restricted' => 1],
            ],
            'group_permission' => [
                // Admins see everything; user 2 gets the secret tag via a
                // bespoke group so the restricted audiencing is testable.
                ['group_id' => 4, 'permission' => 'tag2.viewForum'],
            ],
            'discussions' => [
                [
                    'id' => 1,
                    'title' => 'Public thread',
                    'slug' => 'public-thread',
                    'first_post_id' => 1,
                    'last_post_id' => 1,
                    'comment_count' => 1,
                    'user_id' => 2,
                    'created_at' => '2026-01-01 00:00:00',
                    'last_posted_at' => '2026-01-01 00:00:00',
                ],
                [
                    'id' => 2,
                    'title' => 'Secret thread',
                    'slug' => 'secret-thread',
                    'first_post_id' => 2,
                    'last_post_id' => 2,
                    'comment_count' => 1,
                    'user_id' => 1,
                    'is_private' => 0,
                    'created_at' => '2026-01-01 00:00:00',
                    'last_posted_at' => '2026-01-01 00:00:00',
                ],
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

    private function poll(string $channels, ?int $actor, int $cursor = 0): array
    {
        $options = $actor === null ? [] : ['authenticatedAs' => $actor];

        $response = $this->send(
            $this->request('GET', "/api/warble/poll?channels={$channels}&cursor={$cursor}", $options)
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode($response->getBody()->getContents(), true)['events'];
    }

    private function sendEvent(int $actor, string $channel, string $event, array $data): int
    {
        $response = $this->send(
            $this->request('POST', '/api/warble/event', [
                'authenticatedAs' => $actor,
                'json' => ['channel' => $channel, 'event' => $event, 'data' => $data],
            ])
        );

        return $response->getStatusCode();
    }

    /**
     * @test
     */
    #[Test]
    public function typing_in_a_public_discussion_lights_the_public_list_dot_for_guests()
    {
        $this->assertEquals(204, $this->sendEvent(2, 'private-typing=1', 'client-typing', ['time' => 1]));

        $events = $this->poll('public-index-typing', null);
        $dots = array_values(array_filter($events, fn ($e) => $e['event'] === 'index-typing'));

        $this->assertCount(1, $dots);
        $this->assertSame(1, $dots[0]['data']['id']);
        $this->assertTrue($dots[0]['data']['typing']);
        $this->assertSame([1], $dots[0]['data']['tags'], "the discussion's guest-visible tag lights up");
    }

    /**
     * @test
     */
    #[Test]
    public function a_restricted_discussions_dot_goes_only_to_its_tag_channel()
    {
        $this->assertEquals(204, $this->sendEvent(1, 'private-typing=2', 'client-typing', ['time' => 1]));

        // Nothing on the public channel…
        $public = array_filter($this->poll('public-index-typing', null), fn ($e) => $e['event'] === 'index-typing');
        $this->assertCount(0, $public, 'a restricted discussion never lights the public dot');

        // …and the tag channel is refused to those without the tag, served to
        // those with it (admin).
        $member = array_filter($this->poll('private-index-typing-tag=2', 2), fn ($e) => $e['event'] === 'index-typing');
        $this->assertCount(0, $member, 'no tag access, no channel');

        $admin = array_values(array_filter($this->poll('private-index-typing-tag=2', 1), fn ($e) => $e['event'] === 'index-typing'));
        $this->assertCount(1, $admin);
        $this->assertSame(2, $admin[0]['data']['id']);
        $this->assertSame([2], $admin[0]['data']['tags']);
    }

    /**
     * @test
     */
    #[Test]
    public function compose_typing_claims_are_reauthorised_against_the_sender()
    {
        // User 2 claims both tags but can only see the open one.
        $this->assertEquals(204, $this->sendEvent(2, 'private-user=2', 'client-index-typing-tags', ['tags' => [1, 2]]));

        $public = array_values(array_filter($this->poll('public-index-typing', null), fn ($e) => $e['event'] === 'index-typing'));
        $this->assertCount(1, $public, 'only the visible tag surfaced');
        $this->assertSame([1], $public[0]['data']['tags']);
        $this->assertSame('u2', $public[0]['data']['source']);

        $restricted = array_filter($this->poll('private-index-typing-tag=2', 1), fn ($e) => $e['event'] === 'index-typing');
        $this->assertCount(0, $restricted, 'the unauthorised claim never reaches the restricted channel');
    }

    /**
     * @test
     */
    #[Test]
    public function the_setting_gates_the_fanout()
    {
        $this->setting('flarum-realtime.index-typing-indicator', '0');

        $this->assertEquals(204, $this->sendEvent(2, 'private-typing=1', 'client-typing', ['time' => 1]));

        $events = array_filter($this->poll('public-index-typing', null), fn ($e) => $e['event'] === 'index-typing');
        $this->assertCount(0, $events);
    }
}
