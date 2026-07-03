# Warble for Flarum

**Realtime for your Flarum forum — one paste, done.** `flarum-warble` connects
your forum to [Warble](https://linkrobins.com/warble), a hosted realtime service
built for Flarum: live discussions, typing indicators, and presence over
WebSockets, without running your own socket server or wrestling with Pusher
clusters and message caps.

> **Status: pre-release / work in progress.** Not yet published to Packagist.

## How it works
Warble is a thin **companion to `flarum/realtime`** — realtime does all the work
(live discussions, typing, presence); Warble just points it at the managed Warble
websocket service so you never run a websocket daemon or edit any config.

1. Install + enable **`flarum/realtime`** and **this extension**.
2. Paste your Warble **key** (from your linkrobins.com dashboard → Realtime) into
   the one field on the Warble settings page.
3. Done. Warble exchanges the key for your connection config and writes
   flarum/realtime's `websocket` block into your forum's `config.php`
   (`js-client`, `php-client`, `app-key`/`secret`) — pointed at
   `wss://warble-{you}.linkrobins.com`. **You never open the Realtime extension's
   settings.**

Leave the key blank to disconnect. Outgrow the managed service? It's open
source — point flarum/realtime at your own Reverb any time.

> **Requirement:** your forum's `config.php` must be writable by the web server
> (it is on a standard Flarum install). Warble writes the connection there because
> that's where flarum/realtime reads it — the change takes effect immediately, no
> restart. If `config.php` is locked down, Warble tells you in the settings page.

## Why Warble over raw Pusher
- **Flat, predictable pricing** — from $49/**year** (Pusher's entry is $49/**month**), unlimited messages.
- **Flarum-native** — no Pusher account, no cluster config; one key.
- **Outgrow it? Take it with you.** This extension is open source — point it at
  your own self-hosted Reverb (or the public
  [flarum-docker](https://github.com/linkrobins/flarum) stack) any time.

## Open source
The extension is MIT-licensed and free. The paid part is the **managed service**
that runs the realtime server for you — see <https://linkrobins.com/warble>.

Built by [Link Robins](https://linkrobins.com).
