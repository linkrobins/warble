import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/utils/humanTime';

type Status = 'ok' | 'warn' | 'fail' | 'info';

interface Check {
  id: string;
  status: Status;
  params?: Record<string, string | number> | unknown[];
}

const ICONS: Record<Status, string> = {
  ok: 'fas fa-check-circle',
  warn: 'fas fa-exclamation-triangle',
  fail: 'fas fa-times-circle',
  info: 'fas fa-info-circle',
};

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans('linkrobins-warble.admin.' + key, params);

/**
 * The checklist at the top of Warble's settings: everything realtime over
 * polling depends on, as this forum sees it right now.
 *
 * The server answers what it can see; the poll endpoint is asked from here,
 * the way a visitor's browser asks it, because a host or firewall rule that
 * blocks it only shows up from the outside.
 */
export default class WarbleHealth extends Component {
  checks: Check[] | null = null;
  loading = false;
  failed = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  view() {
    return m('.WarbleHealth', [
      m('h3.WarbleHealth-title', t('health_title')),
      this.failed
        ? m('p.WarbleHealth-error', t('health_failed'))
        : this.checks === null
          ? m(LoadingIndicator, { display: 'inline' })
          : m(
              'ul.WarbleHealth-list',
              this.checks.map((check) =>
                m('li.WarbleHealth-item.WarbleHealth-item--' + check.status, { key: check.id }, [
                  m(Icon, { name: ICONS[check.status], className: 'WarbleHealth-icon' }),
                  m('span.WarbleHealth-text', this.text(check)),
                ])
              )
            ),
      m(
        Button,
        { className: 'Button WarbleHealth-recheck', icon: 'fas fa-sync', loading: this.loading, onclick: () => this.load() },
        t('health_recheck')
      ),
    ]);
  }

  text(check: Check) {
    const params: Record<string, unknown> = Array.isArray(check.params) ? {} : { ...(check.params || {}) };
    if (typeof params.at === 'string') params.at = humanTime(new Date(params.at));

    return t('check.' + check.id, params);
  }

  async load() {
    this.loading = true;
    this.failed = false;

    const api = app.forum.attribute<string>('apiUrl');

    try {
      const response = await app.request<{ checks: Check[] }>({ method: 'GET', url: api + '/warble/health' });

      let endpoint: Check = { id: 'endpoint_ok', status: 'ok' };
      try {
        await app.request({ method: 'GET', url: api + '/warble/poll?channels=public' });
      } catch (e) {
        endpoint = { id: 'endpoint_fail', status: 'fail' };
      }

      const checks = [...response.checks];
      checks.splice(1, 0, endpoint);
      this.checks = checks;
    } catch (e) {
      this.failed = true;
    }

    this.loading = false;
    m.redraw();
  }
}
