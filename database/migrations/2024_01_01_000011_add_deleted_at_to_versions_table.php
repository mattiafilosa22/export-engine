<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Soft-delete for versions: campaigns are retired without cascading deletes,
// consistent with the RESTRICT foreign keys on their children.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
