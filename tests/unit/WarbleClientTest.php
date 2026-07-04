<?php

/*
 * This file is part of linkrobins/flarum-warble.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Warble\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LinkRobins\Warble\WarbleClient;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

class WarbleClientTest extends MockeryTestCase
{
    /** @var array<int, array{request: Request}> */
    private array $history = [];

    private function client(array $responses, ?string $serviceUrl = null): WarbleClient
    {
        $this->history = [];

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $settings = m::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')
            ->with('linkrobins-warble.service-url')
            ->andReturn($serviceUrl);

        return new WarbleClient($settings, new NullLogger(), new Client(['handler' => $stack]));
    }

    #[Test]
    public function a_successful_exchange_returns_normalised_config(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode([
                'app_id' => 7,
                'key' => 'app-key',
                'secret' => 'app-secret',
                'host' => 'ws.linkrobins.com',
            ])),
        ]);

        $config = $client->fetchConfig('SETUPKEY');

        $this->assertEquals([
            'key' => 'app-key',
            'secret' => 'app-secret',
            'host' => 'ws.linkrobins.com',
            'port' => 443,
            'scheme' => 'https',
        ], $config);
    }

    #[Test]
    public function the_exchange_posts_the_token_to_the_configured_service(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['key' => 'k', 'secret' => 's', 'host' => 'h'])),
        ], serviceUrl: 'https://staging.linkrobins.com/');

        $client->fetchConfig('SETUPKEY');

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertEquals('https://staging.linkrobins.com/warble/config', (string) $request->getUri());
        $this->assertStringContainsString('token=SETUPKEY', (string) $request->getBody());
    }

    #[Test]
    public function a_blank_token_short_circuits_without_a_request(): void
    {
        $client = $this->client([]);

        $this->assertNull($client->fetchConfig('   '));
        $this->assertCount(0, $this->history);
    }

    #[Test]
    public function a_non_200_response_returns_null(): void
    {
        $client = $this->client([new Response(403, [], 'nope')]);

        $this->assertNull($client->fetchConfig('SETUPKEY'));
    }

    #[Test]
    public function a_malformed_or_incomplete_body_returns_null(): void
    {
        $incomplete = json_encode(['key' => 'k', 'host' => 'h']); // no secret

        $this->assertNull($this->client([new Response(200, [], 'not json')])->fetchConfig('SETUPKEY'));
        $this->assertNull($this->client([new Response(200, [], $incomplete)])->fetchConfig('SETUPKEY'));
    }

    #[Test]
    public function a_network_failure_returns_null_instead_of_throwing(): void
    {
        $client = $this->client([
            new ConnectException('refused', new Request('POST', '/warble/config')),
        ]);

        $this->assertNull($client->fetchConfig('SETUPKEY'));
    }
}
