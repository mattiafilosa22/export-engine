<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Idempotency key for the direct transactions/rewards ingestion endpoints, same
// contract as events.dedup_key: unique per (version_id, dedup_key), nullable on
// purpose (MySQL never collides NULLs in a UNIQUE) — keyed rows dedup, keyless
// rows always append. Event-driven typed records (event_id set) never use this;
// only direct POSTs (event_id NULL) do.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dedup_key', 64)->nullable()->after('external_ref');
            $table->unique(['version_id', 'dedup_key']);
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->string('dedup_key', 64)->nullable()->after('reward_code');
            $table->unique(['version_id', 'dedup_key']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['version_id', 'dedup_key']);
            $table->dropColumn('dedup_key');
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->dropUnique(['version_id', 'dedup_key']);
            $table->dropColumn('dedup_key');
        });
    }
};
