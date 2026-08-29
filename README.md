# Warble for Flarum

[![Latest Version](https://img.shields.io/packagist/v/linkrobins/flarum-warble)](https://packagist.org/packages/linkrobins/flarum-warble)
[![Downloads](https://img.shields.io/packagist/dt/linkrobins/flarum-warble)](https://packagist.org/packages/linkrobins/flarum-warble)
[![License](https://img.shields.io/packagist/l/linkrobins/flarum-warble)](https://github.com/linkrobins/warble/blob/main/LICENSE)
[![Backend](https://github.com/linkrobins/warble/actions/workflows/backend.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/backend.yml)
[![Frontend](https://github.com/linkrobins/warble/actions/workflows/frontend.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/frontend.yml)
[![Realtime Compatibility](https://github.com/linkrobins/warble/actions/workflows/realtime-compat.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/realtime-compat.yml)

**Realtime for your Flarum forum, with no server to run.** `flarum-warble`
makes `flarum/realtime` work anywhere — live discussions, typing indicators,
notifications — including shared hosting where a websocket server is
impossible. Out of the box it uses **polling**: each visitor's browser asks
your forum for updates every few seconds, so realtime needs nothing but the
forum itself. If you can run a websocket server, point Warble at it and get
instant delivery instead.

## Install

```bash
composer require linkrobins/flarum-warble
php flarum migrate
```

`flarum/realtime` is installed automatically as a dependency. Then enable
**Realtime** and **Warble** in your admin panel. That's the whole setup:
with no websocket configured, Warble runs in polling mode immediately.

## How it works

Warble is a thin **companion to `flarum/realtime`** — realtime does all the
work (live posts, typing, notifications); Warble decides how the events
travel:

- **Polling (the default, zero infrastructure).** Broadcasts are written to a
  short-lived table in your database; each browser collects them by cursor
  every few seconds. Hidden tabs stop polling, idle tabs slow down, and
  every wait is jittered so tabs never stampede. Typing stays private the
  right way: who-is-typing names are decided on your server per reader, so a
  member who hides their online status is anonymous to everyone not
  permitted to see through it.
- **Websocket (bring your own, instant).** Add a `websocket` block to your
  `config.php` pointing at any Pusher-protocol server you run — [Laravel
  Reverb](https://reverb.laravel.com) or soketi both work — and Warble steps
  aside apart from keeping typing names working (realtime 2.0.0-rc.6 only
  sends them through its own bundled server; Warble restores them for
  relays).

The transport is chosen automatically and can be forced either way on the
Warble settings page.

Expect polling to be a few seconds behind rather than instant, and to add a
small request per visitor per interval — fine for small and mid-size
communities, which is exactly who can't run socket servers. A busy forum
should graduate to a websocket.

Realtime's own feature settings stay yours: typing indicators, discussion-list
typing dots, list update interval, notification toast duration, and the "view
who is typing" permission. We encourage you to open the Realtime settings page
and tune those to fit your forum — Warble never touches them.

Leave the key blank to disconnect. Outgrow the managed service? No lock-in:
flarum/realtime ships its own websocket daemon (`php flarum realtime:serve`) —
disconnect Warble and run the stock daemon on your own server any time.

> **Requirement:** your forum's `config.php` must be writable by the web server
> (it is on a standard Flarum install). Warble writes the connection there because
> that's where flarum/realtime reads it — the change takes effect immediately, no
> restart. If `config.php` is locked down, Warble tells you in the settings page.

## Troubleshooting: the settings page says "This extension has no configuration"

That page means your forum is serving an outdated compiled assets build, one
made before Warble was enabled, so the key field literally isn't in the
JavaScript your browser receives. This happens on some shared hosts when
Flarum's post-enable cache flush fails; reinstalling, purging, or clearing your
browser cache won't fix it, because the stale build lives on the server.

Warble now repairs this by itself: it checks the served build on admin page
loads and rebuilds it when it predates Warble. If it can't (or another
extension's script crashes the page before Warble loads), a red banner appears
at the bottom of the admin panel explaining exactly what's wrong in plain
language, with a one-click fix where possible. No SSH needed. If the banner
says the assets folder isn't writable, that part is for your hosting provider.

## Choosing a transport
- **Polling** — zero infrastructure, works on any hosting, a few seconds
  behind. The default when no websocket is configured.
- **Your own websocket** — instant. Any Pusher-protocol server works
  (Reverb, soketi); add its details under `websocket` in `config.php`.
- **`flarum/realtime`'s bundled daemon** — also instant, no Warble involved:
  run `php flarum realtime:serve` where you can keep a process alive.

Forums connected to the retired hosted Warble service keep working unchanged:
their written `config.php` connection is just a websocket configuration like
any other.

## Licensing
MIT, free, and standalone — nothing here depends on any external service.

## Support
- [Report a problem](https://github.com/linkrobins/warble/issues)
