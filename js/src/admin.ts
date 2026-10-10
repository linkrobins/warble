import app from 'flarum/admin/app';
import m from 'mithril';

// Warble admin: a status line and the polling interval. Warble runs realtime
// over polling and nothing else, so there is no key, server or transport to
// set. Realtime's own feature settings stay on the Realtime page.
app.initializers.add('linkrobins-warble', () => {
  const isRealtimeEnabled = (): boolean => {
    try {
      if (app.extensionManager && typeof app.extensionManager.isEnabled === 'function') {
        return app.extensionManager.isEnabled('flarum-realtime');
      }
    } catch (e) {
      // fall through to the payload check
    }
    // Fallback: enabled-extensions list in the admin payload.
    const data = app.data as any;
    const list = (data && (data.extensions || data.enabledExtensions)) || {};
    return !!list['flarum-realtime'];
  };

  const banner = (): m.Children => {
    const t = (k: string) => app.translator.trans('linkrobins-warble.admin.' + k);
    const ready = isRealtimeEnabled();

    return m(
      'div',
      { className: ready ? 'Alert Alert--success' : 'Alert Alert--error', style: 'margin-bottom:16px;' },
      t(ready ? 'running' : 'need_realtime')
    );
  };

  app.registry
    .for('linkrobins-warble')
    .registerSetting(banner, 100)
    .registerSetting({
      setting: 'linkrobins-warble.poll-interval',
      label: app.translator.trans('linkrobins-warble.admin.poll_interval_label'),
      help: app.translator.trans('linkrobins-warble.admin.poll_interval_help'),
      type: 'number',
      min: 2,
      max: 30,
    });
});
