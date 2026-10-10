import app from 'flarum/admin/app';
import WarbleHealth from './admin/WarbleHealth';

// Warble admin: a checklist of everything realtime over polling depends on,
// and the polling interval. There is no key, server or transport to set.
// Realtime's own feature settings stay on the Realtime page.
app.initializers.add('linkrobins-warble', () => {
  app.registry
    .for('linkrobins-warble')
    .registerSetting(() => m(WarbleHealth), 100)
    .registerSetting({
      setting: 'linkrobins-warble.poll-interval',
      label: app.translator.trans('linkrobins-warble.admin.poll_interval_label'),
      help: app.translator.trans('linkrobins-warble.admin.poll_interval_help'),
      type: 'number',
      min: 2,
      max: 30,
    });
});
