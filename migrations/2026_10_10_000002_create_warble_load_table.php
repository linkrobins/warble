<?php

/*
 * Warble: realtime for Flarum over polling.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// What polling costs this host, one row per minute: how many polls and how
// many milliseconds of request time they took. AutoInterval reads the last
// few minutes to choose the polling interval and deletes older rows.
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('warble_load')) {
            $schema->create('warble_load', function (Blueprint $table) {
                // Unix minute (seconds / 60).
                $table->unsignedInteger('minute')->primary();
                $table->unsignedInteger('polls')->default(0);
                $table->unsignedBigInteger('busy_ms')->default(0);
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('warble_load');
    },
];
