<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A Berita Acara is produced by the system, so it has no uploading person.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE attachments ALTER COLUMN uploaded_by DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM attachments WHERE uploaded_by IS NULL');
        DB::statement('ALTER TABLE attachments ALTER COLUMN uploaded_by SET NOT NULL');
    }
};
