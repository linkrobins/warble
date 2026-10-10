import Extend from 'flarum/common/extenders';
import WarblePage from './admin/WarblePage';

// Warble admin: a checklist of everything realtime over polling depends on.
// There is nothing to set: no key, server, transport or interval (Warble
// picks the interval from what polling costs the host). Realtime's own
// feature settings stay on the Realtime page.
export const extend = [new Extend.Admin().page(WarblePage)];
