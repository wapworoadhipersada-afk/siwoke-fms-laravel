<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('berita_acaras', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // Same for every revision of one visit's report, e.g. BA/BRI/2026/11/000123.
            $table->string('ba_number', 40);
            $table->unsignedSmallInteger('revision')->default(1);
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained('ticket_visits')->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->string('outcome', 20);
            // Everything the report says, frozen when it was made, so later edits to master data never change it.
            $table->jsonb('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->boolean('is_current')->default(true);
            $table->text('regenerate_reason')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('generated_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['ba_number', 'revision']);
            $table->unique(['visit_id', 'revision']);
            $table->index('ticket_id');
        });

        DB::statement("ALTER TABLE berita_acaras ADD CONSTRAINT berita_acaras_outcome_check CHECK (outcome IN ('finish', 'pending', 'done_negative'))");
        // One live report per visit; older revisions stay on record but are no longer current.
        DB::statement('CREATE UNIQUE INDEX berita_acaras_one_current_per_visit ON berita_acaras (visit_id) WHERE is_current');

        // Functions and sequences outlive `migrate:fresh`, so creating them must be repeatable.
        DB::unprepared("
            CREATE OR REPLACE FUNCTION protect_berita_acara() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'Berita Acara cannot be deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                -- Only the 'current' flag may change (when a newer revision replaces this one).
                IF NEW.ba_number IS DISTINCT FROM OLD.ba_number OR NEW.revision IS DISTINCT FROM OLD.revision
                   OR NEW.ticket_id IS DISTINCT FROM OLD.ticket_id OR NEW.visit_id IS DISTINCT FROM OLD.visit_id
                   OR NEW.attachment_id IS DISTINCT FROM OLD.attachment_id OR NEW.outcome IS DISTINCT FROM OLD.outcome
                   OR NEW.snapshot IS DISTINCT FROM OLD.snapshot OR NEW.snapshot_sha256 IS DISTINCT FROM OLD.snapshot_sha256
                   OR NEW.generated_at IS DISTINCT FROM OLD.generated_at THEN
                    RAISE EXCEPTION 'Berita Acara content is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        ");
        DB::unprepared('CREATE TRIGGER berita_acaras_protect BEFORE UPDATE OR DELETE ON berita_acaras FOR EACH ROW EXECUTE FUNCTION protect_berita_acara()');
        DB::unprepared('CREATE TRIGGER berita_acaras_no_truncate BEFORE TRUNCATE ON berita_acaras FOR EACH STATEMENT EXECUTE FUNCTION protect_berita_acara()');

        DB::statement('CREATE SEQUENCE IF NOT EXISTS berita_acara_number_seq');
    }

    public function down(): void
    {
        Schema::dropIfExists('berita_acaras');
        DB::statement('DROP FUNCTION IF EXISTS protect_berita_acara()');
        DB::statement('DROP SEQUENCE IF EXISTS berita_acara_number_seq');
    }
};
