<?php

/*
 * This file is part of linkrobins/flarum-warble.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Warble\Tests\unit;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Warble\ConfigWriter;
use LinkRobins\Warble\Listener\ExchangeTokenOnSave;
use LinkRobins\Warble\WarbleClient;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;

class ExchangeTokenOnSaveTest extends MockeryTestCase
{
    private SettingsRepositoryInterface&m\MockInterface $settings;

    private WarbleClient&m\MockInterface $client;

    private ConfigWriter&m\MockInterface $config;

    private ExchangeTokenOnSave $listener;

    public function setUp(): void
    {
        parent::setUp();

        $this->settings = m::mock(SettingsRepositoryInterface::class);
        $this->client = m::mock(WarbleClient::class);
        $this->config = m::mock(ConfigWriter::class);

        $this->listener = new ExchangeTokenOnSave($this->settings, $this->client, $this->config);
    }

    #[Test]
    public function saves_that_do_not_touch_the_token_are_ignored(): void
    {
        $this->client->shouldNotReceive('fetchConfig');
        $this->config->shouldNotReceive('connect');
        $this->config->shouldNotReceive('disconnect');
        $this->settings->shouldNotReceive('set');

        $this->listener->handle(new Saved(['forum_title' => 'My forum']));
    }

    #[Test]
    public function clearing_the_token_disconnects_and_resets_state(): void
    {
        $this->config->shouldReceive('disconnect')->once();
        $this->settings->shouldReceive('set')->once()->with('linkrobins-warble.connected', '0');
        $this->settings->shouldReceive('delete')->once()->with('linkrobins-warble.host');
        $this->settings->shouldReceive('delete')->once()->with('linkrobins-warble.config-write-failed');
        $this->client->shouldNotReceive('fetchConfig');

        $this->listener->handle(new Saved(['linkrobins-warble.setup-token' => '  ']));
    }

    #[Test]
    public function a_failed_exchange_marks_disconnected_and_writes_nothing(): void
    {
        $this->client->shouldReceive('fetchConfig')->once()->with('BADKEY')->andReturnNull();
        $this->settings->shouldReceive('set')->once()->with('linkrobins-warble.connected', '0');
        $this->config->shouldNotReceive('connect');

        $this->listener->handle(new Saved(['linkrobins-warble.setup-token' => 'BADKEY']));
    }

    #[Test]
    public function a_successful_exchange_writes_config_and_marks_connected(): void
    {
        $this->client->shouldReceive('fetchConfig')->once()->with('GOODKEY')->andReturn([
            'key' => 'k', 'secret' => 's', 'host' => 'ws.linkrobins.com', 'port' => 443, 'scheme' => 'https',
        ]);
        $this->config->shouldReceive('connect')
            ->once()
            ->with('ws.linkrobins.com', 'k', 's', 443, true)
            ->andReturnTrue();

        $this->settings->shouldReceive('set')->once()->with('linkrobins-warble.host', 'ws.linkrobins.com');
        $this->settings->shouldReceive('set')->once()->with('linkrobins-warble.connected', '1');
        $this->settings->shouldReceive('delete')->once()->with('linkrobins-warble.config-write-failed');

        $this->listener->handle(new Saved(['linkrobins-warble.setup-token' => 'GOODKEY']));
    }

    #[Test]
    public function an_unwritable_config_php_sets_the_failure_flag(): void
    {
        $this->client->shouldReceive('fetchConfig')->once()->andReturn([
            'key' => 'k', 'secret' => 's', 'host' => 'ws.linkrobins.com', 'port' => 443, 'scheme' => 'https',
        ]);
        $this->config->shouldReceive('connect')->once()->andReturnFalse();

        $this->settings->shouldReceive('set')->once()->with('linkrobins-warble.host', 'ws.linkrobins.com');
        $this->settings->shouldReceive('set')->once()->with('linkrobins-warble.connected', '0');
        $this->settings->shouldReceive('set')->once()->with('linkrobins-warble.config-write-failed', '1');

        $this->listener->handle(new Saved(['linkrobins-warble.setup-token' => 'GOODKEY']));
    }
}
