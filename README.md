# Warble for Flarum

[![Latest Version](https://img.shields.io/packagist/v/linkrobins/flarum-warble)](https://packagist.org/packages/linkrobins/flarum-warble)
[![Downloads](https://img.shields.io/packagist/dt/linkrobins/flarum-warble)](https://packagist.org/packages/linkrobins/flarum-warble)
[![License](https://img.shields.io/packagist/l/linkrobins/flarum-warble)](https://github.com/linkrobins/warble/blob/main/LICENSE)
[![Backend](https://github.com/linkrobins/warble/actions/workflows/backend.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/backend.yml)
[![Frontend](https://github.com/linkrobins/warble/actions/workflows/frontend.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/frontend.yml)
[![Realtime Compatibility](https://github.com/linkrobins/warble/actions/workflows/realtime-compat.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/realtime-compat.yml)

**Realtime for your Flarum forum — one paste, done.** `flarum-warble` connects
your forum to [Warble](https://linkrobins.com/warble), a hosted realtime service
built for Flarum: live discussions, typing indicators, and presence over
WebSockets, without running your own socket server or wrestling with Pusher
clusters and message caps.

## Install

```bash
composer require linkrobins/flarum-warble
```

`flarum/realtime` is installed automatically as a dependency. Then enable
**Realtime** and **Warble** in your admin panel.

## How it works
Warble is a thin **companion to `flarum/realtime`** — realtime does all the work
(live discussions, typing, presence); Warble just points it at the managed Warble
websocket service so you never run a websocket daemon or edit any config.

1. `composer require linkrobins/flarum-warble`, then enable **Realtime** and
   **Warble** in the admin panel.
2. Paste your Warble **key** (from your linkrobins.com dashboard → Warble) into
   the one field on the Warble settings page.
3. Done. Warble exchanges the key for your connection config and writes
   flarum/realtime's `websocket` block into your forum's `config.php`
   (`js-client`, `php-client`, `app-key`/`secret`) — pointed at
   `wss://warble-{you}.linkrobins.com`. **The connection is handled for you** —
   no hosts, ports, or keys to configure.

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

## Why Warble over raw Pusher
- **Flat, predictable pricing** — from $49/**year** (Pusher's entry is $49/**month**), unlimited messages.
- **Flarum-native** — no Pusher account, no cluster config; one key.
- **Outgrow it? No lock-in.** `flarum/realtime` bundles its own websocket
  daemon — disconnect Warble and run `php flarum realtime:serve` on your own
  server; your forum keeps every realtime feature.

## Licensing
This extension is MIT-licensed and free. **Warble itself is a hosted service by
Link Robins** — it is not a self-hostable product; see <https://linkrobins.com/warble>.
If you'd rather self-host realtime, you don't need Warble at all: that's just
`flarum/realtime` in its stock form, running its own bundled daemon.

Built by [Link Robins](https://linkrobins.com).
