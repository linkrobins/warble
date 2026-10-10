import app from 'flarum/forum/app';
import PollingSocket from './forum/PollingSocket';

/**
 * Warble's forum side: realtime over polling, with no socket server anywhere.
 *
 * flarum/realtime still constructs its pusher-js client and assigns it to
 * `app.websocket`; the property trap below swallows every such assignment,
 * disconnects the doomed socket, and stands a PollingSocket in its place.
 * realtime then wires all of its channels and handlers to the shim without
 * knowing the difference. Identity in typing events is decided server-side
 * per reader.
 */

/**
 * Keep pusher-js from opening a socket that Warble has no use for.
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
 * Only pusher-js's own URL shape is intercepted, so any other websocket on
 * the page is left alone.
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

app.initializers.add('linkrobins-warble', () => {
  const anyApp = app as any;

  // Before realtime's mount runs, so its constructor finds the guard in place.
  blockPusherSockets();

  let shim: PollingSocket | null = null;

  const adopt = (value: any): any => {
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
