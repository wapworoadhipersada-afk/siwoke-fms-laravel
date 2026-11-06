<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // What people read in screens; `name` stays the stable identifier used in code.
            $table->string('label', 100)->nullable();
            $table->string('description', 300)->nullable();
            // The six built-in roles cannot be renamed or deleted.
            $table->boolean('is_system')->default(false);
            // Once an administrator edits a role's permissions, a deploy must stop re-syncing it to the defaults.
            $table->boolean('is_customized')->default(false);
        });

        DB::table('roles')->whereIn('name', ['super-admin', 'managerial', 'leader', 'coordinator', 'operational-admin', 'technician'])->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label', 'description', 'is_system', 'is_customized']);
        });
    }
};
