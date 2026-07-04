<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `answers` — the per-question fact (append-only, no updated_at).
// Closed questions point to an answer option via `answer_option_id`; open ones use `answer_text`.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('event_id')->nullable();
            $table->unsignedBigInteger('question_id');
            $table->unsignedBigInteger('answer_option_id')->nullable();
            $table->string('answer_text', 500)->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('created_at')->useCurrent();

            // One answer per question per player (single_choice rule).
            $table->unique(['version_id', 'player_id', 'question_id']);
            // Distribution aggregation by answer option.
            $table->index(['version_id', 'question_id', 'answer_option_id']);

            $table->foreign('version_id')->references('id')->on('versions')->onDelete('restrict');
            $table->foreign('player_id')->references('id')->on('players')->onDelete('restrict');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('restrict');
            $table->foreign('question_id')->references('id')->on('questions')->onDelete('restrict');
            $table->foreign('answer_option_id')->references('id')->on('answer_options')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};
