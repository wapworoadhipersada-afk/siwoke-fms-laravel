<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_uid', 100);
            $table->string('platform', 20);
            $table->string('device_name', 100)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->text('push_token')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('blocked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'device_uid']);
        });

        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_platform_check CHECK (platform IN ('android', 'ios'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
