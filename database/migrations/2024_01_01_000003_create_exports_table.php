<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `exports` — the durable state of an async export job.
// Source of truth for the three endpoints (create / status / download).
// Small/operational table: physical FK on `version_id`.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->foreignId('version_id')->constrained('versions');
            $table->json('params');
            $table->string('format', 10)->default('xlsx');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])
                ->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->string('file_path', 255)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->index(['version_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
