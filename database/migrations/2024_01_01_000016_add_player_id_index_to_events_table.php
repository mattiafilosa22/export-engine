<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Single-column index (not composite, keeping the write-heavy events table's
// index footprint minimal): enables the Data_Quality invalid_event_order
// check to JOIN against players by player_id without a full table scan at
// 10M-row scale.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['player_id']);
        });
    }
};
