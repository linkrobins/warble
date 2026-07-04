<?php

/*
 * This file is part of linkrobins/flarum-warble.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Warble\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SaveSettingsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-warble');

        // Point the exchange at a closed local port: the request fails
        // instantly, exercising the fail-soft path with no external traffic.
        $this->setting('linkrobins-warble.service-url', 'http://127.0.0.1:9');
    }

    #[Test]
    public function a_bad_exchange_never_breaks_the_settings_save(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/settings', [
                'authenticatedAs' => 1,
                'json' => ['linkrobins-warble.setup-token' => 'SOME-KEY'],
            ])
        );

        // The save itself must succeed (fail-soft listener) ...
        $this->assertEquals(204, $response->getStatusCode());

        // ... with the extension marked disconnected, not errored.
        $connected = $this->database()->table('settings')
            ->where('key', 'linkrobins-warble.connected')->value('value');
        $this->assertEquals('0', $connected);
    }

    #[Test]
    public function unrelated_settings_saves_do_not_touch_warble_state(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/settings', [
                'authenticatedAs' => 1,
                'json' => ['forum_title' => 'Renamed forum'],
            ])
        );

        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEquals(
            0,
            $this->database()->table('settings')->where('key', 'linkrobins-warble.connected')->count()
        );
    }

    #[Test]
    public function saving_settings_requires_admin(): void
    {
        $this->prepareDatabase(['users' => [$this->normalUser()]]);

        $response = $this->send(
            $this->request('POST', '/api/settings', [
                'authenticatedAs' => 2,
                'json' => ['linkrobins-warble.setup-token' => 'SOME-KEY'],
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());
    }
}
