<?php

/*
 * Warble: realtime for Flarum over polling.
 */

use Illuminate\Database\Schema\Builder;

// Warble runs realtime over polling only. These settings belonged to the
// retired hosted service (the setup key and what it wrote back) and to the
// transport switch that chose between polling and a websocket. Nothing reads
// them any more; the setup key in particular should not linger in a database.
//
// config.php is left alone: a `websocket` block written there in the hosted
// era is ignored while Warble is enabled, and removing it is the owner's call.
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->whereIn('key', [
            'linkrobins-warble.setup-token',
            'linkrobins-warble.service-url',
            'linkrobins-warble.connected',
            'linkrobins-warble.host',
            'linkrobins-warble.config-write-failed',
            'linkrobins-warble.transport',
        ])->delete();
    },

    'down' => function (Builder $schema) {
        // Nothing to restore: the settings had no effect once removed.
    },
];
