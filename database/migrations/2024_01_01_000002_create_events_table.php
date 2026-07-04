<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `events` — the append-only firehose (~10M rows). Monotonic PK, no `updated_at`.
// Hot JSON fields promoted to indexable generated VIRTUAL columns.
// FK on `version_id` for DB-level integrity; `player_id` stays a plain column
// until the `players` table exists (Slice 2).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('player_id');
            $table->string('type', 40);
            $table->dateTime('occurred_at');
            $table->json('payload');

            // Generated columns: materialized only in the index, payload stays lean.
            $table->string('payload_language', 8)
                ->virtualAs("json_unquote(json_extract(payload, '$.language'))")
                ->nullable();
            $table->string('payload_utm_source', 64)
                ->virtualAs("json_unquote(json_extract(payload, '$.utm_source'))")
                ->nullable();
            $table->integer('payload_score')
                ->virtualAs("cast(json_extract(payload, '$.score') as signed)")
                ->nullable();

            $table->dateTime('created_at')->useCurrent();

            // The composite (version_id, occurred_at) already satisfies the index the
            // FK needs (leftmost prefix): no redundant single-column index.
            $table->index(['version_id', 'occurred_at']);
            $table->index(['version_id', 'type', 'occurred_at']);
            $table->index(['version_id', 'type', 'payload_language', 'payload_utm_source']);

            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
