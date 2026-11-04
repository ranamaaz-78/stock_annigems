<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount and tax on a performa, the same way an invoice carries them.
 *
 * Only the choice is stored (discount type and value, the IVA and recargo
 * rate and name as they were when issued); the amounts are worked out from
 * the lines when the document is shown, so an edited performa never holds a
 * stale figure. Settlement invoices inherit this choice.
 *
 * Rows made before this migration keep no discount, and a null tax_type.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('performas', function (Blueprint $table): void {
            $table->string('discount_type', 20)->default('none')->after('status');
            $table->decimal('discount_value', 12, 2)->default(0)->after('discount_type');
            // null = issued before taxes were kept (settlement falls back to the default IVA);
            // 'none' = the issuer chose no tax.
            $table->string('tax_type', 20)->nullable()->after('discount_value');
            $table->decimal('tax_rate', 8, 2)->default(0)->after('tax_type');
            $table->string('tax_name', 100)->nullable()->after('tax_rate');
            $table->decimal('recargo_rate', 6, 3)->default(0)->after('tax_name');
            $table->string('recargo_name', 100)->nullable()->after('recargo_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performas', function (Blueprint $table): void {
            $table->dropColumn([
                'discount_type', 'discount_value', 'tax_type', 'tax_rate', 'tax_name', 'recargo_rate', 'recargo_name',
            ]);
        });
    }
};
