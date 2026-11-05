<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One credential set a vendor's system uses to call us (and, optionally, where we call them back).
        Schema::create('integration_clients', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            // Public identifier sent with every request; the secret never travels, it only signs.
            $table->string('key_id', 40)->unique();
            // Stored encrypted (Laravel's encrypter), because verifying a signature needs the secret itself.
            $table->text('secret');
            $table->jsonb('allowed_ips')->default(DB::raw("'[]'::jsonb"));
            $table->string('webhook_url', 500)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('vendor_id');
        });

        // What the vendor calls each of our statuses. Missing rows fall back to our own status name.
        Schema::create('status_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('internal_status', 20);
            $table->string('vendor_status', 60);
            $table->timestampsTz();

            $table->unique(['vendor_id', 'internal_status']);
        });

        // The outbox: a status change is written here in the same moment it happens, and delivered afterwards,
        // so a vendor that is down never loses an update and never slows a technician down.
        Schema::create('outbound_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('client_id')->constrained('integration_clients')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('history_id');
            $table->string('event', 40);
            $table->jsonb('payload');
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            // The same change is never queued twice for the same receiver.
            $table->unique(['client_id', 'history_id', 'event']);
            $table->index(['status', 'next_attempt_at']);
            $table->index('ticket_id');
        });

        DB::statement("ALTER TABLE outbound_events ADD CONSTRAINT outbound_events_status_check CHECK (status IN ('pending', 'sent', 'dead'))");

        // A record of every call in either direction, for support and for disputes. Never edited, never deleted.
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3);
            $table->foreignId('client_id')->nullable()->constrained('integration_clients')->nullOnDelete();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 300);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->jsonb('request_headers')->default(DB::raw("'{}'::jsonb"));
            $table->text('request_body')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->uuid('correlation_id');
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['direction', 'created_at']);
            $table->index(['vendor_id', 'created_at']);
            $table->index('ticket_id');
        });

        DB::statement("ALTER TABLE integration_logs ADD CONSTRAINT integration_logs_direction_check CHECK (direction IN ('in', 'out'))");

        // Functions outlive `migrate:fresh`, so creating them must be repeatable.
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_integration_log_mutation() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'Integration log is append-only' USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql
        ");
        DB::unprepared('CREATE TRIGGER integration_logs_immutable BEFORE UPDATE OR DELETE ON integration_logs FOR EACH ROW EXECUTE FUNCTION prevent_integration_log_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('outbound_events');
        Schema::dropIfExists('status_mappings');
        Schema::dropIfExists('integration_clients');
        DB::statement('DROP FUNCTION IF EXISTS prevent_integration_log_mutation()');
    }
};
