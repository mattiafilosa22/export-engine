<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `rewards` — granted/redeemed prizes (append-only). Lifecycle via `status`.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('event_id')->nullable();
            $table->string('reward_type', 40);
            $table->string('reward_code', 100)->nullable();
            $table->enum('status', ['granted', 'redeemed', 'expired']);
            $table->dateTime('granted_at');
            $table->dateTime('redeemed_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['version_id', 'status']);
            $table->index('player_id');

            $table->foreign('version_id')->references('id')->on('versions')->onDelete('restrict');
            $table->foreign('player_id')->references('id')->on('players')->onDelete('restrict');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
