<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments for invoices and delivery notes.
 *
 * A document is pending until a payment is recorded against it, and paid once
 * its payments cover the total. Existing invoices were always taken in full
 * when they were made, so each one gets a payment row dated that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('payable_type', 120);
            $table->unsignedBigInteger('payable_id');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 30);
            $table->string('payment_reference', 100)->nullable();
            $table->date('paid_on');
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index(['branch_id', 'paid_on']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('payment_method', 30)->nullable()->change();
            $table->string('payment_status', 10)->default('paid')->after('payment_reference');
            $table->index(['branch_id', 'payment_status']);
        });

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->string('payment_status', 10)->default('pending')->after('status');
        });

        // Every existing invoice was paid in full on its own date.
        DB::table('invoices')->orderBy('id')->chunkById(200, function ($rows): void {
            $now = now();
            $insert = [];
            foreach ($rows as $row) {
                $insert[] = [
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'branch_id' => $row->branch_id,
                    'payable_type' => \App\Models\Invoice::class,
                    'payable_id' => $row->id,
                    'amount' => $row->grand_total,
                    'payment_method' => $row->payment_method ?: 'cash',
                    'payment_reference' => $row->payment_reference,
                    'paid_on' => $row->invoice_date,
                    'created_by' => $row->created_by,
                    'created_by_role' => $row->created_by_role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('payments')->insert($insert);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->dropColumn('payment_status');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'payment_status']);
            $table->dropColumn('payment_status');
        });

        Schema::dropIfExists('payments');
    }
};
