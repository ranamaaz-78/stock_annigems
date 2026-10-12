<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * settings / branch_settings / settings_history
 *
 * The configuration layer for Modules 1–6. Two tables, deliberately:
 *
 * - `settings` has NO branch_id. Every business rule (tax, discount ceiling,
 *   numbering, feature toggles, locale, scanner, security) is global.
 *   SuperAdmin sets them once; every branch follows. Adding a branch_id
 *   column here would let a future developer invent per-branch tax rates
 *   and break the system's design.
 *
 * - `branch_settings` holds only things that are physically local to one
 *   shop: label size, printer format, document footer notes. Contact
 *   details are NOT stored here — they live on `branches` so there is one
 *   source of truth for the name printed on an invoice.
 *
 * `settings_history` is the answer to "who turned the tax off last Tuesday".
 * Every write also goes to audit_logs; this table is what the Settings
 * Change History screen reads.
 *
 * Keys are never created from user input. The seeder and SettingCatalog
 * are the only writers of new keys.
 */
return new class extends Migration
{
    /**
     * Create the three settings tables.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            // Fixed catalog key, e.g. tax_enabled. Never a free-text slug.
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('type', 16);
            $table->string('group', 32);
            $table->string('description', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_role', 32)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_role', 32)->nullable();
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('branch_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->text('value')->nullable();
            $table->string('type', 16);
            $table->string('group', 32);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_role', 32)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_role', 32)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'key']);
        });

        Schema::create('settings_history', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 80);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_role', 32)->nullable();
            $table->timestamps();

            $table->index(['scope', 'created_at']);
            $table->index(['key', 'created_at']);
        });
    }

    /**
     * Drop the three settings tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings_history');
        Schema::dropIfExists('branch_settings');
        Schema::dropIfExists('settings');
    }
};
