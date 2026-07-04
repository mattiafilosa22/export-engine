<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `answer_options` — the catalog of a question's options (a dimension).
// Options are an entity, not sparse strings: integrity + zero-answer options
// still appear in the export. Correctness lives here, not on each answer.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('answer_options', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('question_id');
            $table->string('code', 20)->nullable();
            $table->string('label', 255);
            $table->smallInteger('position')->unsigned();
            // Quiz correctness; null for surveys/rating.
            $table->boolean('is_correct')->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'label']);
            $table->index(['question_id', 'position']);

            $table->foreign('version_id')->references('id')->on('versions')->onDelete('restrict');
            $table->foreign('question_id')->references('id')->on('questions')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_options');
    }
};
