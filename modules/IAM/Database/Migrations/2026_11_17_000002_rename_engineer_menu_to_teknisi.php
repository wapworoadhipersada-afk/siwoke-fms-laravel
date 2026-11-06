<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The menu for field staff is simply called "Teknisi". Only a menu that still carries the old default name is
 * renamed, so a name an administrator chose themselves is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('navigation_menus')->where('key', 'access.engineers')->where('label', 'Engineer / Teknisi')->update(['label' => 'Teknisi']);
    }

    public function down(): void
    {
        DB::table('navigation_menus')->where('key', 'access.engineers')->where('label', 'Teknisi')->update(['label' => 'Engineer / Teknisi']);
    }
};
