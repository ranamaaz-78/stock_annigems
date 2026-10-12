<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Retrofit the hybrid UUID strategy onto the existing users table (PATCH 02).
 *
 * Integer id stays the primary key (auth, FKs, attribution). uuid is the
 * public identifier used in SuperAdmin user URLs. Existing rows are
 * backfilled before the not-null constraint is applied.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        $this->backfillUuids();

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }

    /**
     * Assign an ordered UUID to every existing user.
     *
     * Uses the query builder (not the User model) so HasUuid / attribution
     * events do not fire during the retrofit.
     */
    private function backfillUuids(): void
    {
        DB::table('users')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'uuid' => (string) Str::orderedUuid(),
            ]);
        });
    }
};
