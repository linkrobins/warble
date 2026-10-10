<?php

/*
 * Warble: realtime for Flarum over polling.
 */

namespace LinkRobins\Warble\Tests\integration;

use Flarum\Testing\integration\TestCase;
use LinkRobins\Warble\Polling\AutoInterval;
use PHPUnit\Framework\Attributes\Test;

/**
 * The polling interval is picked from what polling costs the host: up at
 * once when it is too expensive, down a second at a time when there is room,
 * always between MIN and MAX.
 */
class AutoIntervalTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-realtime', 'linkrobins-warble');
    }

    private function auto(): AutoInterval
    {
        return $this->app()->getContainer()->make(AutoInterval::class);
    }

    /** Pretend the last few minutes held this much polling. */
    private function seed(int $polls, int $busyMs): void
    {
        $minute = intdiv(time(), 60);
        $this->database()->table('warble_load')->insert(['minute' => $minute - 4, 'polls' => $polls, 'busy_ms' => $busyMs]);
    }

    /**
     * @test
     */
    #[Test]
    public function the_rule_rises_at_once_and_falls_a_second_at_a_time()
    {
        $auto = $this->auto();

        // Nothing measured: stay put.
        $this->assertSame(AutoInterval::START, $auto->next(AutoInterval::START, null));
        // Almost no load at 10 s: one step down, not straight to the floor.
        $this->assertSame(9, $auto->next(10, 0.001));
        // Never below the floor.
        $this->assertSame(AutoInterval::MIN, $auto->next(AutoInterval::MIN, 0.0));
        // Load 0.4 at 3 s needs 0.4 * 3 / 0.5 = 2.4, rounded up to 3: hold.
        $this->assertSame(3, $auto->next(3, 0.4));
        // Load 2.0 at 3 s needs 12 s: jump straight there.
        $this->assertSame(12, $auto->next(3, 2.0));
        // Never above the ceiling.
        $this->assertSame(AutoInterval::MAX, $auto->next(10, 50.0));
    }

    /**
     * @test
     */
    #[Test]
    public function polls_are_measured_into_the_current_minute()
    {
        $this->send($this->request('GET', '/api/warble/poll?channels=public'));
        $this->send($this->request('GET', '/api/warble/poll?channels=public'));

        $row = $this->database()->table('warble_load')->where('minute', intdiv(time(), 60))->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->polls);
        $this->assertGreaterThanOrEqual(0, (int) $row->busy_ms);
    }

    /**
     * @test
     */
    #[Test]
    public function a_busy_host_gets_a_longer_interval_in_the_poll_response()
    {
        // 300 polls of 600 ms each in about five minutes: polling keeps
        // roughly 0.6 of a process busy at 3 s, so it needs about 4 s.
        $this->seed(300, 300 * 600);

        $response = $this->send($this->request('GET', '/api/warble/poll?channels=public'));
        $interval = json_decode($response->getBody()->getContents(), true)['interval'];

        $this->assertGreaterThan(AutoInterval::START, $interval);
        $this->assertLessThanOrEqual(AutoInterval::MAX, $interval);
    }

    /**
     * @test
     */
    #[Test]
    public function a_quiet_forum_keeps_the_fast_default()
    {
        $this->seed(20, 20 * 15);

        $response = $this->send($this->request('GET', '/api/warble/poll?channels=public'));

        $this->assertSame(AutoInterval::MIN, json_decode($response->getBody()->getContents(), true)['interval']);
    }

    /**
     * @test
     */
    #[Test]
    public function the_checklist_reports_the_choice_and_the_measurements()
    {
        $this->seed(300, 300 * 20);

        $response = $this->send($this->request('GET', '/api/warble/health', ['authenticatedAs' => 1]));
        $checks = array_column(json_decode($response->getBody()->getContents(), true)['checks'], null, 'id');

        $this->assertSame('ok', $checks['interval_auto']['status']);
        $this->assertSame(AutoInterval::MIN, $checks['interval_auto']['params']['seconds']);
        $this->assertSame(20, $checks['interval_auto']['params']['ms']);
    }
}
