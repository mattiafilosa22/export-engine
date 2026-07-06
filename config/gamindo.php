<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    |
    | Synchronous boundary limits and asynchronous chunking for the hybrid
    | ingestion pipeline. `max_batch_rows` caps a single request (413 above it);
    | `chunk_size` is the transactional bulk-insert size used by the worker.
    |
    */

    'ingestion' => [
        'max_batch_rows' => (int) env('GAMINDO_MAX_BATCH_ROWS', 5000),
        'chunk_size' => (int) env('GAMINDO_INGEST_CHUNK_SIZE', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    |
    | Whitelists that drive the fully configurable export. There are no
    | predefined exports: the client composes each sheet (source, columns,
    | filters, group_by, sort) and the engine builds the query from these
    | whitelists. A source declares its Eloquent model, the selectable fields
    | (public alias => real/virtual column), the filterable fields (with a bound
    | operator) and which aggregate functions may be applied to which fields.
    | No user string ever reaches SQL as an identifier. Adding a source is a
    | config-only change. `keyset_chunk` sizes the keyset detail read;
    | `max_sheets` caps sheets per request.
    |
    */

    'export' => [
        'keyset_chunk' => (int) env('GAMINDO_EXPORT_KEYSET_CHUNK', 1000),
        'max_sheets' => (int) env('GAMINDO_EXPORT_MAX_SHEETS', 10),

        // Aggregate functions the engine can build (safe, fixed SQL templates).
        'aggregations' => ['count', 'count_distinct', 'avg', 'sum', 'min', 'max'],

        // Sheet name => source. Lets the client name a sheet
        // instead of naming a source; an explicit `source` always wins.
        'sheet_source_map' => [
            'players' => 'players',
            'events_summary' => 'events',
        ],

        // Named metric => aggregate column (fn + optional field). The metric
        // name becomes the column label. The resulting fn/field must still be
        // allowed by the sheet's source `aggregatable` whitelist.
        'metric_aggregates' => [
            'count' => ['fn' => 'count'],
            'unique_players' => ['fn' => 'count_distinct', 'field' => 'player_id'],
            'avg_score' => ['fn' => 'avg', 'field' => 'score'],
        ],

        'sources' => [

            'events' => [
                'model' => \App\Models\Event::class,
                // public alias => real (possibly virtual) column
                'fields' => [
                    'id' => 'id',
                    'type' => 'type',
                    'occurred_at' => 'occurred_at',
                    'player_id' => 'player_id',
                    'language' => 'payload_language',
                    'utm_source' => 'payload_utm_source',
                    'score' => 'payload_score',
                ],
                'default_columns' => ['id', 'type', 'occurred_at', 'language', 'score'],
                // filter alias => [column, op]
                'filters' => [
                    'type' => ['column' => 'type', 'op' => 'in'],
                    'language' => ['column' => 'payload_language', 'op' => 'in'],
                    'utm_source' => ['column' => 'payload_utm_source', 'op' => 'in'],
                    'score' => ['column' => 'payload_score', 'op' => 'eq'],
                    'occurred_from' => ['column' => 'occurred_at', 'op' => 'gte'],
                    'occurred_to' => ['column' => 'occurred_at', 'op' => 'lte'],
                ],
                // fn => field aliases it may aggregate ('*' means COUNT(*)).
                'aggregatable' => [
                    'count' => ['*'],
                    'count_distinct' => ['player_id', 'type'],
                    'avg' => ['score'],
                    'sum' => ['score'],
                    'min' => ['score', 'occurred_at'],
                    'max' => ['score', 'occurred_at'],
                ],
                'sort' => ['id', 'occurred_at', 'score'],
            ],

            // Join source: players + users (for email). `version_column`/`key` are
            // qualified because of the join; joins are config data, not per-source code.
            'players' => [
                'model' => \App\Models\Player::class,
                'version_column' => 'players.version_id',
                'key' => 'players.id',
                'joins' => [
                    ['type' => 'inner', 'table' => 'users', 'alias' => 'users',
                     'on' => [['users.id', '=', 'players.user_id']]],
                ],
                'fields' => [
                    'player_id' => 'players.id',
                    'email' => 'users.email',
                    'registered_at' => 'players.registered_at',
                    'total_score' => 'players.total_score',
                    'language' => 'players.language',
                ],
                'default_columns' => ['player_id', 'email', 'registered_at', 'total_score'],
                'filters' => [
                    'language' => ['column' => 'players.language', 'op' => 'in'],
                ],
                'aggregatable' => [
                    'count' => ['*'],
                    'count_distinct' => ['player_id'],
                    'sum' => ['total_score'],
                    'avg' => ['total_score'],
                ],
                'sort' => ['player_id', 'registered_at', 'total_score'],
            ],

        ],
    ],

];
