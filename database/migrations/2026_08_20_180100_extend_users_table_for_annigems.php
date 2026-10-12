<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the default users table for Annigems multi-branch auth.
 *
 * SuperAdmin has branch_id = NULL. All other roles must belong to a branch.
 * Soft deletes keep historical audit trails intact.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL = SuperAdmin (system level); required for all other roles.
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->string('phone', 30)->nullable()->after('email');
            // Stored as UserRole enum value (superadmin, admin, ...).
            $table->string('role', 30)->after('password');
            // Stored as UserStatus enum value (active | inactive).
            $table->string('status', 20)->default('active')->after('role');
            $table->timestamp('last_login_at')->nullable()->after('status');
            // When true, middleware forces the change-password screen after login.
            $table->boolean('must_change_password')->default(false)->after('last_login_at');
            $table->foreignId('created_by')->nullable()->after('must_change_password')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'phone',
                'role',
                'status',
                'last_login_at',
                'must_change_password',
            ]);
            $table->dropSoftDeletes();
        });
    }
};
