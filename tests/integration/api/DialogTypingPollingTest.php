<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Warble\Api\PollHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * Typing in a private conversation (flarum/messages) over polling, the way
 * realtime 2.0 relays it: the payload is only the typist's id; a typist who
 * discloses their online status goes out on the conversation's channel, and
 * a hidden one only on the identified channel to members holding
 * user.viewLastSeenAt, and to no one else.
 */
class DialogTypingPollingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-realtime', 'flarum-messages', 'linkrobins-warble');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
                ['id' => 3, 'username' => 'hidden', 'email' => 'hidden@machine.local', 'password' => self::HASH, 'is_email_confirmed' => 1, 'preferences' => json_encode(['discloseOnline' => false])],
                ['id' => 4, 'username' => 'outsider', 'email' => 'outsider@machine.local', 'password' => self::HASH, 'is_email_confirmed' => 1],
            ],
            'dialogs' => [
                ['id' => 1, 'type' => 'direct', 'created_at' => '2026-01-01 00:00:00'],
                ['id' => 2, 'type' => 'direct', 'created_at' => '2026-01-01 00:00:00'],
            ],
            'dialog_user' => [
                ['dialog_id' => 1, 'user_id' => 2, 'joined_at' => '2026-01-01 00:00:00'],
                ['dialog_id' => 1, 'user_id' => 3, 'joined_at' => '2026-01-01 00:00:00'],
                ['dialog_id' => 2, 'user_id' => 1, 'joined_at' => '2026-01-01 00:00:00'],
                ['dialog_id' => 2, 'user_id' => 3, 'joined_at' => '2026-01-01 00:00:00'],
            ],
        ]);
    }

    /** @return array<int, array{channel: string, event: string, data: mixed}> */
    private function poll(int $actor, array $channels, ?int $cursor = 0): array
    {
        $query = 'channels='.implode(',', $channels).($cursor === null ? '' : "&cursor={$cursor}");

        $response = $this->send($this->request('GET', '/api/warble/poll?'.$query, ['authenticatedAs' => $actor]));

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode($response->getBody()->getContents(), true)['events'];
    }

    private function type(int $actor, int $dialog): int
    {
        return $this->send($this->request('POST', '/api/warble/event', [
            'authenticatedAs' => $actor,
            'json' => ['channel' => "private-privateMessageTyping={$dialog}", 'event' => 'client-typing', 'data' => []],
        ]))->getStatusCode();
    }

    /**
     * @test
     */
    #[Test]
    public function a_member_who_shows_they_are_online_is_sent_by_id_alone()
    {
        $this->assertEquals(204, $this->type(2, 1));

        $this->assertSame(
            [['channel' => 'private-privateMessageTyping=1', 'event' => 'client-typing', 'data' => ['userId' => 2]]],
            $this->poll(3, ['private-privateMessageTyping=1'])
        );
    }

    /**
     * @test
     */
    #[Test]
    public function a_hidden_typist_reaches_nobody_without_the_see_through_permission()
    {
        $this->assertEquals(204, $this->type(3, 1));

        $this->assertSame([], $this->poll(2, ['private-privateMessageTyping=1', 'private-privateMessageTypingIdentified=1']));
    }

    /**
     * @test
     */
    #[Test]
    public function a_hidden_typist_reaches_members_who_see_through_it_once_on_the_identified_channel()
    {
        $this->assertEquals(204, $this->type(3, 2));

        $this->assertSame(
            [['channel' => 'private-privateMessageTypingIdentified=2', 'event' => 'client-typing', 'data' => ['userId' => 3]]],
            $this->poll(1, ['private-privateMessageTyping=2', 'private-privateMessageTypingIdentified=2'])
        );

        $this->assertSame([], $this->poll(1, ['private-privateMessageTyping=2']), 'not on the plain channel');
    }

    /**
     * @test
     */
    #[Test]
    public function a_client_from_before_realtime_2_still_gets_the_payload_it_reads()
    {
        // flarum/messages rc.8 sends {displayName, discloseOnline, time} and
        // reads displayName and time back.
        $response = $this->send($this->request('POST', '/api/warble/event', [
            'authenticatedAs' => 2,
            'json' => ['channel' => 'private-privateMessageTyping=1', 'event' => 'client-typing', 'data' => ['displayName' => 'spoofed', 'discloseOnline' => true, 'time' => 1234]],
        ]));
        $this->assertEquals(204, $response->getStatusCode());

        $events = $this->poll(3, ['private-privateMessageTyping=1']);

        $this->assertCount(1, $events);
        $this->assertSame(1234, $events[0]['data']['time']);
        $this->assertSame('normal', $events[0]['data']['displayName'], 'named by the server, never by the client');
    }

    /**
     * @test
     */
    #[Test]
    public function only_members_may_listen_or_type()
    {
        $this->assertEquals(403, $this->type(4, 1));

        $this->assertEquals(204, $this->type(2, 1));
        $this->assertSame([], $this->poll(4, ['private-privateMessageTyping=1']));
    }

    /**
     * @test
     */
    #[Test]
    public function one_poll_holds_at_most_a_hundred_channels()
    {
        $names = array_map(fn ($i) => "unknown-{$i}", range(1, PollHandler::MAX_CHANNELS));
        $names[] = 'public';

        $this->poll(2, $names, null);

        $this->assertFalse(
            $this->database()->table('warble_presence')->where('channel', 'public')->exists(),
            'a channel past the cap is not listened to'
        );

        $this->poll(2, ['public'], null);
        $this->assertTrue($this->database()->table('warble_presence')->where('channel', 'public')->exists());
    }
}
