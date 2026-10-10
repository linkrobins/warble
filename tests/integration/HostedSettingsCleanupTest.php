<?php

/*
 * Warble: realtime for Flarum over polling.
 */

namespace LinkRobins\Warble\Tests\integration;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Upgrading removes what the hosted service, the transport switch and the
 * manual polling interval left in the settings table, the setup key above
 * all, and nothing else.
 */
class HostedSettingsCleanupTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-realtime', 'linkrobins-warble');

        $this->prepareDatabase([
            'settings' => [
                ['key' => 'linkrobins-warble.setup-token', 'value' => 'wbl_secret'],
                ['key' => 'linkrobins-warble.connected', 'value' => '1'],
                ['key' => 'linkrobins-warble.transport', 'value' => 'socket'],
                ['key' => 'linkrobins-warble.poll-interval', 'value' => '5'],
                ['key' => 'forum_title', 'value' => 'Kept'],
            ],
        ]);
    }

    /**
     * @test
     */
    #[Test]
    public function the_migration_removes_the_old_settings_and_nothing_else()
    {
        $this->app();

        $migration = require __DIR__.'/../../migrations/2026_10_10_000001_remove_hosted_service_settings.php';
        $migration['up']($this->database()->getSchemaBuilder());

        $keys = $this->database()->table('settings')->where('key', 'like', 'linkrobins-warble.%')->pluck('value', 'key')->all();

        $this->assertArrayNotHasKey('linkrobins-warble.setup-token', $keys);
        $this->assertArrayNotHasKey('linkrobins-warble.connected', $keys);
        $this->assertArrayNotHasKey('linkrobins-warble.transport', $keys);
        $this->assertArrayNotHasKey('linkrobins-warble.poll-interval', $keys);
        $this->assertSame('Kept', $this->database()->table('settings')->where('key', 'forum_title')->value('value'));
    }
}
