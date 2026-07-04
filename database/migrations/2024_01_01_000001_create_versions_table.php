<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `versions` — the published campaign/game: root that everything attaches to.
// Few rows; public non-enumerable uuid in URLs.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->string('name', 150);
            $table->string('client_name', 150)->nullable();
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions');
    }
};
