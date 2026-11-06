<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Province, City, Area, Service Point  ->  Province, Service Point, Managed Area.
 *
 * Existing rows are carried over: each city becomes a service point (taking the vendor of the merchants
 * beneath it, or else the first vendor), the old area level is folded away (its children move up to the
 * city), and each old service point becomes a managed area, which is where merchant sites attach.
 * A service point without a vendor is shared by every vendor; the screens ask for one by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::table('locations', function (Blueprint $table) {
                $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            });

            DB::statement('ALTER TABLE locations DROP CONSTRAINT locations_type_check');
            DB::statement('ALTER TABLE locations DROP CONSTRAINT locations_depth_check');
            DB::statement('DROP INDEX locations_type_code_active_unique');

            // Fold the old Area level away: whatever hung under an area moves up to the area's city,
            // and anyone assigned to an area is assigned to that city instead.
            DB::statement("UPDATE locations child SET parent_id = area.parent_id FROM locations area WHERE child.parent_id = area.id AND area.type = 'area'");
            DB::statement("INSERT INTO user_location_scopes (user_id, location_id) SELECT s.user_id, a.parent_id FROM user_location_scopes s JOIN locations a ON a.id = s.location_id AND a.type = 'area' ON CONFLICT DO NOTHING");
            DB::statement("DELETE FROM user_location_scopes WHERE location_id IN (SELECT id FROM locations WHERE type = 'area')");
            // A warehouse that stood in an old area now stands in that area's city (the new service point).
            DB::statement("UPDATE warehouses w SET location_id = a.parent_id FROM locations a WHERE w.location_id = a.id AND a.type = 'area'");
            DB::statement("DELETE FROM locations WHERE type = 'area'");

            DB::statement("UPDATE locations SET type = 'area' WHERE type = 'service_point'");
            DB::statement("UPDATE locations SET type = 'service_point' WHERE type = 'city'");

            // A service point serves the vendor of the merchants in its areas; with none, the first vendor.
            DB::statement("
                UPDATE locations sp SET vendor_id = COALESCE(
                    (SELECT m.vendor_id FROM merchant_sites s
                        JOIN merchants m ON m.id = s.merchant_id
                        JOIN locations a ON a.id = s.location_id
                        WHERE a.parent_id = sp.id ORDER BY m.vendor_id LIMIT 1),
                    (SELECT id FROM vendors WHERE deleted_at IS NULL ORDER BY id LIMIT 1)
                )
                WHERE sp.type = 'service_point'
            ");
            DB::statement("UPDATE locations a SET vendor_id = sp.vendor_id FROM locations sp WHERE a.parent_id = sp.id AND a.type = 'area'");

            // Paths and depths are rebuilt top-down because the levels changed.
            DB::statement("UPDATE locations SET path = '/' || id || '/', depth = 1 WHERE parent_id IS NULL");
            DB::statement("UPDATE locations c SET path = p.path || c.id || '/', depth = 2 FROM locations p WHERE c.parent_id = p.id AND p.depth = 1");
            DB::statement("UPDATE locations c SET path = p.path || c.id || '/', depth = 3 FROM locations p WHERE c.parent_id = p.id AND p.depth = 2");

            DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_type_check CHECK (type IN ('province', 'service_point', 'area'))");
            DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_depth_check CHECK (depth BETWEEN 1 AND 3)');
            // A province belongs to nobody; service points and areas may name a vendor (empty = shared by all).
            DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_province_has_no_vendor_check CHECK (type <> 'province' OR vendor_id IS NULL)");
            DB::statement("CREATE UNIQUE INDEX locations_province_code_unique ON locations (lower(code)) WHERE type = 'province' AND deleted_at IS NULL");
            DB::statement("CREATE UNIQUE INDEX locations_code_per_vendor_unique ON locations (type, coalesce(vendor_id, 0), lower(code)) WHERE type <> 'province' AND deleted_at IS NULL");
            DB::statement('CREATE INDEX locations_vendor_id_index ON locations (vendor_id)');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restructuring the location levels cannot be undone; restore a backup instead.');
    }
};
