<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('code', 30);
            $table->string('name');
            // Materialized path of ids from the root, e.g. "/1/5/23/", so a whole subtree is one prefix match.
            $table->text('path')->default('');
            $table->unsignedSmallInteger('depth')->default(1);
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_type_check CHECK (type IN ('province', 'city', 'area', 'service_point'))");
        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_root_is_province_check CHECK ((type = 'province') = (parent_id IS NULL))");
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_depth_check CHECK (depth BETWEEN 1 AND 4)');
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
        DB::statement('CREATE UNIQUE INDEX locations_type_code_active_unique ON locations (type, lower(code)) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX locations_path_index ON locations (path text_pattern_ops)');
        DB::statement('CREATE INDEX locations_parent_id_index ON locations (parent_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
