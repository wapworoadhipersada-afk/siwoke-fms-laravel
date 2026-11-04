<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HOLDERS = "('warehouse', 'user', 'terminal', 'external')";

    public function up(): void
    {
        // Workflow of a two-step handover. Mutable by design; the movement it produces is not.
        Schema::create('asset_handovers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('type', 30);
            $table->string('status', 20)->default('pending');
            $table->string('from_holder_type', 20);
            $table->unsignedBigInteger('from_holder_id')->nullable();
            $table->string('to_holder_type', 20);
            $table->unsignedBigInteger('to_holder_id')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('note')->nullable();
            $table->text('resolution_note')->nullable();
            $table->unsignedBigInteger('movement_id')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'to_holder_type', 'to_holder_id']);
        });

        DB::statement("ALTER TABLE asset_handovers ADD CONSTRAINT asset_handovers_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'cancelled'))");

        // The ledger: one immutable row per completed movement.
        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('movement_no', 30)->unique();
            $table->string('type', 30);
            $table->string('from_holder_type', 20)->nullable();
            $table->unsignedBigInteger('from_holder_id')->nullable();
            $table->string('to_holder_type', 20)->nullable();
            $table->unsignedBigInteger('to_holder_id')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('handover_id')->nullable()->constrained('asset_handovers')->restrictOnDelete();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('occurred_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // Not a foreign key on purpose: a ledger row must never be touched by a cascade.
            $table->unsignedBigInteger('device_id')->nullable();
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['reference_type', 'reference_id']);
            $table->index('occurred_at');
        });

        Schema::table('asset_handovers', function (Blueprint $table) {
            $table->foreign('movement_id')->references('id')->on('asset_movements')->restrictOnDelete();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('asset_model_id')->constrained('asset_models')->restrictOnDelete();
            // Every asset belongs to a vendor (bank); the company owns none.
            $table->foreignId('owner_vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('serial_number', 100);
            $table->string('asset_tag', 50)->nullable();
            $table->string('condition', 20)->default('good');
            $table->string('status', 20)->default('in_stock');
            // Snapshot of where the asset is now. The ledger is the source of truth; this makes "where is it?" one lookup.
            $table->string('holder_type', 20);
            $table->unsignedBigInteger('holder_id')->nullable();
            $table->foreignId('pending_handover_id')->nullable()->constrained('asset_handovers')->nullOnDelete();
            $table->foreignId('last_movement_id')->nullable()->constrained('asset_movements')->restrictOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['holder_type', 'holder_id']);
            $table->index('status');
            $table->index('owner_vendor_id');
        });

        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_status_check CHECK (status IN ('in_stock', 'in_transit', 'with_technician', 'installed', 'in_repair', 'retired', 'lost'))");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_condition_check CHECK (condition IN ('good', 'damaged', 'needs_repair'))");
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_holder_type_check CHECK (holder_type IN '.self::HOLDERS.')');
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_external_has_no_id_check CHECK ((holder_type = 'external') = (holder_id IS NULL))");
        // Status and holder must tell the same story.
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_status_holder_check CHECK (
            (status <> 'in_stock' OR holder_type = 'warehouse')
            AND (status <> 'with_technician' OR holder_type = 'user')
            AND (status <> 'installed' OR holder_type = 'terminal')
            AND (status NOT IN ('in_repair', 'lost', 'retired') OR holder_type = 'external')
            AND (status <> 'in_transit' OR holder_type IN ('warehouse', 'user'))
        )");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_in_transit_has_handover_check CHECK ((status = 'in_transit') = (pending_handover_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX assets_model_serial_active_unique ON assets (asset_model_id, lower(serial_number)) WHERE deleted_at IS NULL');

        Schema::create('asset_movement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movement_id')->constrained('asset_movements')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->foreignId('asset_model_id')->constrained('asset_models')->restrictOnDelete();
            $table->foreignId('owner_vendor_id')->constrained('vendors')->restrictOnDelete();
            // Signed so stock adjustments can record a decrease.
            $table->integer('quantity')->default(1);

            $table->index('movement_id');
            $table->index('asset_id');
        });

        DB::statement('ALTER TABLE asset_movement_items ADD CONSTRAINT asset_movement_items_quantity_check CHECK ((asset_id IS NOT NULL AND quantity = 1) OR (asset_id IS NULL AND quantity <> 0))');

        Schema::create('asset_handover_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('handover_id')->constrained('asset_handovers')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->foreignId('asset_model_id')->constrained('asset_models')->restrictOnDelete();
            $table->foreignId('owner_vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            // Where the asset stood before it went in transit, so a cancelled handover can put it back.
            $table->string('from_status', 20)->nullable();

            $table->index('handover_id');
        });

        Schema::create('asset_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_model_id')->constrained('asset_models')->restrictOnDelete();
            $table->foreignId('owner_vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('holder_type', 20);
            $table->unsignedBigInteger('holder_id');
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['asset_model_id', 'owner_vendor_id', 'holder_type', 'holder_id'], 'asset_stocks_balance_unique');
        });

        DB::statement("ALTER TABLE asset_stocks ADD CONSTRAINT asset_stocks_holder_type_check CHECK (holder_type IN ('warehouse', 'user'))");
        DB::statement('ALTER TABLE asset_stocks ADD CONSTRAINT asset_stocks_quantity_check CHECK (quantity >= 0)');

        // Append-only ledger: even a bug or a hand-run query cannot rewrite history.
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_ledger_mutation() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'Ledger table % is append-only', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql
        ");

        foreach (['asset_movements', 'asset_movement_items'] as $ledgerTable) {
            DB::unprepared("CREATE TRIGGER {$ledgerTable}_immutable BEFORE UPDATE OR DELETE ON {$ledgerTable} FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation()");
            DB::unprepared("CREATE TRIGGER {$ledgerTable}_no_truncate BEFORE TRUNCATE ON {$ledgerTable} FOR EACH STATEMENT EXECUTE FUNCTION prevent_ledger_mutation()");
        }

        // Functions and sequences outlive `migrate:fresh` (it only drops tables), so creating them must be repeatable.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS asset_movement_number_seq');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS asset_movement_number_seq');

        Schema::dropIfExists('asset_stocks');
        Schema::dropIfExists('asset_handover_items');
        Schema::dropIfExists('asset_movement_items');
        Schema::dropIfExists('assets');

        Schema::table('asset_handovers', function (Blueprint $table) {
            $table->dropForeign(['movement_id']);
        });

        Schema::dropIfExists('asset_movements');
        Schema::dropIfExists('asset_handovers');

        DB::statement('DROP FUNCTION IF EXISTS prevent_ledger_mutation()');
    }
};
