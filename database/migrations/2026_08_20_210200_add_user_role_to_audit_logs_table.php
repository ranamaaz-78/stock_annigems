<?php

use App\Support\AttributionFormatter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofit a role snapshot onto the existing audit_logs table (Modules 0–1).
 *
 * audit_logs already stores user_id (the actor). PATCH 01 adds user_role —
 * the role that actor held when the logged action occurred — so promoting
 * a user later cannot rewrite the audit trail.
 *
 * user_id is also tightened from ON DELETE SET NULL to RESTRICT: audit rows
 * are permanent and must keep pointing at the (soft-deleted) user.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            // Role the acting user held at the moment this log row was written.
            $table->string('user_role', 30)->nullable()->after('user_id');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        $this->backfillUserRole();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_role');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Best-effort backfill of user_role from the actor's CURRENT role.
     *
     * APPROXIMATION: the historical role was not captured when these log
     * rows were written. New rows are snapshotted by TracksUserAttribution.
     */
    private function backfillUserRole(): void
    {
        $roles = DB::table('users')->pluck('role', 'id');

        DB::table('audit_logs')->orderBy('id')->each(function (object $log) use ($roles): void {
            $role = $log->user_id
                ? ($roles[$log->user_id] ?? AttributionFormatter::SYSTEM_ROLE)
                : AttributionFormatter::SYSTEM_ROLE;

            DB::table('audit_logs')->where('id', $log->id)->update([
                'user_role' => $role,
            ]);
        });
    }
};
