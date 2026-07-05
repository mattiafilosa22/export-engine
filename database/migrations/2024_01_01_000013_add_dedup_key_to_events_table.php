<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Idempotency key for at-least-once ingestion: unique per (version_id, dedup_key).
// Nullable on purpose — MySQL does not collide NULLs in a UNIQUE: keyed rows
// dedup, keyless rows always append (the API contract makes the key optional).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('dedup_key', 64)->nullable()->after('payload');
            $table->unique(['version_id', 'dedup_key']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['version_id', 'dedup_key']);
            $table->dropColumn('dedup_key');
        });
    }
};
