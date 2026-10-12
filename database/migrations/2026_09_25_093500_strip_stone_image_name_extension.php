<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stone image names are labels without an extension (stone1, not stone1.jpg).
 *
 * Search looks up that label. name_key stays the lowercased copy and is
 * already unique per branch; this adds an index on name itself so a search
 * on the display name can use it.
 */
return new class extends Migration
{
    /**
     * Strip image extensions already stored, then index the name column.
     */
    public function up(): void
    {
        $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        foreach (DB::table('stone_images')->orderBy('id')->get() as $row) {
            $name = (string) $row->name;
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (in_array($extension, $extensions, true)) {
                $name = trim((string) pathinfo($name, PATHINFO_FILENAME));
            }

            if ($name === '') {
                $name = 'image-'.$row->id;
            }

            DB::table('stone_images')->where('id', $row->id)->update([
                'name' => $name,
                'name_key' => mb_strtolower($name),
            ]);
        }

        Schema::table('stone_images', function (Blueprint $table) {
            $table->index('name', 'stone_images_name_index');
        });
    }

    /**
     * Drop the name index. Extensions are not restored.
     */
    public function down(): void
    {
        Schema::table('stone_images', function (Blueprint $table) {
            $table->dropIndex('stone_images_name_index');
        });
    }
};
