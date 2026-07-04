<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Now that `players` exists, wire the deferred `events.player_id` FK.
// Also realign `events.version_id` from the Slice 1 `cascadeOnDelete`
// residue to RESTRICT, matching the doc (campaigns are soft-deleted).
// Columns, indexes and generated columns are left untouched.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['version_id']);
            $table->foreign('version_id')->references('id')->on('versions')->onDelete('restrict');
            $table->foreign('player_id')->references('id')->on('players')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
            $table->dropForeign(['version_id']);
            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
        });
    }
};
