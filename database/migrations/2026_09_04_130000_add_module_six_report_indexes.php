<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting indexes for Module 6.
 *
 * Reports are read-heavy and must never slow daily operations down. Only the
 * indexes that do not already exist are added here — Modules 3 to 5 already
 * created several of them, and MySQL rejects a duplicate index name.
 *
 * Deliberately NOT added:
 * - stones (branch_id, status): the stones table has no status column, only
 *   is_active, and (branch_id, is_active) already exists.
 * - invoice_items (invoice_id): already covered as the leading column of the
 *   existing (invoice_id, sort_order) index.
 */
return new class extends Migration
{
    /**
     * Add the missing reporting indexes.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            // Every revenue report filters branch + completed status, then
            // ranges over invoice_date. The two-column indexes already present
            // cannot serve all three legs at once.
            $table->index(['branch_id', 'status', 'invoice_date'], 'invoices_branch_status_date_index');
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            // Loose stone sales report filters on line_type.
            $table->index('line_type', 'invoice_items_line_type_index');
            // Drill-through to the current record ("view current record" only).
            $table->index('jewellery_item_id', 'invoice_items_jewellery_item_id_index');
            $table->index('stone_id', 'invoice_items_stone_id_index');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->index(['branch_id', 'status', 'quotation_date'], 'quotations_branch_status_date_index');
            // Time-to-convert joins quotations to their converted invoice.
            $table->index('converted_invoice_id', 'quotations_converted_invoice_id_index');
        });

        Schema::table('jewellery_items', function (Blueprint $table): void {
            // Gold weight in stock is grouped by purity within a branch.
            $table->index(['branch_id', 'purity_id'], 'jewellery_items_branch_purity_index');
            // Aging buckets range over the creation date.
            $table->index('created_at', 'jewellery_items_created_at_index');
        });

        Schema::table('stone_stock_movements', function (Blueprint $table): void {
            // Stone consumption groups by movement type over a date range.
            $table->index(['branch_id', 'type', 'created_at'], 'stone_movements_branch_type_date_index');
        });

        Schema::table('jewellery_item_movements', function (Blueprint $table): void {
            // Branch-wide movement log, newest first.
            $table->index(['branch_id', 'created_at'], 'jewellery_movements_branch_date_index');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            // User activity report: branch, then actor, then date range.
            $table->index(['branch_id', 'user_id', 'created_at'], 'audit_logs_branch_user_date_index');
        });
    }

    /**
     * Drop the indexes again by their explicit names.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_branch_status_date_index');
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropIndex('invoice_items_line_type_index');
            $table->dropIndex('invoice_items_jewellery_item_id_index');
            $table->dropIndex('invoice_items_stone_id_index');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropIndex('quotations_branch_status_date_index');
            $table->dropIndex('quotations_converted_invoice_id_index');
        });

        Schema::table('jewellery_items', function (Blueprint $table): void {
            $table->dropIndex('jewellery_items_branch_purity_index');
            $table->dropIndex('jewellery_items_created_at_index');
        });

        Schema::table('stone_stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stone_movements_branch_type_date_index');
        });

        Schema::table('jewellery_item_movements', function (Blueprint $table): void {
            $table->dropIndex('jewellery_movements_branch_date_index');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_branch_user_date_index');
        });
    }
};
