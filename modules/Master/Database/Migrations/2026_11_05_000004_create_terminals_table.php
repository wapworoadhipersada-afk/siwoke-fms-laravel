<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('merchant_site_id');
            $table->string('tid', 20);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('merchant_site_id');
        });

        // A terminal's merchant must belong to the terminal's vendor, and its site to that merchant.
        DB::statement('ALTER TABLE terminals ADD CONSTRAINT terminals_merchant_vendor_foreign FOREIGN KEY (merchant_id, vendor_id) REFERENCES merchants (id, vendor_id)');
        DB::statement('ALTER TABLE terminals ADD CONSTRAINT terminals_site_merchant_foreign FOREIGN KEY (merchant_site_id, merchant_id) REFERENCES merchant_sites (id, merchant_id)');
        DB::statement("ALTER TABLE terminals ADD CONSTRAINT terminals_status_check CHECK (status IN ('active', 'inactive', 'removed'))");
        // One TID per vendor among live rows; the same TID may exist at different banks.
        DB::statement('CREATE UNIQUE INDEX terminals_vendor_tid_active_unique ON terminals (vendor_id, lower(tid)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
