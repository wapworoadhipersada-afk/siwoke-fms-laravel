<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            // What must be recorded before a visit may end with each result, e.g. {"finish": {"install": 1, "uninstall": 1}}.
            $table->jsonb('asset_rules')->default(DB::raw("'{}'::jsonb"));
        });

        Schema::create('ticket_asset_lines', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained('ticket_visits')->restrictOnDelete();
            $table->string('action', 10);
            // A serialized asset (EDC, SIM, SAM) for install / uninstall; empty for a consumable.
            $table->foreignId('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->foreignId('asset_model_id')->constrained('asset_models')->restrictOnDelete();
            $table->foreignId('owner_vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('notes')->nullable();
            // Lets the phone resend a line after a bad connection without recording it twice.
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // Set when the visit finished and the custody change really happened in the asset ledger.
            $table->foreignId('applied_movement_id')->nullable()->constrained('asset_movements')->restrictOnDelete();
            // A line of a visit that ended pending / done negative is kept for the record but never applied.
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 120)->nullable();
            $table->timestampsTz();

            $table->index(['ticket_id', 'visit_id']);
        });

        DB::statement("ALTER TABLE ticket_asset_lines ADD CONSTRAINT ticket_asset_lines_action_check CHECK (action IN ('install', 'uninstall', 'consume'))");
        DB::statement('ALTER TABLE ticket_asset_lines ADD CONSTRAINT ticket_asset_lines_quantity_check CHECK (quantity > 0)');
        // A serialized line names its asset (one unit); a consumable line has a quantity and no single asset.
        DB::statement("ALTER TABLE ticket_asset_lines ADD CONSTRAINT ticket_asset_lines_shape_check CHECK ((action = 'consume' AND asset_id IS NULL) OR (action <> 'consume' AND asset_id IS NOT NULL AND quantity = 1))");
        // The same asset cannot be installed and pulled out in one visit, or listed twice.
        DB::statement('CREATE UNIQUE INDEX ticket_asset_lines_asset_once ON ticket_asset_lines (visit_id, asset_id) WHERE asset_id IS NOT NULL AND voided_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_asset_lines');

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('asset_rules');
        });
    }
};
