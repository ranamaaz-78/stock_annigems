<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen business-code columns so a SuperAdmin prefix like CUSTOMER-
 * plus numbering_padding (up to 10) still fits. The original 20-char
 * width assumed CUS-000125 (10 chars).
 *
 * SQLite ignores VARCHAR length, so this is a MySQL-only ALTER.
 */
return new class extends Migration
{
    /**
     * Table => code column that SuperAdmin prefixes are written into.
     *
     * @var array<string, string>
     */
    private array $columns = [
        'jewellery_items' => 'item_code',
        'stones' => 'stone_code',
        'stone_intakes' => 'intake_number',
        'customers' => 'customer_code',
        'quotations' => 'quotation_number',
        'invoices' => 'invoice_number',
    ];

    /**
     * Prefix max 16 + padding max 10 = 26; 32 leaves room for the dash.
     */
    public function up(): void
    {
        $this->resize(32);
    }

    /**
     * Restore the original 20-character width.
     */
    public function down(): void
    {
        $this->resize(20);
    }

    /**
     * ALTER one width across every business-code column.
     */
    private function resize(int $length): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->columns as $table => $column) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR({$length}) NOT NULL");
        }
    }
};
