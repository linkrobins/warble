# Warble for Flarum

[![Latest Version](https://img.shields.io/packagist/v/linkrobins/flarum-warble)](https://packagist.org/packages/linkrobins/flarum-warble)
[![Downloads](https://img.shields.io/packagist/dt/linkrobins/flarum-warble)](https://packagist.org/packages/linkrobins/flarum-warble)
[![License](https://img.shields.io/packagist/l/linkrobins/flarum-warble)](https://github.com/linkrobins/warble/blob/main/LICENSE)
[![Backend](https://github.com/linkrobins/warble/actions/workflows/backend.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/backend.yml)
[![Frontend](https://github.com/linkrobins/warble/actions/workflows/frontend.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/frontend.yml)
[![Realtime Compatibility](https://github.com/linkrobins/warble/actions/workflows/realtime-compat.yml/badge.svg)](https://github.com/linkrobins/warble/actions/workflows/realtime-compat.yml)


**Realtime for your Flarum forum, with no server to run.** Warble makes `flarum/realtime` work on any hosting, including shared hosting where a websocket server is impossible. New posts, typing indicators and notifications reach your visitors a few seconds after they happen, and the only thing it needs is your forum.

## Install

```bash
composer require linkrobins/flarum-warble
php flarum migrate
php flarum cache:clear
```

`flarum/realtime` is installed automatically as a dependency. Enable **Realtime** and **LR Warble** in your admin panel and you are done. There is no key to paste, no server to connect and no cron job to set up.

## How it works

Warble is a companion to `flarum/realtime`. Realtime does all the live work: new posts, typing indicators, notifications and discussion-list updates. Warble changes only how those updates travel.

Instead of a websocket, Warble uses **polling**. When something happens, the update is written to a short-lived table in your forum's database. Each visitor's browser asks your forum for new updates every few seconds and shows them. Hidden tabs stop asking entirely, tabs left idle for a few minutes ask less often, and every wait is slightly randomised so open tabs never all ask at once. Old updates are cleaned up as new ones arrive, so the table stays small with no scheduled job.

Typing indicators stay private the right way: who is typing is decided on your server for each reader, so a member who hides their online status is never named to someone who is not allowed to see through it.

## Settings

The LR Warble page in your admin panel opens with a **Realtime health** checklist of everything Warble depends on, as your forum sees it right now:

- whether the Realtime extension is enabled
- whether browsers can reach the polling address (a firewall or security plugin can block it)
- how your queue hands updates over, and when that needs the scheduler (cron) to be running
- when a browser last checked for updates, and when the last update was sent
- a leftover websocket section in `config.php` from the retired hosted service, if there is one

**Check again** runs it once more. Below it is the one setting:

- **Polling interval:** how often each visitor's browser asks for updates, from 2 to 30 seconds (3 by default). Lower is snappier and busier, higher is gentler on small hosting.

Realtime's own options stay on the Realtime extension's page: typing indicators, discussion-list typing dots, list update interval, notification toast duration, and who may see who is typing. Warble never changes them.

## What to expect

Updates arrive a few seconds after they happen rather than instantly, and each open tab makes one small request per interval. That suits small and mid-size communities, which are exactly the ones that cannot run a websocket server.

If your forum outgrows polling and you can keep a process running on your server, disable Warble and run Realtime's own websocket server instead (`php flarum realtime:serve`, see the Realtime extension's documentation). Warble only does polling: it does not connect to websocket servers, its own or anyone else's.

## Troubleshooting

### New posts take minutes to appear

The Realtime health checklist on Warble's settings page shows your queue and scheduler. Realtime hands every update to Flarum's queue before Warble can deliver it. On a standard install the queue is `sync`, which sends updates immediately. If your forum uses a queue that is processed by a cron job or a worker (a database or Redis queue, for example), updates wait until that queue runs, so a queue processed every 15 minutes means updates up to 15 minutes late. `php flarum info` shows your queue driver.

### Upgrading from the hosted Warble service

The hosted service is retired. Upgrading removes its old settings, including any setup key. If your `config.php` still has a `websocket` section from that time, Warble ignores it and polls anyway. You can delete that section; it is only needed if you later switch to Realtime's own websocket server, and then it has to point at your server.

### The settings page says "This extension has no configuration"

That page means your forum is serving an outdated compiled build, one made before Warble was enabled. This happens on some shared hosts when Flarum's post-enable cache flush fails. Reinstalling, purging or clearing your browser cache won't fix it, because the stale build lives on the server.

Warble repairs this by itself: it checks the served build on admin page loads and rebuilds it when it predates Warble. If it can't (or another extension's script crashes the page before Warble loads), a red banner appears at the bottom of the admin panel explaining what's wrong in plain language, with a one-click fix where possible. No SSH needed. If the banner says the assets folder isn't writable, that part is for your hosting provider.

## License

MIT, free, and standalone: nothing here depends on any external service.

## Support

- [Report a problem](https://github.com/linkrobins/warble/issues)
