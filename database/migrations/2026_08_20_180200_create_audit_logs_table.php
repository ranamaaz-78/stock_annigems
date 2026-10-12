<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the audit_logs table.
 *
 * Records who changed important records (branches, users, later invoices/items),
 * what changed, and when — required for every create/update/delete in Module 1.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Actor who performed the action (nullable if user later hard-removed; we soft-delete users).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Branch context when the action relates to a branch-owned entity.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // Machine-readable action key, e.g. branch.created, user.deactivated.
            $table->string('action', 100);
            // Morph-style model class name of the affected record.
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
