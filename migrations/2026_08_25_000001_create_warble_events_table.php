<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// The polling transport's relay: broadcasts and client events land here as
// rows and readers collect them by id cursor. Rows live for about two
// minutes (EventLog::RETENTION_SECONDS) — this is a wire, not a record.
// No foreign keys on purpose: a row must not block the user or discussion
// it mentions from being deleted, and rows are never joined.
return [
    'up' => function (Builder $schema) {
        // Which channels have a live poller, so realtime's occupancy reads
        // (getChannels: "who is connected?") have a truthful answer. One row
        // per channel, refreshed by polls, expired by silence.
        if (!$schema->hasTable('warble_presence')) {
            $schema->create('warble_presence', function (Blueprint $table) {
                $table->string('channel', 120)->primary();
                $table->dateTime('last_seen_at')->index();
            });
        }

        if (!$schema->hasTable('warble_events')) {
            $schema->create('warble_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('channel', 120)->index();
                $table->string('event', 120);
                // JSON payload exactly as broadcast; typing client events
                // store only {time} — identity is resolved per reader.
                $table->text('payload')->nullable();
                // The authenticated sender of a client event; broadcasts
                // from the server leave it null.
                $table->unsignedInteger('user_id')->nullable();
                // The sending browser tab's random poll id, so its own
                // events are not echoed back to it (Pusher semantics).
                $table->string('origin', 32)->nullable();
                $table->dateTime('created_at')->useCurrent()->index();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('warble_events');
        $schema->dropIfExists('warble_presence');
    },
];
