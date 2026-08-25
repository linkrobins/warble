<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Warble\Polling\EventLog;
use PHPUnit\Framework\Attributes\Test;
use Pusher\Pusher;

/**
 * The polling transport end to end inside one process: broadcasts written
 * through realtime's own Pusher binding come back out of the poll endpoint,
 * channels are gated per reader, and typing identity is decided server-side
 * per reader — the sender's client asserts nothing.
 */
class PollingTransportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-realtime', 'linkrobins-warble');

        $this->setting('flarum-realtime.typing-indicator', '1');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
                [
                    'id' => 3,
                    'username' => 'hidden_typist',
                    'password' => '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim',
                    'email' => 'hidden@machine.local',
                    'is_email_confirmed' => 1,
                    'preferences' => json_encode(['discloseOnline' => false]),
                ],
            ],
            'discussions' => [
                [
                    'id' => 1,
                    'title' => 'Somewhere to type',
                    'slug' => 'somewhere-to-type',
                    'first_post_id' => 1,
                    'last_post_id' => 1,
                    'comment_count' => 1,
                    'user_id' => 2,
                    'created_at' => '2026-01-01 00:00:00',
                    'last_posted_at' => '2026-01-01 00:00:00',
                ],
            ],
            'posts' => [
                [
                    'id' => 1,
                    'discussion_id' => 1,
                    'user_id' => 2,
                    'type' => 'comment',
                    'number' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'content' => '<r><p>hello</p></r>',
                ],
            ],
        ]);
    }

    private function poll(array $params, ?int $actor = null): array
    {
        $options = $actor === null ? [] : ['authenticatedAs' => $actor];

        $response = $this->send(
            $this->request('GET', '/api/warble/poll?'.http_build_query($params), $options)
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @test
     */
    #[Test]
    public function without_a_websocket_config_the_transport_is_polling_and_head_comes_back()
    {
        $first = $this->poll(['channels' => 'public']);

        $this->assertSame([], $first['events']);
        $this->assertIsInt($first['cursor']);
        $this->assertGreaterThanOrEqual(2, $first['interval']);
    }

    /**
     * @test
     */
    #[Test]
    public function a_broadcast_through_realtimes_pusher_binding_reaches_a_poller()
    {
        $head = $this->poll(['channels' => 'public'])['cursor'];

        // Exactly what realtime does: resolve Pusher::class and trigger.
        $this->app()->getContainer()->make(Pusher::class)
            ->trigger('public', 'fresh-assets', ['revision' => 'abc123']);

        $next = $this->poll(['channels' => 'public', 'cursor' => $head]);

        $this->assertCount(1, $next['events']);
        $this->assertSame('public', $next['events'][0]['channel']);
        $this->assertSame('fresh-assets', $next['events'][0]['event']);
        $this->assertSame(['revision' => 'abc123'], $next['events'][0]['data']);
        $this->assertGreaterThan($head, $next['cursor']);
    }

    /**
     * @test
     */
    #[Test]
    public function another_users_private_channel_is_silently_dropped()
    {
        $head = $this->poll(['channels' => 'public'], 2)['cursor'];

        $this->app()->getContainer()->make(Pusher::class)
            ->trigger('private-user=1', 'notification', ['secret' => true]);

        // User 2 asks for user 1's channel; the gate drops it, the event
        // never arrives, and the cursor still advances past it.
        $next = $this->poll(['channels' => 'public,private-user=1', 'cursor' => $head], 2);

        $this->assertSame([], $next['events']);
        $this->assertGreaterThan($head, $next['cursor']);
    }

    /**
     * @test
     */
    #[Test]
    public function guests_cannot_send_client_events()
    {
        $response = $this->send(
            $this->request('POST', '/api/warble/event', [
                'json' => ['channel' => 'private-typing=1', 'event' => 'client-typing', 'data' => ['time' => 1]],
            ])
        );

        // 400, not 401: a guest POST dies on CSRF before authentication is
        // even consulted. In a browser the shim's app.request carries the
        // token, and the handler's own guest check answers 401 behind it.
        $this->assertEquals(400, $response->getStatusCode());
    }

    private function sendTyping(int $actor, array $data, string $origin = 'senderorigin'): void
    {
        $response = $this->send(
            $this->request('POST', '/api/warble/event', [
                'authenticatedAs' => $actor,
                'json' => ['channel' => 'private-typing=1', 'event' => 'client-typing', 'data' => $data, 'origin' => $origin],
            ])
        );

        $this->assertEquals(204, $response->getStatusCode());
    }

    /**
     * @test
     */
    #[Test]
    public function typing_identity_is_resolved_server_side_and_client_claims_are_stripped()
    {
        $head = $this->poll(['channels' => 'private-typing=1'], 1)['cursor'];

        // The sender lies about who they are; the server does not care.
        $this->sendTyping(2, ['time' => 123, 'displayName' => 'Impostor', 'discloseOnline' => true]);

        $next = $this->poll(['channels' => 'private-typing=1', 'cursor' => $head, 'origin' => 'readerorigin'], 1);

        $this->assertCount(1, $next['events']);
        $data = $next['events'][0]['data'];

        $this->assertSame('normal', $data['displayName'], 'the authenticated identity, not the asserted one');
        $this->assertTrue($data['discloseOnline']);
        $this->assertSame(123, $data['time']);
    }

    /**
     * @test
     */
    #[Test]
    public function the_sender_is_not_echoed_their_own_event()
    {
        $head = $this->poll(['channels' => 'private-typing=1'], 2)['cursor'];

        $this->sendTyping(2, ['time' => 5], 'tab1');

        $same = $this->poll(['channels' => 'private-typing=1', 'cursor' => $head, 'origin' => 'tab1'], 2);
        $other = $this->poll(['channels' => 'private-typing=1', 'cursor' => $head, 'origin' => 'tab2'], 2);

        $this->assertSame([], $same['events'], 'own origin excluded');
        $this->assertCount(1, $other['events'], 'a second tab of the same user still hears it');
    }

    /**
     * @test
     */
    #[Test]
    public function a_hidden_typist_is_anonymous_to_members_and_named_to_the_permitted()
    {
        $head = $this->poll(['channels' => 'private-typing=1'], 1)['cursor'];

        $this->sendTyping(3, ['time' => 9]);

        $member = $this->poll(['channels' => 'private-typing=1', 'cursor' => $head, 'origin' => 'r1'], 2);
        $admin = $this->poll(['channels' => 'private-typing=1', 'cursor' => $head, 'origin' => 'r2'], 1);

        $this->assertNull($member['events'][0]['data']['displayName'], 'hidden stays hidden for a plain member');
        $this->assertFalse($member['events'][0]['data']['discloseOnline']);

        $this->assertSame('hidden_typist', $admin['events'][0]['data']['displayName'], 'admins hold user.viewLastSeenAt and see through');
        $this->assertFalse($admin['events'][0]['data']['discloseOnline']);
    }

    /**
     * @test
     */
    #[Test]
    public function forcing_socket_mode_turns_the_endpoints_off()
    {
        $this->setting('linkrobins-warble.transport', 'socket');

        $response = $this->send($this->request('GET', '/api/warble/poll?channels=public'));

        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * @test
     */
    #[Test]
    public function the_forum_payload_names_the_transport()
    {
        $response = $this->send($this->request('GET', '/api'));

        $data = json_decode($response->getBody()->getContents(), true);

        $this->assertSame('polling', $data['data']['attributes']['warbleTransport']);
    }

    /**
     * @test
     */
    #[Test]
    public function polling_counts_as_being_connected()
    {
        // realtime's push jobs ask the Pusher client which private-user=
        // channels are occupied to decide who gets notification payloads
        // (Push\Jobs\Job::connectedUsers). Before anyone polls, nobody is
        // connected; after user 2 polls, they are.
        $pusher = $this->app()->getContainer()->make(\Pusher\Pusher::class);

        $empty = $pusher->getChannels(['filter_by_prefix' => 'private-user=']);
        $this->assertSame([], (array) $empty->channels);

        $this->poll(['channels' => 'public,private-user=2'], 2);

        $after = $pusher->getChannels(['filter_by_prefix' => 'private-user=']);
        $this->assertArrayHasKey('private-user=2', (array) $after->channels);
        $this->assertArrayNotHasKey('public', (array) $after->channels, 'prefix filter respected');
    }

    /**
     * @test
     */
    #[Test]
    public function expired_rows_are_pruned()
    {
        $log = $this->app()->getContainer()->make(EventLog::class);

        $log->write(['public'], 'old-event', ['n' => 1]);

        $this->app()->getContainer()->make('db.connection')->table('warble_events')
            ->update(['created_at' => gmdate('Y-m-d H:i:s', time() - EventLog::RETENTION_SECONDS - 60)]);

        $log->prune();

        $this->assertSame(0, (int) $this->app()->getContainer()->make('db.connection')->table('warble_events')->count());
    }
}
