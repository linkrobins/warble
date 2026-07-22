<?php

namespace LinkRobins\Warble\Frontend;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Injects two tiny inline scripts into the server-rendered admin document, so
 * they work precisely when the compiled admin bundle is broken or stale, the
 * situations Warble's own admin JS can never reach:
 *
 * - <head>: captures the first script error during page startup, before the
 *   compiled bundle executes.
 * - end of <body>, after core's boot script: if Warble's module never
 *   registered in flarum.extensions, shows a plain-DOM banner that explains
 *   the problem in plain language and, when a cache rebuild is the likely
 *   fix, offers it as one click (core's DELETE /api/cache, which runs
 *   cache:clear plus assets:publish).
 *
 * The banner strings are deliberately hardcoded English rather than
 * translations: this UI renders exactly when the compiled asset pipeline,
 * which also delivers the locale data, is broken, so the translator cannot
 * be relied on here.
 */
class FallbackScripts
{
    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $document->head[] = $this->errorCapture();
        $document->foot[] = $this->detector();
    }

    /**
     * Boot errors surface before any framework code can observe them; this
     * captures the first one (file + message) so the detector can name the
     * offending script instead of reporting a silent failure.
     */
    protected function errorCapture(): string
    {
        return <<<'HTML'
<script>
(function () {
    window.lrWarbleBootError = null;
    function capture(e) {
        // Bubble-phase 'error' events on window are uncaught script errors
        // only (resource-load errors never reach here). Keep whatever detail
        // the browser gives us: cross-origin scripts (CDN assets, host vs
        // config-URL mismatches) are sanitized to just "Script error." with
        // no filename, and that still proves the bundle crashed.
        if (!window.lrWarbleBootError && e) {
            window.lrWarbleBootError = { file: String(e.filename || ''), message: String(e.message || 'unknown error') };
        }
    }
    window.addEventListener('error', capture);
    window.lrWarbleStopCapture = function () { window.removeEventListener('error', capture); };
})();
</script>
HTML;
    }

    protected function detector(): string
    {
        $healFailed = $this->settings->get(AdminAssetsHealth::HEAL_FAILED_KEY) === '1' ? 'true' : 'false';

        // Renders after the blade template's boot <script>, at which point
        // every extension chunk in the compiled bundle has either executed
        // (and registered itself in flarum.extensions) or provably never will.
        return sprintf(<<<'HTML'
<script>
(function () {
    if (window.lrWarbleStopCapture) window.lrWarbleStopCapture();
    if (window.flarum && flarum.extensions && 'linkrobins-warble' in flarum.extensions) return;

    var err = window.lrWarbleBootError;
    var healFailed = %s;
    var host = 'Ask your hosting provider to make the assets folder inside your Flarum installation writable, then reload this page.';

    var banner = document.createElement('div');
    banner.id = 'lr-warble-fallback';
    banner.setAttribute('style', 'position:fixed;bottom:0;left:0;right:0;z-index:99999;background:#b72a2a;color:#fff;padding:14px 18px;font:14px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;text-align:center;box-shadow:0 -2px 8px rgba(0,0,0,0.25);');

    var text = document.createElement('span');
    if (err) {
        var where = err.file ? ' (' + err.file + ': ' + err.message + ')' : ' (' + err.message + ')';
        text.textContent = 'Warble could not start: a script crashed while this page was loading' + where + '. This is usually another extension interfering. Try disabling recently added extensions, and share this exact message on the Warble support thread.';
    } else if (healFailed) {
        text.textContent = 'Warble could not load its settings: this forum is serving an outdated assets build, and Warble was unable to rebuild it automatically. ' + host;
    } else {
        text.textContent = 'Warble could not load its settings: this forum is serving an outdated assets build. ';
    }
    banner.appendChild(text);

    if (!err && !healFailed) {
        if (window.app && app.request && app.forum) {
            var btn = document.createElement('button');
            btn.textContent = 'Fix this';
            btn.setAttribute('style', 'margin-left:12px;padding:6px 14px;border:0;border-radius:4px;background:#fff;color:#b72a2a;font-weight:bold;cursor:pointer;');
            btn.onclick = function () {
                btn.disabled = true;
                btn.textContent = 'Rebuilding, give it a moment...';
                app.request({ method: 'DELETE', url: app.forum.attribute('apiUrl') + '/cache' }).then(
                    function () { location.reload(); },
                    function () {
                        btn.parentNode.removeChild(btn);
                        text.textContent = 'The automatic rebuild did not work. ' + host;
                    }
                );
            };
            banner.appendChild(btn);
        } else {
            text.textContent += 'Open the admin dashboard, choose Tools, then Clear Cache, and reload. If this banner returns, also clear any hosting or CDN cache.';
        }
    }

    document.body.appendChild(banner);
})();
</script>
HTML, $healFailed);
    }
}
