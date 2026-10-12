<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild `stones` around the supplier inventory sheet.
 *
 * A row is now a lot: material, shape, size, and the three pricing modes
 * (per carat, per piece, per gram). The old jewellery-style identity
 * (type/shape/color/clarity/cut lookups, origin, short code, fingerprint)
 * does not exist in that sheet and is removed, along with every stone,
 * intake, and movement recorded under it — the sheet is the new source.
 *
 * `barcode` becomes a generated 10-11 digit number, separate from the
 * STN-000001 business code. Totals are stored so a printed document never
 * recomputes historical money.
 */
return new class extends Migration
{
    /**
     * Wipe stone stock, drop the old identity, and add the lot columns.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        $this->clearStoneReferences();

        if ($driver === 'sqlite') {
            // SQLite refuses to drop a column named in a foreign key and
            // cannot drop the key separately, so the table is rebuilt whole.
            $this->rebuildForSqlite();

            return;
        }

        // Foreign keys and their indexes must go before the columns.
        Schema::table('stones', function (Blueprint $table): void {
            $table->dropForeign(['stone_type_id']);
            $table->dropForeign(['stone_shape_id']);
            $table->dropForeign(['stone_color_id']);
            $table->dropForeign(['stone_clarity_id']);
            $table->dropForeign(['stone_cut_id']);
            $table->dropUnique('stones_branch_fingerprint_unique');
            $table->dropUnique('stones_branch_short_code_unique');
            $table->dropIndex(['stone_type_id']);
        });

        Schema::table('stones', function (Blueprint $table): void {
            $table->dropColumn([
                'stone_type_id',
                'stone_shape_id',
                'stone_color_id',
                'stone_clarity_id',
                'stone_cut_id',
                'origin',
                'attribute_fingerprint',
                'short_code',
                'notes',
            ]);
        });

        Schema::table('stones', function (Blueprint $table): void {
            // Sheet columns. Carat, weight, and the prices are nullable
            // because each pricing mode only fills the fields it uses.
            $table->string('shape', 60)->nullable()->after('name')->comment('SHAPE/CUT text from the sheet');
            $table->string('dimension', 40)->nullable()->after('shape')->comment('Millimetre size, e.g. 8x6');
            $table->unsignedInteger('pairs')->default(0)->after('quantity')->comment('Matching pairs; never part of a price');
            $table->decimal('weight_grams', 12, 3)->nullable()->after('pairs')->comment('Gram lots only');
            $table->string('pricing_mode', 20)->default('per_piece')->after('weight_grams')->comment('per_carat | per_piece | per_gram');
            $table->decimal('price_per_carat', 14, 4)->nullable()->after('pricing_mode');
            $table->decimal('price_per_piece', 14, 4)->nullable()->after('price_per_carat');
            $table->decimal('price_per_gram', 14, 4)->nullable()->after('price_per_piece');
            $table->decimal('piece_price', 14, 4)->nullable()->after('price_per_gram')
                ->comment('Per carat only: carat x price_per_carat for one piece');
            $table->decimal('total_cost', 16, 4)->default(0)->after('piece_price')
                ->comment('Server-calculated; never trusted from the client');
            $table->string('cost_formula', 120)->nullable()->after('total_cost')
                ->comment('Readable calculation, e.g. 2.100 x 2.0000 x 2');
            $table->string('status', 20)->default('in_stock')->after('cost_formula')->comment('in_stock | pending');
            $table->string('tray_no', 30)->nullable()->after('status');
            $table->string('remarks', 255)->nullable()->after('tray_no');

            $table->index(['branch_id', 'pricing_mode']);
            $table->index(['branch_id', 'tray_no']);
            $table->index('name');
        });

        // Carat is optional now: per-piece and per-gram lots have no carat.
        DB::statement('ALTER TABLE stones MODIFY carat DECIMAL(10,3) NULL');
    }

    /**
     * Restore the previous identity columns. Deleted stock is not restored.
     */
    public function down(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'pricing_mode']);
            $table->dropIndex(['branch_id', 'tray_no']);
            $table->dropIndex(['name']);
            $table->dropColumn([
                'shape',
                'dimension',
                'pairs',
                'weight_grams',
                'pricing_mode',
                'price_per_carat',
                'price_per_piece',
                'price_per_gram',
                'piece_price',
                'total_cost',
                'cost_formula',
                'status',
                'tray_no',
                'remarks',
            ]);
        });

        Schema::table('stones', function (Blueprint $table): void {
            $table->foreignId('stone_type_id')->nullable()->constrained('stone_types')->restrictOnDelete();
            $table->foreignId('stone_shape_id')->nullable()->constrained('stone_shapes')->restrictOnDelete();
            $table->foreignId('stone_color_id')->nullable()->constrained('stone_colors')->restrictOnDelete();
            $table->foreignId('stone_clarity_id')->nullable()->constrained('stone_clarities')->restrictOnDelete();
            $table->foreignId('stone_cut_id')->nullable()->constrained('stone_cuts')->restrictOnDelete();
            $table->string('origin', 30)->nullable();
            $table->string('attribute_fingerprint', 64)->nullable();
            $table->char('short_code', 3)->nullable();
            $table->text('notes')->nullable();
        });
    }

    /**
     * Remove every stone row and anything that points at one.
     *
     * Sales documents keep their own name/carat snapshots, so clearing the
     * stone_id link leaves invoice and quotation history readable while
     * freeing the restrict-on-delete foreign keys.
     */
    private function clearStoneReferences(): void
    {
        // Sales lines keep their snapshots; only the live link is cleared.
        foreach (['quotation_items', 'invoice_items', 'performa_items'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'stone_id')) {
                DB::table($table)->whereNotNull('stone_id')->update(['stone_id' => null]);
            }
        }

        // jewellery_item_stones.stone_id is required, so the allocation row
        // itself goes. The piece keeps its own stone_name/carat snapshots.
        foreach (['jewellery_item_stones', 'stone_stock_movements', 'stone_intake_items', 'stone_intakes', 'stone_intake_drafts'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        DB::table('stones')->delete();
    }

    /**
     * Recreate `stones` in its final shape for SQLite (test) databases.
     *
     * The table is already empty by the time this runs, so nothing is copied
     * across. Column types and names match the MySQL path exactly, which is
     * what keeps the test schema honest about production.
     */
    private function rebuildForSqlite(): void
    {
        Schema::dropIfExists('stones');

        Schema::create('stones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('stone_code', 20)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('name', 150);
            $table->string('shape', 60)->nullable();
            $table->string('dimension', 40)->nullable();
            $table->decimal('carat', 10, 3)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('pairs')->default(0);
            $table->decimal('weight_grams', 12, 3)->nullable();
            $table->string('pricing_mode', 20)->default('per_piece');
            $table->decimal('price_per_carat', 14, 4)->nullable();
            $table->decimal('price_per_piece', 14, 4)->nullable();
            $table->decimal('price_per_gram', 14, 4)->nullable();
            $table->decimal('piece_price', 14, 4)->nullable();
            $table->decimal('total_cost', 16, 4)->default(0);
            $table->string('cost_formula', 120)->nullable();
            $table->string('status', 20)->default('in_stock');
            $table->string('tray_no', 30)->nullable();
            $table->string('remarks', 255)->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active']);
            $table->index(['branch_id', 'pricing_mode']);
            $table->index(['branch_id', 'tray_no']);
            $table->index('name');
        });
    }
};
