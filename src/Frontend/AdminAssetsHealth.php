<?php

namespace LinkRobins\Warble\Frontend;

use Flarum\Frontend\Compiler\VersionerInterface;
use Flarum\Frontend\RecompileFrontendAssets;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use Psr\Log\LoggerInterface;

/**
 * Detects and repairs a stale compiled admin bundle.
 *
 * Flarum concatenates core plus every enabled extension's admin JS into ONE
 * compiled admin.js, and only rebuilds it when the revision entry is flushed.
 * Core flushes it in an Enabled-event listener that runs AFTER the extension
 * is persisted as enabled, so on hosts where that flush fails (unwritable
 * assets directory, half-broken cache plumbing) the forum keeps serving a
 * bundle from BEFORE Warble was enabled. The visible symptom is the support
 * thread's "This extension has no configuration": the settings panel never
 * renders because Warble's module isn't in the served file at all.
 *
 * This class re-runs that flush whenever the served bundle provably predates
 * Warble, so the rebuild happens during a normal admin page load with no SSH
 * and no user action. It is diagnostic plumbing: every failure path degrades
 * to "no change plus a flag", never to an exception that could break the
 * admin panel it is trying to fix.
 */
class AdminAssetsHealth
{
    /**
     * Present in the compiled bundle if and only if Warble's chunk was
     * included at compile time: core wraps every extension chunk with
     * "flarum.extensions['<id>']=module.exports;" and the chunk body itself
     * carries the id in its settings keys. Deliberately just the bare id, so
     * the check survives any future quote or wrapper changes in core.
     */
    public const MARKER = 'linkrobins-warble';

    public const COMPILED_FILE = 'admin.js';

    public const VERIFIED_REV_KEY = 'linkrobins-warble.assets-verified-rev';
    public const HEAL_ATTEMPTED_AT_KEY = 'linkrobins-warble.assets-heal-attempted-at';
    public const HEAL_FAILED_KEY = 'linkrobins-warble.assets-heal-failed';

    /**
     * One flush attempt per window: when the rebuild itself keeps failing
     * (typically an unwritable assets directory) this must not turn every
     * admin page load into a recompile storm.
     */
    public const RETRY_SECONDS = 600;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected VersionerInterface $versioner,
        protected Factory $filesystem,
        protected Container $container,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * Flush the admin assets if the served bundle predates Warble, so the
     * current request's page render recompiles them from today's sources.
     * Never throws.
     */
    public function healIfStale(): void
    {
        try {
            $this->check();
        } catch (\Throwable $e) {
            $this->log->warning('Warble: admin assets health check failed: ' . $e->getMessage());
        }
    }

    protected function check(): void
    {
        $revision = $this->versioner->getRevision(self::COMPILED_FILE);

        // No compiled bundle yet: the upcoming render compiles from current
        // sources, which include Warble. Nothing to heal.
        if (! $revision) {
            return;
        }

        // This exact build was already inspected and found healthy; skip
        // re-reading a multi-megabyte file on every admin request.
        if ($this->settings->get(self::VERIFIED_REV_KEY) === $revision) {
            return;
        }

        $disk = $this->filesystem->disk('flarum-assets');
        if (! $disk->exists(self::COMPILED_FILE)) {
            return;
        }

        if (str_contains((string) $disk->get(self::COMPILED_FILE), self::MARKER)) {
            $this->settings->set(self::VERIFIED_REV_KEY, $revision);
            $this->settings->delete(self::HEAL_ATTEMPTED_AT_KEY);
            $this->settings->delete(self::HEAL_FAILED_KEY);

            return;
        }

        $lastAttempt = (int) $this->settings->get(self::HEAL_ATTEMPTED_AT_KEY, 0);
        if (time() - $lastAttempt < self::RETRY_SECONDS) {
            // A recent flush didn't stick, so the rebuild is failing on this
            // host. The fallback banner reads this flag and tells the admin
            // it's a hosting/permissions problem instead of offering a
            // one-click fix that can't work.
            $this->settings->set(self::HEAL_FAILED_KEY, '1');

            return;
        }

        // Record the attempt BEFORE flushing so a flush that throws still
        // counts against the retry window.
        $this->settings->set(self::HEAL_ATTEMPTED_AT_KEY, (string) time());

        // The same flush core runs when an extension is enabled. The bundle
        // recompiles from current sources later in this very request, so the
        // admin usually just sees a working settings page.
        (new RecompileFrontendAssets(
            $this->container->make('flarum.assets.admin'),
            $this->container->make(LocaleManager::class),
            $this->container->make('events'),
        ))->flush();

        $this->log->info('Warble: flushed a stale compiled admin bundle (it predated Warble being enabled).');
    }
}
