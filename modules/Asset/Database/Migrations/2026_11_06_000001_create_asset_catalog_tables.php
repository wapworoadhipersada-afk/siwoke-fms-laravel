<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('code', 30)->unique();
            $table->string('name');
            // Serialized: every unit is tracked individually. Quantity: only balances per holder are tracked.
            $table->string('tracking_mode', 20);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE asset_categories ADD CONSTRAINT asset_categories_tracking_mode_check CHECK (tracking_mode IN ('serialized', 'quantity'))");

        Schema::create('asset_models', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->string('brand', 100);
            $table->string('model_name', 100);
            $table->jsonb('spec')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE UNIQUE INDEX asset_models_unique_active ON asset_models (asset_category_id, lower(brand), lower(model_name)) WHERE deleted_at IS NULL');

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->foreignId('pic_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE UNIQUE INDEX warehouses_code_active_unique ON warehouses (lower(code)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('asset_models');
        Schema::dropIfExists('asset_categories');
    }
};
