<?php

use App\Support\AttributionFormatter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofit attribution onto the existing branches table (Modules 0–1).
 *
 * Original Module 1 only stored created_by. PATCH 01 adds a role snapshot at
 * create time, plus updated_by / updated_by_role, so history never rewrites
 * itself when a user is later promoted or replaced.
 *
 * The created_by foreign key is changed from ON DELETE SET NULL to RESTRICT:
 * users are never hard-deleted, and deleting a user must never null out or
 * cascade-remove the branches they created.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Drop the original SET NULL constraint before tightening it.
            $table->dropForeign(['created_by']);
        });

        Schema::table('branches', function (Blueprint $table) {
            // Role the creator held at the moment the branch was created.
            $table->string('created_by_role', 30)->nullable()->after('created_by');
            // Last editor — restrict so a user delete cannot wipe this pointer.
            $table->foreignId('updated_by')->nullable()->after('created_by_role')->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable()->after('updated_by');

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        $this->backfillCreatedByRole();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['created_by_role', 'updated_by_role']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Best-effort backfill of created_by_role from the creator's CURRENT role.
     *
     * APPROXIMATION: the historical role was not captured when these rows were
     * written. Values on existing rows may therefore differ from the role the
     * user actually held at create time. Every row created after this patch
     * is snapshotted accurately by TracksUserAttribution.
     */
    private function backfillCreatedByRole(): void
    {
        $roles = DB::table('users')->pluck('role', 'id');

        DB::table('branches')->orderBy('id')->each(function (object $branch) use ($roles): void {
            $role = $branch->created_by
                ? ($roles[$branch->created_by] ?? AttributionFormatter::SYSTEM_ROLE)
                : AttributionFormatter::SYSTEM_ROLE;

            DB::table('branches')->where('id', $branch->id)->update([
                'created_by_role' => $role,
            ]);
        });
    }
};
