import app from 'flarum/forum/app';

/**
 * Put the typist's name back into typing events relayed through Warble.
 *
 * flarum/realtime 2.0.0-rc.6 stopped sending anything identifying in
 * `client-typing` events: its own bundled websocket server now looks the
 * sender up from the authenticated connection and injects the display name
 * server-side (extensions/realtime, Message::relayTyping). Warble, like every
 * other Pusher-protocol backend, relays client events verbatim and has no
 * access to the forum's users, so on rc.6 every typing event arrives nameless
 * and every typist renders as the anonymous label.
 *
 * The fix is the rc.5 contract, applied only where it is missing: if an
 * outgoing `client-typing` payload has no `displayName` field (an rc.6 client;
 * rc.5 clients still include it and pass through untouched), the sender's own
 * name and online-disclosure preference are attached. A user who hides their
 * online status stays anonymous, exactly as on rc.5. The one rc.6 refinement
 * this cannot reproduce is `user.viewLastSeenAt` holders seeing through hidden
 * typists: that requires a server that knows the forum's users, and a name
 * broadcast on the open channel would disclose it to everyone. Hidden stays
 * hidden for all, which is the rc.5 behaviour.
 *
 * Sender-asserted identity is the trust model every Pusher-protocol client
 * event has (and the one rc.5 shipped with): it is readable by exactly the
 * audience that could already subscribe to the discussion's typing channel.
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

  // Channels subscribed before this ran (initializer ordering is not ours to
  // control) are wrapped retroactively.
  if (ws.channels?.channels) {
    Object.values(ws.channels.channels).forEach(wrapChannel);
  }

  return ws;
}

app.initializers.add('linkrobins-warble', () => {
  const anyApp = app as any;

  if (anyApp.websocket) {
    wrapSocket(anyApp.websocket);
    return;
  }

  // flarum/realtime assigns `app.websocket` when it connects; intercept the
  // assignment so the wrap lands no matter which side runs first.
  let stored: any;
  Object.defineProperty(anyApp, 'websocket', {
    configurable: true,
    enumerable: true,
    get: () => stored,
    set: (value) => {
      stored = value ? wrapSocket(value) : value;
    },
  });
});
