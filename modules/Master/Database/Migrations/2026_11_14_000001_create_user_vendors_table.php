<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The vendors a person works for. A person with no rows is not limited by vendor (their areas and
        // roles still limit them); one or more rows limit them to exactly those vendors.
        Schema::create('user_vendors', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'vendor_id']);
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_vendors');
    }
};
