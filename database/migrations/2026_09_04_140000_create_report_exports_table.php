<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * report_exports
 *
 * One row per export request.
 *
 * The table earns its place three times over:
 *
 * 1. AuditLogService::log() attaches to an Eloquent model, so an export needs a
 *    record of its own before it can be audited — which spec 9.6 requires.
 * 2. A large export runs in a queued job. The row is what the user's browser
 *    polls and what holds the finished file's path.
 * 3. The stored filters make an export reproducible and reviewable: "who pulled
 *    which figures, for which range, when".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            // Public identifier. The numeric id never appears in a URL.
            $table->uuid('uuid')->unique();

            // Nullable because a SuperAdmin export may cover all branches.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Report slug from ReportCatalog, e.g. "sales-summary".
            $table->string('report_key', 64);
            // Rendered report title at the time of export, so a later rename
            // does not change what an old export claims to be.
            $table->string('report_title', 120);
            // The exact ReportFilter that produced it (dates, preset, options).
            $table->json('filters');

            $table->string('format', 8);
            $table->string('status', 16);
            // Set once a queued export finishes writing to disk.
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            // Populated when a queued export fails, for the failure notice.
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Actor attribution with the role held at the time, matching the
            // convention used across every other record in the system.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_role', 32)->nullable();

            $table->timestamps();

            // The export list is always "mine, newest first", or "this branch's".
            $table->index(['branch_id', 'created_at']);
            $table->index(['requested_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
