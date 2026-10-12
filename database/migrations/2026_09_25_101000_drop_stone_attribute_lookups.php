<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the stone attribute vocabularies.
 *
 * Stone lots now describe themselves with the fields the supplier sheet
 * carries — shape, dimension, carat, weight and a pricing rate — so the
 * type/shape/colour/clarity/cut lookup tables no longer have a column to
 * attach to. The `stones.attributes` permission goes with them.
 *
 * Existing jewellery pieces keep their stone descriptions: those are stored
 * as text snapshots on jewellery_item_stones, never as lookup references.
 */
return new class extends Migration
{
    /**
     * Drop the lookup tables and retire the permission.
     */
    public function up(): void
    {
        // Colours and clarities point at stone_types, so they go first.
        Schema::dropIfExists('stone_colors');
        Schema::dropIfExists('stone_clarities');
        Schema::dropIfExists('stone_cuts');
        Schema::dropIfExists('stone_shapes');
        Schema::dropIfExists('stone_types');

        $permissionId = DB::table('permissions')->where('name', 'stones.attributes')->value('id');

        if ($permissionId !== null) {
            DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }

    /**
     * Irreversible: the vocabularies and their rows are gone.
     *
     * Rolling back would recreate empty tables that no stone references,
     * which is more misleading than leaving them dropped.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
