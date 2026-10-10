import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import WarbleHealth from './WarbleHealth';

/**
 * Warble's admin page: the health checklist and nothing else. A plain
 * settings registration would also bring Flarum's Save and Reset buttons,
 * which have nothing to act on here.
 */
export default class WarblePage extends ExtensionPage {
  content() {
    return m('.ExtensionPage-settings', m('.container', m(WarbleHealth)));
  }
}
