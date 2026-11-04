<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('code', 30)->unique();
            $table->string('name');
            // Hours allowed from the moment a ticket is received until it should be closed.
            $table->unsignedInteger('sla_hours');
            // Reserved for the per-type checklist the technician fills in on close.
            $table->jsonb('form_schema')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('reason_codes', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('type', 20);
            $table->string('code', 40);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['type', 'code']);
        });

        DB::statement("ALTER TABLE reason_codes ADD CONSTRAINT reason_codes_type_check CHECK (type IN ('pending', 'negative'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reason_codes');
        Schema::dropIfExists('ticket_types');
    }
};
