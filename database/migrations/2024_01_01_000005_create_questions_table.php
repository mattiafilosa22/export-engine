<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `questions` — small dimension. The text lives here once; `type` drives a rule
// (single_choice → one answer per player; open → free text, no answer options).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->string('code', 20);
            $table->string('text', 500);
            $table->enum('type', ['single_choice', 'multiple_choice', 'rating', 'open']);
            $table->smallInteger('position')->unsigned()->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'code']);

            $table->foreign('version_id')->references('id')->on('versions')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
