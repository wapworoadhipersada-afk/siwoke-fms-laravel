<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Locations now have their own sidebar group (Provinsi, Service Point, Area Kelolaan). The old single "Lokasi"
 * entry under Master Data is removed so it is not shown twice; the catalog sync adds the new group.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('navigation_menus')->where('key', 'master.locations')->value('id');

        if ($id === null) {
            return;
        }

        DB::table('navigation_menu_role')->where('navigation_menu_id', $id)->delete();
        DB::table('navigation_menus')->where('id', $id)->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the catalog sync would re-add the entry if it came back to the catalog.
    }
};
