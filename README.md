# Chirp for Flarum

**Realtime for your Flarum forum — one paste, done.** `flarum-chirp` connects
your forum to [Chirp](https://linkrobins.com/chirp), a hosted realtime service
built for Flarum: live discussions, typing indicators, and presence over
WebSockets, without running your own socket server or wrestling with Pusher
clusters and message caps.

> **Status: pre-release / work in progress.** This extension is a fork of
> `flarum/realtime` with one-field setup. Not yet published to Packagist.

## How it works
1. Install the extension.
2. Paste your Chirp **key** in the settings — the extension resolves the endpoint
   and the rest of the config automatically. No host, port, cluster, or secret to
   hand-enter.
3. Your forum goes realtime, served from `wss://{you}.chirp.linkrobins.com`.

## Why Chirp over raw Pusher
- **Flat, predictable pricing** — from $49/**year** (Pusher's entry is $49/**month**), unlimited messages.
- **Flarum-native** — no Pusher account, no cluster config; one key.
- **Outgrow it? Take it with you.** This extension is open source — point it at
  your own self-hosted Reverb (or the public
  [flarum-docker](https://github.com/linkrobins/flarum) stack) any time.

## Open source
The extension is MIT-licensed and free. The paid part is the **managed service**
that runs the realtime server for you — see <https://linkrobins.com/chirp>.

Built by [Link Robins](https://linkrobins.com).
