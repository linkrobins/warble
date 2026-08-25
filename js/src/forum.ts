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

app.initializers.add('linkrobins-warble', () => {
  const anyApp = app as any;
  const polling = app.forum.attribute<string>('warbleTransport') === 'polling';

  let shim: PollingSocket | null = null;

  const adopt = (value: any): any => {
    if (!polling) return value ? wrapSocket(value) : value;

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
    return (shim ??= new PollingSocket());
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
