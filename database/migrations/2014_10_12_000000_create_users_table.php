<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `users` — the person's identity, stored once and reused across versions.
// Domain identity (unique email + optional external_id), not an auth model:
// no name/password. Participation lives in `players`.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            // 191: utf8mb4 index limit on legacy row formats (767 bytes / 4).
            $table->string('email', 191)->unique();
            // External SSO id; null on direct registration.
            $table->string('external_id', 100)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
