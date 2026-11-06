<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The short code people call an engineer by (e.g. ENG-0012). Office staff may have none.
            $table->string('user_code', 30)->nullable();
            // Profile picture, re-encoded by us and kept on the private disk; never a path the user chose.
            $table->string('photo_path')->nullable();
            $table->string('photo_thumb_path')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX users_user_code_active_unique ON users (lower(user_code)) WHERE deleted_at IS NULL AND user_code IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_user_code_active_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['user_code', 'photo_path', 'photo_thumb_path']);
        });
    }
};
