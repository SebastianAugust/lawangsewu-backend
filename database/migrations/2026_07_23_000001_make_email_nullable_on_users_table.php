<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Login memakai username; email tidak lagi diminta saat owner
            // membuat akun kasir. Kolomnya sengaja tidak di-drop — email lama
            // tetap tersimpan, dan password_reset_tokens masih memakai email
            // sebagai primary key.
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
