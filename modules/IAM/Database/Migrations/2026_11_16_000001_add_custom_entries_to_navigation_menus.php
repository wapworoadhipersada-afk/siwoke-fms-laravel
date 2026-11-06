<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\IAM\Application\Services\SyncNavigationCatalog;

/**
 * Administrators can add their own groups and menus. A custom entry is flagged, and may point at an address
 * inside this application instead of a named route. The settings hub replaces the two separate settings entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('navigation_menus', function (Blueprint $table): void {
            $table->string('url', 255)->nullable()->after('route_name');
            $table->boolean('is_custom')->default(false)->after('is_active');
        });

        // One entry never has both a route and an address.
        DB::statement('ALTER TABLE navigation_menus ADD CONSTRAINT navigation_menus_target_check CHECK (route_name IS NULL OR url IS NULL)');

        $ids = DB::table('navigation_menus')->whereIn('key', ['access.roles', 'access.menus'])->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('navigation_menu_role')->whereIn('navigation_menu_id', $ids)->delete();
            DB::table('navigation_menus')->whereIn('id', $ids)->delete();
        }

        // Bring in the pages added since the table was filled (locations, engineers, team, documents, settings).
        if (DB::table('navigation_menus')->exists()) {
            app(SyncNavigationCatalog::class)->handle();
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE navigation_menus DROP CONSTRAINT IF EXISTS navigation_menus_target_check');

        Schema::table('navigation_menus', function (Blueprint $table): void {
            $table->dropColumn(['url', 'is_custom']);
        });
    }
};
