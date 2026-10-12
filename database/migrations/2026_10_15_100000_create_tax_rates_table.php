<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax rates managed by SuperAdmin: IVA rates and Recargo de equivalencia rates.
 *
 * Nothing is fixed in code. A document stores a snapshot of what was applied
 * (tax_name, tax_rate, recargo_name, recargo_rate and their amounts), so editing
 * or deleting a rate later never changes a quotation or invoice already made.
 * Delivery notes and performas carry no tax and get no snapshot columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind', 10)->comment('iva | recargo');
            $table->string('name', 60);
            $table->decimal('rate', 6, 3)->default(0);
            $table->boolean('is_exempt')->default(false)->comment('IVA only: prints as exempt');
            $table->boolean('is_default')->default(false)->comment('One per kind; preselected on the POS');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kind', 'is_active']);
        });

        foreach (['quotations', 'invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('tax_name', 60)->nullable()->after('tax_amount')
                    ->comment('IVA rate name when the document was made');
                $table->string('recargo_name', 60)->nullable()->after('tax_name');
                $table->decimal('recargo_rate', 6, 3)->default(0)->after('recargo_name');
                $table->decimal('recargo_amount', 12, 2)->default(0)->after('recargo_rate');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['invoices', 'quotations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['tax_name', 'recargo_name', 'recargo_rate', 'recargo_amount']);
            });
        }

        Schema::dropIfExists('tax_rates');
    }
};
