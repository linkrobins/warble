<?php

/*
 * Warble: realtime for Flarum over polling.
 */

namespace LinkRobins\Warble\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin page's checklist: admins only, and each answer comes from what
 * the forum can actually see.
 */
class HealthTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-realtime', 'linkrobins-warble');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    /** @return array<string, string> id => status */
    private function checks(): array
    {
        $response = $this->send($this->request('GET', '/api/warble/health', ['authenticatedAs' => 1]));
        $this->assertEquals(200, $response->getStatusCode());

        $checks = json_decode($response->getBody()->getContents(), true)['checks'];

        return array_column($checks, 'status', 'id');
    }

    /**
     * @test
     */
    #[Test]
    public function members_cannot_read_it()
    {
        $response = $this->send($this->request('GET', '/api/warble/health', ['authenticatedAs' => 2]));

        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * @test
     */
    #[Test]
    public function a_quiet_sync_forum_reports_what_it_can_see()
    {
        $checks = $this->checks();

        $this->assertSame('ok', $checks['realtime']);
        $this->assertSame('ok', $checks['queue_sync']);
        $this->assertSame('info', $checks['scheduler_not_needed']);
        $this->assertSame('info', $checks['polling_quiet']);
        $this->assertSame('info', $checks['updates_quiet']);
        $this->assertArrayNotHasKey('websocket_leftover', $checks);
    }

    /**
     * @test
     */
    #[Test]
    public function a_poll_and_a_broadcast_show_up()
    {
        $this->send($this->request('GET', '/api/warble/poll?channels=public'));
        $this->app()->getContainer()->make(\Pusher\Pusher::class)->trigger('public', 'probe', ['ok' => true]);

        $checks = $this->checks();

        $this->assertSame('ok', $checks['polling_seen']);
        $this->assertSame('ok', $checks['updates_seen']);
    }

    /**
     * @test
     */
    #[Test]
    public function a_leftover_websocket_block_is_pointed_out()
    {
        $this->config('websocket', ['key' => 'hosted-era-key']);

        $this->assertSame('info', $this->checks()['websocket_leftover']);
    }
}
