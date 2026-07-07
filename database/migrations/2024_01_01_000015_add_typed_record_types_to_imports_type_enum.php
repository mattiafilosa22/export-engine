<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Extends imports.type to accept the 3 direct-ingestion endpoints (transactions/
// answers/rewards) added as a safety net alongside the event-driven typed
// records. Laravel's schema builder has no portable "modify enum" helper, so
// this uses a raw statement (MySQL-only, consistent with the rest of the
// project's DB choice).
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE imports MODIFY type ENUM('players', 'events', 'transactions', 'answers', 'rewards')"
        );
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE imports MODIFY type ENUM('players', 'events')");
    }
};
