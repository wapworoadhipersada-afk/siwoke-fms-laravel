<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the office needs to know about a field engineer: a username, how they are employed, their national ID
 * number (NIK) and where they live. All optional in the table (office accounts have none); the engineer form
 * makes them required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable();
            $table->string('employment_status', 20)->nullable();
            $table->string('nik', 16)->nullable();
            $table->text('address')->nullable();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_employment_status_check CHECK (employment_status IS NULL OR employment_status IN ('freelance', 'pkwt', 'tetap', 'kontrak'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_nik_check CHECK (nik IS NULL OR nik ~ '^[0-9]{16}$')");
        DB::statement('CREATE UNIQUE INDEX users_username_active_unique ON users (lower(username)) WHERE deleted_at IS NULL AND username IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_nik_active_unique ON users (nik) WHERE deleted_at IS NULL AND nik IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_nik_active_unique');
        DB::statement('DROP INDEX users_username_active_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_nik_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_employment_status_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'employment_status', 'nik', 'address']);
        });
    }
};
