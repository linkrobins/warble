<?php

/*
 * This file is part of linkrobins/flarum-warble.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Warble\Tests\integration;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Warble\Frontend\AdminAssetsHealth;
use LinkRobins\Warble\Frontend\FallbackScripts;
use PHPUnit\Framework\Attributes\Test;

class FallbackScriptsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-warble');
    }

    protected function makeDocument(): Document
    {
        return $this->app()->getContainer()->makeWith(Document::class, [
            'forumApiDocument' => ['data' => ['attributes' => []]],
            'request' => $this->request('GET', '/admin'),
        ]);
    }

    protected function invokeCallback(Document $document): void
    {
        $callback = $this->app()->getContainer()->make(FallbackScripts::class);
        $callback($document, $this->request('GET', '/admin'));
    }

    #[Test]
    public function both_inline_scripts_are_injected(): void
    {
        $document = $this->makeDocument();
        $this->invokeCallback($document);

        // Error capture goes to <head>, so it runs before the compiled bundle.
        $this->assertCount(1, $document->head);
        $this->assertStringContainsString('lrWarbleBootError', $document->head[0]);

        // The detector goes to the foot, after core's boot script, and keys
        // off the module registration core writes for every included chunk.
        $this->assertCount(1, $document->foot);
        $this->assertStringContainsString("'linkrobins-warble' in flarum.extensions", $document->foot[0]);
        $this->assertStringContainsString('lr-warble-fallback', $document->foot[0]);
    }

    #[Test]
    public function the_detector_reflects_the_heal_failed_flag(): void
    {
        $document = $this->makeDocument();
        $this->invokeCallback($document);
        $this->assertStringContainsString('var healFailed = false', $document->foot[0]);

        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
            ->set(AdminAssetsHealth::HEAL_FAILED_KEY, '1');

        $document = $this->makeDocument();
        $this->invokeCallback($document);
        $this->assertStringContainsString('var healFailed = true', $document->foot[0]);
    }
}
