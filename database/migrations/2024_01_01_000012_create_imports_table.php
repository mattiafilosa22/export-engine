<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `imports` — the durable state of an async ingestion job (players/events batch).
// Mirror of `exports`: source of truth for the create/status endpoints, distinct
// from the queue `jobs` table (mechanism vs. queryable domain state).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->foreignId('version_id')->constrained('versions');
            $table->enum('type', ['players', 'events']);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');
            // Batch size, known at creation; counters filled by the worker.
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('error_message')->nullable();
            // The whole batch, for audit/retry; the job carries only the uuid and rereads.
            $table->json('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->index(['version_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
