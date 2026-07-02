import app from 'flarum/admin/app';
import m from 'mithril';

// Chirp admin: dead-simple, one field. The owner pastes their Chirp key and
// everything (all of flarum/realtime's connection config) is set up for them —
// they never open the Realtime extension's settings. A plain-language status
// banner tells a no-experience user exactly what to do next.
app.initializers.add('linkrobins-chirp', () => {
  const isRealtimeEnabled = () => {
    try {
      if (app.extensionManager && typeof app.extensionManager.isEnabled === 'function') {
        return app.extensionManager.isEnabled('flarum-realtime');
      }
    } catch (e) {}
    // Fallback: enabled-extensions list in the admin payload.
    const list = (app.data && (app.data.extensions || app.data.enabledExtensions)) || {};
    return !!list['flarum-realtime'];
  };

  const banner = () => {
    const t = (k) => app.translator.trans('linkrobins-chirp.admin.' + k);
    let cls = 'Alert';
    let text;

    if (!isRealtimeEnabled()) {
      cls = 'Alert Alert--error';
      text = t('need_realtime');
    } else if (app.data.settings['linkrobins-chirp.config-write-failed']) {
      cls = 'Alert Alert--error';
      text = t('write_failed');
    } else if (app.data.settings['linkrobins-chirp.connected']) {
      cls = 'Alert Alert--success';
      text = t('connected');
    } else {
      text = t('paste_key');
    }

    return m('div', { className: cls, style: 'margin-bottom:16px;' }, text);
  };

  app.registry
    .for('linkrobins-chirp')
    .registerSetting(banner, 100)
    .registerSetting({
      setting: 'linkrobins-chirp.setup-token',
      label: app.translator.trans('linkrobins-chirp.admin.key_label'),
      help: app.translator.trans('linkrobins-chirp.admin.key_help'),
      type: 'text',
    });
});
