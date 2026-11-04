<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('ulid')->nullable()->after('id');
            $table->string('phone', 32)->nullable();
            $table->string('employee_no', 32)->nullable();
            $table->string('user_type', 20)->default('staff');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->softDeletes();
        });

        foreach (DB::table('users')->whereNull('ulid')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['ulid' => (string) Str::ulid()]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->ulid('ulid')->nullable(false)->change();
            $table->unique('ulid');
            $table->dropUnique(['email']);
        });

        // Uniqueness only applies to live rows, so a soft-deleted user frees its email and employee number.
        DB::statement('CREATE UNIQUE INDEX users_email_active_unique ON users (lower(email)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_employee_no_active_unique ON users (employee_no) WHERE deleted_at IS NULL AND employee_no IS NOT NULL');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_user_type_check CHECK (user_type IN ('staff', 'technician'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_user_type_check');
        DB::statement('DROP INDEX users_employee_no_active_unique');
        DB::statement('DROP INDEX users_email_active_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
            $table->dropUnique(['ulid']);
            $table->dropSoftDeletes();
            $table->dropColumn(['ulid', 'phone', 'employee_no', 'user_type', 'is_active', 'last_login_at']);
        });
    }
};
