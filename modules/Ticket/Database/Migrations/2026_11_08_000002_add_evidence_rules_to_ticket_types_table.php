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
            // What must be attached before a visit may end with each result, e.g.
            // {"finish": {"photo_before": 1, "photo_after": 1, "signature_merchant": 1}}.
            // Empty means nothing is required.
            $table->jsonb('evidence_rules')->default(DB::raw("'{}'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('evidence_rules');
        });
    }
};
