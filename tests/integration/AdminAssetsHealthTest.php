<?php

/*
 * This file is part of linkrobins/flarum-warble.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Warble\Tests\integration;

use Flarum\Frontend\Compiler\VersionerInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Filesystem\Factory;
use LinkRobins\Warble\Frontend\AdminAssetsHealth;
use LinkRobins\Warble\Middleware\HealAdminAssets;
use PHPUnit\Framework\Attributes\Test;

class AdminAssetsHealthTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-warble');
    }

    protected function health(): AdminAssetsHealth
    {
        return $this->app()->getContainer()->make(AdminAssetsHealth::class);
    }

    protected function settings(): SettingsRepositoryInterface
    {
        return $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
    }

    protected function versioner(): VersionerInterface
    {
        return $this->app()->getContainer()->make(VersionerInterface::class);
    }

    protected function assetsDisk(): \Illuminate\Contracts\Filesystem\Cloud
    {
        return $this->app()->getContainer()->make(Factory::class)->disk('flarum-assets');
    }

    /**
     * The render-time step: compile the admin bundle as a document request
     * would. Forced, because the tests' tmp assets dir survives across suite
     * runs: an unforced commit would skip writing when a previous run left a
     * doctored admin.js behind under a still-matching revision.
     */
    protected function compileAdminBundle(): void
    {
        $this->app()->getContainer()->make('flarum.assets.admin')->makeJs()->commit(true);
    }

    #[Test]
    public function a_healthy_bundle_is_verified_and_left_alone(): void
    {
        $this->compileAdminBundle();
        $revision = $this->versioner()->getRevision(AdminAssetsHealth::COMPILED_FILE);
        $this->assertNotNull($revision);

        $this->health()->healIfStale();

        // Bundle untouched, build recorded as verified so later requests skip
        // re-reading the file.
        $this->assertEquals($revision, $this->versioner()->getRevision(AdminAssetsHealth::COMPILED_FILE));
        $this->assertEquals($revision, $this->settings()->get(AdminAssetsHealth::VERIFIED_REV_KEY));
        $this->assertStringContainsString(
            AdminAssetsHealth::MARKER,
            (string) $this->assetsDisk()->get(AdminAssetsHealth::COMPILED_FILE)
        );
    }

    #[Test]
    public function a_stale_bundle_is_flushed_and_recompiles_with_warble_included(): void
    {
        $this->compileAdminBundle();

        // Simulate the support-thread state: a bundle compiled before Warble
        // was enabled (no Warble chunk), still served under a valid revision.
        $this->assetsDisk()->put(AdminAssetsHealth::COMPILED_FILE, 'console.log("stale build")');

        $this->health()->healIfStale();

        // The stale build and its revision are gone, forcing a recompile.
        $this->assertNull($this->versioner()->getRevision(AdminAssetsHealth::COMPILED_FILE));
        $this->assertFalse($this->assetsDisk()->exists(AdminAssetsHealth::COMPILED_FILE));

        // The render step that follows within the same request rebuilds the
        // bundle from current sources, which now include Warble.
        $this->compileAdminBundle();
        $this->assertStringContainsString(
            AdminAssetsHealth::MARKER,
            (string) $this->assetsDisk()->get(AdminAssetsHealth::COMPILED_FILE)
        );

        // The next check verifies the fresh build and clears the heal flags.
        $this->health()->healIfStale();
        $this->assertNull($this->settings()->get(AdminAssetsHealth::HEAL_FAILED_KEY));
        $this->assertNull($this->settings()->get(AdminAssetsHealth::HEAL_ATTEMPTED_AT_KEY));
        $this->assertEquals(
            $this->versioner()->getRevision(AdminAssetsHealth::COMPILED_FILE),
            $this->settings()->get(AdminAssetsHealth::VERIFIED_REV_KEY)
        );
    }

    #[Test]
    public function a_recent_failed_heal_is_not_retried_and_gets_flagged(): void
    {
        $this->compileAdminBundle();
        $this->assetsDisk()->put(AdminAssetsHealth::COMPILED_FILE, 'console.log("stale build")');

        // A flush already happened moments ago and the bundle is STILL stale:
        // the rebuild isn't sticking on this host.
        $this->settings()->set(AdminAssetsHealth::HEAL_ATTEMPTED_AT_KEY, (string) time());

        $this->health()->healIfStale();

        // No second flush inside the retry window; the failure is flagged for
        // the fallback banner instead.
        $this->assertEquals('1', $this->settings()->get(AdminAssetsHealth::HEAL_FAILED_KEY));
        $this->assertEquals(
            'console.log("stale build")',
            (string) $this->assetsDisk()->get(AdminAssetsHealth::COMPILED_FILE)
        );
    }

    #[Test]
    public function the_heal_middleware_is_registered_in_the_admin_stack(): void
    {
        $this->assertContains(
            HealAdminAssets::class,
            $this->app()->getContainer()->make('flarum.admin.middleware')
        );
    }
}
