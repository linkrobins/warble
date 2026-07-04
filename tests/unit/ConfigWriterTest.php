<?php

/*
 * This file is part of linkrobins/flarum-warble.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Warble\Tests\unit;

use Flarum\Foundation\Paths;
use LinkRobins\Warble\ConfigWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ConfigWriterTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/warble-config-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    private function writer(): ConfigWriter
    {
        return new ConfigWriter(
            new Paths(['base' => $this->dir, 'public' => $this->dir, 'storage' => $this->dir]),
            new NullLogger()
        );
    }

    private function seedConfig(array $config): void
    {
        file_put_contents($this->dir . '/config.php', '<?php return ' . var_export($config, true) . ';' . PHP_EOL);
    }

    private function readConfig(): array
    {
        return include $this->dir . '/config.php';
    }

    #[Test]
    public function connect_writes_the_websocket_block_and_preserves_the_rest(): void
    {
        $this->seedConfig(['debug' => false, 'url' => 'https://forum.example']);

        $ok = $this->writer()->connect('ws.linkrobins.com', 'app-key', 'app-secret');

        $this->assertTrue($ok);
        $config = $this->readConfig();
        $this->assertFalse($config['debug']);
        $this->assertEquals('https://forum.example', $config['url']);
        $this->assertEquals('app-key', $config['websocket']['app-key']);
        $this->assertEquals('ws.linkrobins.com', $config['websocket']['js-client-host']);
        $this->assertEquals('ws.linkrobins.com', $config['websocket']['php-client-host']);
        $this->assertEquals(443, $config['websocket']['js-client-port']);
        $this->assertTrue($config['websocket']['js-client-secure']);
    }

    #[Test]
    public function connect_merges_over_an_existing_websocket_block(): void
    {
        // A self-hosted daemon's server-side keys must survive the takeover of
        // the client-facing connection.
        $this->seedConfig(['websocket' => ['server-host' => '127.0.0.1', 'server-port' => 6001]]);

        $this->writer()->connect('ws.linkrobins.com', 'k', 's');

        $config = $this->readConfig();
        $this->assertEquals('127.0.0.1', $config['websocket']['server-host']);
        $this->assertEquals(6001, $config['websocket']['server-port']);
        $this->assertEquals('ws.linkrobins.com', $config['websocket']['js-client-host']);
    }

    #[Test]
    public function disconnect_strips_only_the_warble_keys(): void
    {
        $this->seedConfig(['websocket' => ['server-host' => '127.0.0.1']]);
        $this->writer()->connect('ws.linkrobins.com', 'k', 's');

        $ok = $this->writer()->disconnect();

        $this->assertTrue($ok);
        $config = $this->readConfig();
        $this->assertArrayNotHasKey('app-key', $config['websocket']);
        $this->assertArrayNotHasKey('js-client-host', $config['websocket']);
        $this->assertEquals('127.0.0.1', $config['websocket']['server-host']);
    }

    #[Test]
    public function disconnect_removes_an_emptied_websocket_block_entirely(): void
    {
        $this->seedConfig(['debug' => false]);
        $this->writer()->connect('ws.linkrobins.com', 'k', 's');

        $this->writer()->disconnect();

        $this->assertArrayNotHasKey('websocket', $this->readConfig());
    }

    #[Test]
    public function a_missing_config_file_fails_closed(): void
    {
        $this->assertFalse($this->writer()->connect('ws.linkrobins.com', 'k', 's'));
    }

    #[Test]
    public function a_config_file_that_does_not_return_an_array_fails_closed(): void
    {
        file_put_contents($this->dir . '/config.php', '<?php return "broken";' . PHP_EOL);

        $this->assertFalse($this->writer()->connect('ws.linkrobins.com', 'k', 's'));
        // And the file is left untouched.
        $this->assertEquals('broken', include $this->dir . '/config.php');
    }
}
