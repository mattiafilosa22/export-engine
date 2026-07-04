<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `transactions` — money movements (append-only). Strong columns, never JSON:
// DECIMAL for exact arithmetic, direction via the `type` enum.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('event_id')->nullable();
            $table->enum('type', ['purchase', 'spend', 'refund']);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->enum('status', ['pending', 'completed', 'failed']);
            $table->string('external_ref', 100)->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('created_at')->useCurrent();

            $table->index(['version_id', 'occurred_at']);
            $table->index('player_id');

            $table->foreign('version_id')->references('id')->on('versions')->onDelete('restrict');
            $table->foreign('player_id')->references('id')->on('players')->onDelete('restrict');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
