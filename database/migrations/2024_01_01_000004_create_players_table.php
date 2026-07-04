<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `players` — a user's participation in one version (grain: user + version).
// Holds the denormalized `total_score` scoped to this version (~1M rows).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('registered_at')->nullable();
            // Precomputed per-version aggregate: avoids rescanning events.
            $table->unsignedInteger('total_score')->default(0);
            $table->string('language', 8)->nullable();
            $table->timestamps();

            // One participation per person per version.
            $table->unique(['version_id', 'user_id']);
            // Ordered detail export by registration date.
            $table->index(['version_id', 'registered_at']);

            $table->foreign('version_id')->references('id')->on('versions')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
