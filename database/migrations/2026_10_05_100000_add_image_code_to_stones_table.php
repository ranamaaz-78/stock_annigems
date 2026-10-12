<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a stone lot to a picture in the branch image folder.
 *
 * `image_code` holds the picture's name (without extension), lowercased, so
 * it equals stone_images.name_key. It is deliberately not a foreign key: a
 * lot can be registered before its picture is uploaded, and the picture
 * attaches itself the moment a file with that name exists.
 */
return new class extends Migration
{
    /**
     * Add the nullable code and index it with the branch.
     */
    public function up(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->string('image_code', 180)->nullable()->after('tray_no')
                ->comment('Image folder name (lowercase) this lot shows');
            $table->index(['branch_id', 'image_code']);
        });
    }

    /**
     * Remove the column and its index.
     */
    public function down(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'image_code']);
            $table->dropColumn('image_code');
        });
    }
};
