import app from 'flarum/forum/app';
import PollingSocket from './forum/PollingSocket';

/**
 * Warble's forum-side transport work, both modes decided by the
 * `warbleTransport` forum attribute:
 *
 * POLLING — no socket server anywhere. flarum/realtime still constructs its
 * pusher-js client and assigns it to `app.websocket`; the property trap
 * below swallows every such assignment, disconnects the doomed socket, and
 * stands a PollingSocket in its place. realtime then wires all of its
 * channels and handlers to the shim without knowing the difference.
 * Identity in typing events is decided server-side per reader, so the
 * sender-side wrap is NOT installed in this mode.
 *
 * SOCKET — the hosted-era or bring-your-own configuration. The socket is
 * real and stays; the only intervention is the typing-identity wrap:
 * realtime 2.0.0-rc.6 stopped sending anything identifying in
 * `client-typing` events (its own bundled websocket server injects the
 * name; a Pusher-protocol relay cannot), so outgoing payloads that lack the
 * field get the sender's own name attached, rc.5-style.
 */

type Payload = Record<string, unknown>;

function identify(event: string, data: Payload | undefined): Payload | undefined {
  if (event !== 'client-typing' || !data || 'displayName' in data) return data;

  const user = app.session.user;
  const disclose = !!user?.preferences?.()?.discloseOnline;

  return {
    ...data,
    displayName: disclose && user ? user.displayName() : null,
    discloseOnline: disclose,
  };
}

function wrapChannel(channel: any): any {
  if (!channel || typeof channel.trigger !== 'function' || channel.__warbleIdentified) return channel;

  const trigger = channel.trigger.bind(channel);
  channel.trigger = (event: string, data?: Payload) => trigger(event, identify(event, data));
  channel.__warbleIdentified = true;

  return channel;
}

function wrapSocket(ws: any): any {
  if (!ws || typeof ws.subscribe !== 'function' || ws.__warbleIdentified) return ws;

  const subscribe = ws.subscribe.bind(ws);
  ws.subscribe = (name: string) => wrapChannel(subscribe(name));
  ws.__warbleIdentified = true;

  if (ws.channels?.channels) {
    Object.values(ws.channels.channels).forEach(wrapChannel);
  }

  return ws;
}

/**
 * Keep pusher-js from opening a socket that polling mode has no use for.
 *
 * realtime builds its client with `new Pusher(...)`, and pusher-js connects
 * from inside that constructor, so by the time the assignment reaches the trap
 * below the browser has already begun a handshake to the websocket server this
 * forum does not run. Disconnecting it then is too late: the socket is aborted
 * mid-handshake and every browser logs
 *
 *   WebSocket connection to 'wss://host:6001/app/<key>' failed:
 *   WebSocket is closed before the connection is established.
 *
 * which is alarming, repeats on every forced reconnect, and led to two reports
 * on the community thread from people whose forums were in fact working.
 *
 * Only pusher-js's own URL shape is intercepted, and only in polling mode, so
 * any other websocket on the page is left alone.
 */
function blockPusherSockets(): void {
  const anyWindow = window as any;
  const Native = anyWindow.WebSocket;

  if (!Native || anyWindow.__warbleSocketGuard) return;

  anyWindow.__warbleSocketGuard = true;

  const isPusher = (url: string): boolean => /\/app\/[^/?]+\?[^ ]*\bclient=js\b/.test(url);

  // Inert: never connects, never errors, never retries. pusher-js assigns its
  // handlers and then closes this when Warble disconnects the client.
  const inert = (url: string): any => ({
    url,
    readyState: 0,
    binaryType: 'blob',
    onopen: null,
    onclose: null,
    onerror: null,
    onmessage: null,
    send: () => {},
    close: function (this: any) {
      this.readyState = 3;
    },
    addEventListener: () => {},
    removeEventListener: () => {},
  });

  const Guard = function (url: string, protocols?: string | string[]) {
    return isPusher(String(url)) ? inert(String(url)) : new Native(url, protocols as any);
  } as any;

  Guard.prototype = Native.prototype;
  ['CONNECTING', 'OPEN', 'CLOSING', 'CLOSED'].forEach((k) => (Guard[k] = Native[k]));

  anyWindow.WebSocket = Guard;
}

/**
 * The transport, read from the boot payload rather than `app.forum`, which 2.0
 * has not built yet while initializers run.
 */
function pollingAtBoot(): boolean {
  try {
    const forum = (app as any).data?.resources?.find((r: any) => r.type === 'forums');

    return forum?.attributes?.warbleTransport === 'polling';
  } catch {
    return false;
  }
}

app.initializers.add('linkrobins-warble', () => {
  const anyApp = app as any;

  // Before realtime's mount runs, so its constructor finds the guard in place.
  if (pollingAtBoot()) blockPusherSockets();

  let shim: PollingSocket | null = null;

  // Evaluated at assignment time, not here: initializers run before 2.0
  // builds `app.forum`, so reading the attribute now would throw and the
  // trap below would never install. By the time realtime assigns
  // `app.websocket` (during Application.mount), the forum model exists.
  const polling = (): boolean => {
    try {
      return app.forum.attribute<string>('warbleTransport') === 'polling';
    } catch {
      return false;
    }
  };

  const adopt = (value: any): any => {
    if (!polling()) return value ? wrapSocket(value) : value;

    if (!value) return value;

    // Whatever pusher-js instance realtime just built is pointed at a socket
    // server that does not exist; stop it before it starts retrying.
    if (typeof value.disconnect === 'function' && !(value instanceof PollingSocket)) {
      try {
        value.disconnect();
      } catch {}
    }

    // One shim for the page's lifetime: realtime's forced reconnects build
    // fresh Pusher instances, but the polling loop has no connection to lose.
    // Those reconnects do call disconnect() on the previous client first,
    // which stops this shim, so wake it back up before handing it over.
    shim ??= new PollingSocket();
    shim.restart();

    return shim;
  };

  if (anyApp.websocket) {
    anyApp.websocket = adopt(anyApp.websocket);
    return;
  }

  // realtime assigns `app.websocket` during Application.mount, after
  // initializers; intercept the assignment so the swap happens no matter
  // which order the extensions booted in.
  let stored: any;
  Object.defineProperty(anyApp, 'websocket', {
    configurable: true,
    enumerable: true,
    get: () => stored,
    set: (value) => {
      stored = adopt(value);
    },
  });
});
