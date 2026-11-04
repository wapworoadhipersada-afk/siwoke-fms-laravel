<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('ticket_no', 30)->unique();
            // The vendor's own reference when the ticket comes from them; unique per vendor.
            $table->string('vendor_ticket_no', 60)->nullable();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('merchant_site_id')->constrained('merchant_sites')->restrictOnDelete();
            $table->foreignId('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->string('source', 10)->default('manual');
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('received');
            // Set only while a technician is on it; the visits table keeps who did what.
            $table->foreignId('technician_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('sla_due_at');
            $table->timestampTz('closed_at')->nullable();
            $table->string('final_result', 20)->nullable();
            // The vendor's original request, kept verbatim.
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('technician_id');
            $table->index('merchant_site_id');
            $table->index('sla_due_at');
        });

        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('received', 'assigned', 'accepted', 'on_the_way', 'arrived', 'handling', 'pending', 'finished', 'done_negative', 'cancelled'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_source_check CHECK (source IN ('manual', 'api'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_final_result_check CHECK (final_result IS NULL OR final_result IN ('finish', 'done_negative'))");
        // A closed ticket always says how it ended; an open one never does.
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_closed_has_result_check CHECK ((final_result IS NOT NULL) = (status IN ('finished', 'done_negative')))");
        // While someone is working on it, it must be assigned to them.
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_active_has_technician_check CHECK (status NOT IN ('assigned', 'accepted', 'on_the_way', 'arrived', 'handling') OR technician_id IS NOT NULL)");
        DB::statement('CREATE UNIQUE INDEX tickets_vendor_ref_active_unique ON tickets (vendor_id, lower(vendor_ticket_no)) WHERE vendor_ticket_no IS NOT NULL AND deleted_at IS NULL');

        // One row per attempt at the job. A pending ticket gets a new visit when it is rescheduled.
        Schema::create('ticket_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('visit_no');
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('departed_at')->nullable();
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('handling_started_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->foreignId('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('arrival_latitude', 10, 7)->nullable();
            $table->decimal('arrival_longitude', 10, 7)->nullable();
            $table->unsignedInteger('arrival_distance_m')->nullable();
            $table->decimal('close_latitude', 10, 7)->nullable();
            $table->decimal('close_longitude', 10, 7)->nullable();
            $table->jsonb('result_data')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['ticket_id', 'visit_no']);
            $table->index('technician_id');
        });

        DB::statement("ALTER TABLE ticket_visits ADD CONSTRAINT ticket_visits_outcome_check CHECK (outcome IS NULL OR outcome IN ('finish', 'pending', 'done_negative', 'cancelled'))");
        DB::statement('CREATE UNIQUE INDEX ticket_visits_one_open_per_ticket ON ticket_visits (ticket_id) WHERE outcome IS NULL');

        Schema::create('ticket_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained('ticket_visits')->restrictOnDelete();
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('assigned_at');
            $table->timestampTz('unassigned_at')->nullable();
            $table->text('unassign_reason')->nullable();

            $table->index('ticket_id');
        });

        // Every status change ever made. Immutable, like the asset ledger.
        Schema::create('ticket_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('ticket_visits')->restrictOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 10)->default('user');
            $table->text('reason')->nullable();
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->string('ip', 45)->nullable();
            // Not a foreign key: a history row must never be touched by a cascade.
            $table->unsignedBigInteger('device_id')->nullable();
            // Lets the mobile app resend an action after a bad connection without it counting twice.
            $table->uuid('client_uuid')->nullable()->unique();
            // When it really happened (the phone's clock, bounded), as opposed to when we heard about it.
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['ticket_id', 'id']);
        });

        DB::statement("ALTER TABLE ticket_status_histories ADD CONSTRAINT ticket_status_histories_actor_type_check CHECK (actor_type IN ('user', 'system', 'vendor'))");

        // Functions and sequences outlive `migrate:fresh`, so creating them must be repeatable.
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_history_mutation() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'History table % is append-only', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql
        ");
        DB::unprepared('CREATE TRIGGER ticket_status_histories_immutable BEFORE UPDATE OR DELETE ON ticket_status_histories FOR EACH ROW EXECUTE FUNCTION prevent_history_mutation()');
        DB::unprepared('CREATE TRIGGER ticket_status_histories_no_truncate BEFORE TRUNCATE ON ticket_status_histories FOR EACH STATEMENT EXECUTE FUNCTION prevent_history_mutation()');

        DB::statement('CREATE SEQUENCE IF NOT EXISTS ticket_number_seq');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS ticket_number_seq');

        Schema::dropIfExists('ticket_status_histories');
        Schema::dropIfExists('ticket_assignments');
        Schema::dropIfExists('ticket_visits');
        Schema::dropIfExists('tickets');

        DB::statement('DROP FUNCTION IF EXISTS prevent_history_mutation()');
    }
};
