<?php

/*
 * Warble — realtime for Flarum.
 */

namespace LinkRobins\Warble\Polling;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * May this actor read this channel?
 *
 * Mirrors flarum/realtime's websocket AuthController rule for rule — that
 * controller answers the same question for socket subscriptions, but its
 * answers come out as Pusher auth signatures bound to a socket id, which a
 * polling reader doesn't have. Any behaviour change upstream belongs here
 * too; each rule cites its source.
 *
 * Presence channels are refused: polling v1 carries no presence (upstream's
 * own presence payload is a stub), and refusing is safer than pretending.
 */
class ChannelGate
{
    /** Answers per (actor id, channel), so one poll checks each channel once. */
    protected array $memo = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function allows(User $actor, string $channel): bool
    {
        $key = $actor->id.'|'.$channel;

        return $this->memo[$key] ??= $this->decide($actor, $channel);
    }

    protected function decide(User $actor, string $channel): bool
    {
        // Public channels are public (asset revisions, index typing display).
        if ($channel === 'public' || $channel === 'public-index-typing') {
            return true;
        }

        // AuthController::indexTypingTag — visible tag, tags installed.
        if (preg_match('~^private-index-typing-tag=(?<id>[0-9]+)$~', $channel, $m)) {
            return class_exists(\Flarum\Tags\Tag::class)
                && \Flarum\Tags\Tag::whereVisibleTo($actor)->where('id', (int) $m['id'])->exists();
        }

        if (!preg_match('~^private-(?<subject>[a-zA-Z]+)=(?<id>[0-9]+)$~', $channel, $m)) {
            return false;
        }

        $id = (int) $m['id'];

        return match ($m['subject']) {
            // AuthController::user — your own channel and nobody else's.
            'user' => !$actor->isGuest() && $actor->id === $id,

            // AuthController::typing — anyone who can see the discussion.
            'typing' => Discussion::whereVisibleTo($actor)->where('id', $id)->exists(),

            // AuthController::privateMessageTyping — dialog visibility.
            'privateMessageTyping' => class_exists(\Flarum\Messages\Dialog::class)
                && \Flarum\Messages\Dialog::whereVisibleTo($actor)->where('id', $id)->exists(),

            // AuthController::typingIdentified — seeing through a hidden
            // online status needs the core override permission plus the
            // ordinary requirements for the typing indicator itself.
            'typingIdentified' => $this->typingIdentified($actor, $id),

            default => false,
        };
    }

    protected function typingIdentified(User $actor, int $id): bool
    {
        if (!$this->settings->get('flarum-realtime.typing-indicator')
            || !$actor->hasPermission('user.viewLastSeenAt')) {
            return false;
        }

        $discussion = Discussion::whereVisibleTo($actor)->find($id);

        return $discussion !== null
            && $actor->can('flarum-realtime.view-who-types', $discussion);
    }
}
