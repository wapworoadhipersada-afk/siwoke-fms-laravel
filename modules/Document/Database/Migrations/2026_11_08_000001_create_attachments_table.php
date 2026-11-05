<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // What the file is evidence for (a ticket visit today; anything that opts in later).
            $table->string('attachable_type', 40);
            $table->unsignedBigInteger('attachable_id');
            $table->string('kind', 30);
            $table->string('disk', 30);
            // Random, never derived from anything the uploader sent.
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            // Display only. Sanitised, and never used to build a path.
            $table->string('original_name', 150)->nullable();
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // Hash of the file as stored, to prove it was not altered; and of the file as uploaded, to refuse a repeat.
            $table->char('sha256', 64);
            $table->char('source_sha256', 64);
            $table->timestampTz('captured_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            // Not a foreign key: an upload record should never be touched by a device being removed.
            $table->unsignedBigInteger('device_id')->nullable();
            // Lets the phone resend an upload after a bad connection without creating a second file.
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['attachable_type', 'attachable_id', 'kind']);
        });

        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_kind_check CHECK (kind IN ('photo_before', 'photo_after', 'photo_evidence', 'signature_merchant', 'signature_technician', 'ba_pdf'))");
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
        // The same upload twice on the same thing is a mistake, not evidence.
        DB::statement('CREATE UNIQUE INDEX attachments_source_unique ON attachments (attachable_type, attachable_id, source_sha256) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
