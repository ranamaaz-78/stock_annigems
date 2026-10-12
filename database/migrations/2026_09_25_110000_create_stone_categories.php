<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Group stone lots into categories named after the material.
 *
 * The supplier sheet repeats the same material many times with different
 * carats, trays, and rates — CITIRINO alone spans dozens of rows. Operators
 * think in materials, not lots, so the listing opens on one folder per
 * material and the lots live inside it.
 *
 * The category is derived from the lot's name rather than typed separately:
 * a name that has been seen before attaches to its existing category, and a
 * new one creates it. Categories are branch-owned, like the lots themselves.
 */
return new class extends Migration
{
    /**
     * Create the table, link stones to it, and file every existing lot.
     */
    public function up(): void
    {
        Schema::create('stone_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->comment('The only identifier used in URLs');
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120)->comment('Material as first spelled, e.g. CITIRINO');
            $table->string('match_key', 120)
                ->comment('Upper-cased, whitespace-collapsed name used to match lots to this folder');
            $table->boolean('is_active')->default(true);

            // Attribution, matching every other record in the system.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->string('updated_by_role', 30)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One folder per material per branch; two branches may both hold
            // a CITIRINO folder without colliding.
            $table->unique(['branch_id', 'match_key']);
            $table->index(['branch_id', 'name']);
        });

        Schema::table('stones', function (Blueprint $table): void {
            // Nullable so a lot is never blocked by a missing folder; the
            // service always sets it on create and on rename.
            $table->foreignId('stone_category_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('stone_categories')
                ->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * File every stone that already exists into a folder for its name.
     *
     * Runs in chunks because a branch may already hold thousands of imported
     * lots by the time this migration is applied.
     */
    private function backfill(): void
    {
        DB::table('stones')
            ->select('id', 'branch_id', 'name', 'created_by', 'created_by_role')
            ->whereNull('stone_category_id')
            ->orderBy('id')
            ->chunkById(500, function ($stones): void {
                foreach ($stones as $stone) {
                    $name = trim((string) $stone->name);

                    if ($name === '') {
                        continue;
                    }

                    $matchKey = mb_strtoupper(preg_replace('/\s+/u', ' ', $name));

                    $categoryId = DB::table('stone_categories')
                        ->where('branch_id', $stone->branch_id)
                        ->where('match_key', $matchKey)
                        ->value('id');

                    if ($categoryId === null) {
                        $categoryId = DB::table('stone_categories')->insertGetId([
                            'uuid' => (string) Str::uuid(),
                            'branch_id' => $stone->branch_id,
                            'name' => $name,
                            'match_key' => $matchKey,
                            'is_active' => true,
                            // The lot's own author is the closest thing to an
                            // author the backfilled folder has.
                            'created_by' => $stone->created_by,
                            'created_by_role' => $stone->created_by_role,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // Only the link is written; updated_at is left alone so
                    // the backfill does not look like an edit by a user.
                    DB::table('stones')
                        ->where('id', $stone->id)
                        ->update(['stone_category_id' => $categoryId]);
                }
            });
    }

    /**
     * Unlink the stones, then drop the table.
     */
    public function down(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stone_category_id');
        });

        Schema::dropIfExists('stone_categories');
    }
};
