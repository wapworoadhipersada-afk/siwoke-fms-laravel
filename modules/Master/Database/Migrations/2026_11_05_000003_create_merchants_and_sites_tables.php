<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('merchant_code', 40);
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('category', 100)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Target of the composite foreign keys that keep a terminal on its merchant's own vendor.
            $table->unique(['id', 'vendor_id']);
        });

        DB::statement('CREATE UNIQUE INDEX merchants_vendor_code_active_unique ON merchants (vendor_id, lower(merchant_code)) WHERE deleted_at IS NULL');

        Schema::create('merchant_sites', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('name')->nullable();
            $table->text('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('pic_name')->nullable();
            $table->string('pic_phone', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['id', 'merchant_id']);
            $table->index('location_id');
        });

        DB::statement('ALTER TABLE merchant_sites ADD CONSTRAINT merchant_sites_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE merchant_sites ADD CONSTRAINT merchant_sites_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_sites');
        Schema::dropIfExists('merchants');
    }
};
