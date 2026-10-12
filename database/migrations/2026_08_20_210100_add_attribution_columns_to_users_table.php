<?php

use App\Support\AttributionFormatter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofit attribution onto the existing users table (Modules 0–1).
 *
 * Original Module 1 only stored created_by. PATCH 01 adds a role snapshot at
 * create time, plus updated_by / updated_by_role. created_by is attribution
 * only — it never grants or restricts access (data belongs to the branch).
 *
 * The created_by foreign key is changed from ON DELETE SET NULL to RESTRICT
 * so deactivating or (illegally) hard-deleting a user cannot cascade into
 * the staff accounts they created.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('created_by_role', 30)->nullable()->after('created_by');
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
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['created_by_role', 'updated_by_role']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Best-effort backfill of created_by_role from the creator's CURRENT role.
     *
     * APPROXIMATION: historical role was not stored at write time. Rows with
     * no creator (the bootstrap SuperAdmin) are marked with the system marker.
     * Rows created after this patch are accurate snapshots.
     */
    private function backfillCreatedByRole(): void
    {
        $roles = DB::table('users')->pluck('role', 'id');

        DB::table('users')->orderBy('id')->each(function (object $user) use ($roles): void {
            $role = $user->created_by
                ? ($roles[$user->created_by] ?? AttributionFormatter::SYSTEM_ROLE)
                : AttributionFormatter::SYSTEM_ROLE;

            DB::table('users')->where('id', $user->id)->update([
                'created_by_role' => $role,
            ]);
        });
    }
};
