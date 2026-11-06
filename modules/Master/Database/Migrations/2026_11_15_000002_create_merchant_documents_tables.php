<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merchants and their TIDs arrive on a document from the principal ("pemberi kerja"): a document number, a
 * received date and a list of lines. A document is first a draft with every line checked, then imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('document_number', 120);
            $table->date('received_on');
            $table->text('notes')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('source_name')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('imported_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE merchant_documents ADD CONSTRAINT merchant_documents_status_check CHECK (status IN ('draft', 'imported'))");
        DB::statement("ALTER TABLE merchant_documents ADD CONSTRAINT merchant_documents_source_check CHECK (source IN ('manual', 'upload'))");
        // One document number per principal.
        DB::statement('CREATE UNIQUE INDEX merchant_documents_vendor_number_unique ON merchant_documents (vendor_id, lower(document_number))');

        Schema::create('merchant_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('merchant_name');
            $table->string('tid', 40);
            $table->string('mid', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('province')->nullable();
            $table->string('service_point')->nullable();
            $table->string('area')->nullable();
            $table->text('tid_note')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->foreignId('terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['merchant_document_id', 'line_no']);
        });
        DB::statement("ALTER TABLE merchant_document_lines ADD CONSTRAINT merchant_document_lines_status_check CHECK (status IN ('pending', 'error', 'imported'))");

        Schema::table('terminals', function (Blueprint $table) {
            $table->text('note')->nullable();
            $table->foreignId('merchant_document_id')->nullable()->constrained('merchant_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merchant_document_id');
            $table->dropColumn('note');
        });
        Schema::dropIfExists('merchant_document_lines');
        Schema::dropIfExists('merchant_documents');
    }
};
