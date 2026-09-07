import app from 'flarum/forum/app';

/**
 * A Pusher-shaped client that never opens a socket.
 *
 * flarum/realtime wires everything — channel subscriptions, event bindings,
 * liveness tracking, reconnect catch-up — through the object it assigns to
 * `app.websocket`, using a small slice of the pusher-js surface. This
 * implements exactly that slice over HTTP polling: one GET collects events
 * for every subscribed channel by cursor, client events (typing) become one
 * POST, and realtime's own code runs unmodified on top.
 *
 * Cadence: the server suggests the interval (default 3s); a hidden tab stops
 * polling entirely and fires one immediate poll when it returns; a tab idle
 * for a while stretches the interval; every wait is jittered so a forum's
 * tabs don't synchronize into request spikes.
 */

const IDLE_AFTER_MS = 5 * 60 * 1000;
const IDLE_MULTIPLIER = 5;
const JITTER = 0.2;

interface Binding {
  event: string;
  callback: (data: unknown) => void;
}

class PollingChannel {
  private bindings: Binding[] = [];

  constructor(
    public name: string,
    private socket: PollingSocket
  ) {}

  bind(event: string, callback: (data: unknown) => void): this {
    this.bindings.push({ event, callback });
    return this;
  }

  unbind(event?: string, callback?: (data: unknown) => void): this {
    this.bindings = this.bindings.filter((b) => (event ? b.event !== event || (callback ? b.callback !== callback : false) : false));
    return this;
  }

  /** A client event: POSTed, fire and forget. Pusher returns a boolean. */
  trigger(event: string, data?: unknown): boolean {
    this.socket.post(this.name, event, data);
    return true;
  }

  dispatch(event: string, data: unknown): void {
    this.bindings.forEach((b) => {
      if (b.event === event) {
        try {
          b.callback(data);
        } catch {
          // One broken handler must not stop the rest.
        }
      }
    });
  }
}

interface ConnectionBinding {
  event: string;
  callback: (payload?: unknown) => void;
}

export default class PollingSocket {
  /** Mirrors pusher-js's registry; realtime never reads it, but the identity
   *  wrap for socket mode does, and keeping the same shape costs nothing. */
  channels = { channels: {} as Record<string, PollingChannel> };

  connection = {
    state: 'initialized',
    bindings: [] as ConnectionBinding[],
    bind: (event: string, callback: (payload?: unknown) => void) => {
      this.connection.bindings.push({ event, callback });
      return this.connection;
    },
    unbind: () => this.connection,
  };

  private globals: ((...args: unknown[]) => void)[] = [];
  private cursor: number | null = null;
  private interval = 3000;
  private timer: ReturnType<typeof setTimeout> | null = null;
  stopped = false;
  private polling = false;
  private lastActivity = Date.now();
  /** This tab's identity, so its own client events are not echoed back. */
  private origin = Math.random().toString(36).slice(2, 14) + Math.random().toString(36).slice(2, 14);

  constructor() {
    this.listen();
    this.schedule(0);
  }

  private listen(): void {
    document.addEventListener('visibilitychange', this.onVisibility);
    ['pointerdown', 'keydown', 'scroll'].forEach((ev) => document.addEventListener(ev, this.onActivity, { passive: true }));
  }

  /**
   * Bring a disconnected shim back to life.
   *
   * realtime rebuilds its client whenever it decides the connection is stale
   * (every iOS app-switch, and on any browser once a tab has been hidden past
   * its 65s liveness window). That path calls disconnect() on whatever is in
   * `app.websocket` first, which used to stop this loop for good: polling
   * never resumed until the page was reloaded. The cursor is deliberately
   * kept, so the catch-up poll picks up exactly where the old one left off.
   */
  restart(): void {
    if (!this.stopped) return;

    this.stopped = false;
    this.listen();
    this.setState('initialized');
    this.schedule(0);
  }

  /** pusher-js: called on every incoming frame; realtime feeds liveness from it. */
  bind_global(callback: (...args: unknown[]) => void): this {
    this.globals.push(callback);
    return this;
  }

  subscribe(name: string): PollingChannel {
    const existing = this.channels.channels[name];

    if (existing) return existing;

    const channel = new PollingChannel(name, this);
    this.channels.channels[name] = channel;

    return channel;
  }

  unsubscribe(name: string): void {
    delete this.channels.channels[name];
  }

  disconnect(): void {
    this.stopped = true;

    if (this.timer) clearTimeout(this.timer);

    document.removeEventListener('visibilitychange', this.onVisibility);
    ['pointerdown', 'keydown', 'scroll'].forEach((ev) => document.removeEventListener(ev, this.onActivity));

    this.setState('disconnected');
  }

  post(channel: string, event: string, data: unknown): void {
    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute<string>('apiUrl')}/warble/event`,
        body: { channel, event, data, origin: this.origin },
        errorHandler: () => {},
      })
      .catch(() => {});
  }

  private onVisibility = (): void => {
    if (document.visibilityState === 'visible' && !this.stopped) {
      this.schedule(0);
    }
  };

  private onActivity = (): void => {
    this.lastActivity = Date.now();
  };

  private schedule(delay: number): void {
    if (this.stopped) return;
    if (this.timer) clearTimeout(this.timer);

    this.timer = setTimeout(() => void this.poll(), delay);
  }

  private nextDelay(): number {
    const idle = Date.now() - this.lastActivity > IDLE_AFTER_MS;
    const base = this.interval * (idle ? IDLE_MULTIPLIER : 1);

    return base * (1 - JITTER + Math.random() * JITTER * 2);
  }

  private async poll(): Promise<void> {
    if (this.stopped || this.polling) return;

    // A hidden tab does not poll; visibilitychange restarts it.
    if (document.visibilityState === 'hidden') return;

    this.polling = true;

    const names = Object.keys(this.channels.channels);

    try {
      const params = new URLSearchParams({ channels: names.join(','), origin: this.origin });

      if (this.cursor !== null) params.set('cursor', String(this.cursor));

      const data = (await app.request({
        method: 'GET',
        url: `${app.forum.attribute<string>('apiUrl')}/warble/poll?${params}`,
        errorHandler: () => {},
      })) as { cursor: number; interval: number; events: { channel: string; event: string; data: unknown }[] };

      this.cursor = data.cursor;
      this.interval = Math.max(2000, (data.interval || 3) * 1000);

      // Every successful poll is a frame: feeds realtime's liveness clock.
      this.globals.forEach((g) => {
        try {
          g();
        } catch {}
      });

      this.setState('connected');

      (data.events || []).forEach((e) => {
        this.channels.channels[e.channel]?.dispatch(e.event, e.data);
      });
    } catch {
      // The next poll retries; realtime's own stale-connection handling sees
      // the liveness clock stop and recovers with a catch-up on reconnect.
      this.setState('unavailable');
    } finally {
      this.polling = false;
      this.schedule(this.nextDelay());
    }
  }

  private setState(state: string): void {
    if (this.connection.state === state) return;

    const previous = this.connection.state;
    this.connection.state = state;

    this.connection.bindings.forEach((b) => {
      if (b.event === 'state_change') {
        try {
          b.callback({ previous, current: state });
        } catch {}
      }
    });
  }
}
