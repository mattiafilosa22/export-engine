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

];
