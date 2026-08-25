import app from 'flarum/admin/app';
import m from 'mithril';

// Warble admin: dead-simple, one field. The owner pastes their Warble key and
// the whole connection (flarum/realtime's websocket config) is set up for them.
// Realtime's own feature settings stay theirs to tune. A plain-language status
// banner tells a no-experience user exactly what to do next.
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
    let cls = 'Alert';
    let text: m.Children;

    // Status flags arrive as STRINGS from the settings table ('1'/'0' — and a
    // legacy PHP false round-trips as "0", which is truthy in JS), so compare
    // strictly against '1'.
    if (!isRealtimeEnabled()) {
      cls = 'Alert Alert--error';
      text = t('need_realtime');
    } else if (app.data.settings['linkrobins-warble.config-write-failed'] === '1') {
      cls = 'Alert Alert--error';
      text = t('write_failed');
    } else if (app.data.settings['linkrobins-warble.connected'] === '1') {
      cls = 'Alert Alert--success';
      text = t('connected');
    } else {
      text = t('paste_key');
    }

    return m('div', { className: cls, style: 'margin-bottom:16px;' }, text);
  };

  app.registry
    .for('linkrobins-warble')
    .registerSetting(banner, 100)
    .registerSetting({
      setting: 'linkrobins-warble.transport',
      label: app.translator.trans('linkrobins-warble.admin.transport_label'),
      help: app.translator.trans('linkrobins-warble.admin.transport_help'),
      type: 'select',
      options: {
        auto: app.translator.trans('linkrobins-warble.admin.transport_auto'),
        polling: app.translator.trans('linkrobins-warble.admin.transport_polling'),
        socket: app.translator.trans('linkrobins-warble.admin.transport_socket'),
      },
      default: 'auto',
    })
    .registerSetting({
      setting: 'linkrobins-warble.poll-interval',
      label: app.translator.trans('linkrobins-warble.admin.poll_interval_label'),
      help: app.translator.trans('linkrobins-warble.admin.poll_interval_help'),
      type: 'number',
      min: 2,
      max: 30,
    })
    .registerSetting({
      setting: 'linkrobins-warble.setup-token',
      label: app.translator.trans('linkrobins-warble.admin.key_label'),
      help: app.translator.trans('linkrobins-warble.admin.key_help'),
      type: 'text',
    });
});
