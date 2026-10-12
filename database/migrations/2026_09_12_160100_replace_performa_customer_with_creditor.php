<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Point performas at creditors instead of sales customers.
 *
 * Existing performas (if any) are remapped: a branch creditor is created
 * from the old customer name/phone so historical ledgers stay readable.
 * Settlement invoices keep their name/phone snapshots and are not rewritten.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('performas', function (Blueprint $table) {
            $table->unsignedBigInteger('creditor_id')->nullable()->after('performa_number');
        });

        $this->backfillCreditors();

        // SQLite keeps performas_customer_id_index after dropForeign; drop
        // that leftover index first or ALTER TABLE DROP COLUMN fails.
        Schema::table('performas', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        if (Schema::hasIndex('performas', 'performas_customer_id_index')) {
            Schema::table('performas', function (Blueprint $table) {
                $table->dropIndex('performas_customer_id_index');
            });
        }

        Schema::table('performas', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });

        Schema::table('performas', function (Blueprint $table) {
            $table->foreign('creditor_id')->references('id')->on('creditors')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performas', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('performa_number');
        });

        $this->restoreCustomers();

        Schema::table('performas', function (Blueprint $table) {
            $table->dropForeign(['creditor_id']);
            $table->dropColumn('creditor_id');
        });

        Schema::table('performas', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->index('customer_id');
        });
    }

    /**
     * Create branch creditors from leftover customer links, then assign them.
     */
    private function backfillCreditors(): void
    {
        $rows = DB::table('performas')->whereNull('creditor_id')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $now = now();
        $sequence = (int) DB::table('code_sequences')->where('key', 'creditor')->value('last_number');

        foreach ($rows as $performa) {
            $customer = DB::table('customers')->where('id', $performa->customer_id)->first();
            $phone = $customer?->phone ?: ('legacy-'.$performa->id);
            $name = $customer?->name ?: 'Creditor';

            $existing = DB::table('creditors')
                ->where('branch_id', $performa->branch_id)
                ->where('phone', $phone)
                ->first();

            if ($existing) {
                $creditorId = $existing->id;
            } else {
                $sequence++;
                $creditorId = DB::table('creditors')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'branch_id' => $performa->branch_id,
                    'creditor_code' => 'CRD-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'phone' => $phone,
                    'alternate_phone' => $customer?->alternate_phone,
                    'email' => $customer?->email,
                    'address' => $customer?->address,
                    'city' => $customer?->city,
                    'notes' => $customer?->notes,
                    'status' => true,
                    'created_by' => $performa->created_by,
                    'created_by_role' => $performa->created_by_role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('performas')->where('id', $performa->id)->update([
                'creditor_id' => $creditorId,
            ]);
        }

        if ($sequence > 0) {
            DB::table('code_sequences')->updateOrInsert(
                ['key' => 'creditor'],
                ['last_number' => $sequence, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    /**
     * Best-effort reverse: map creditor name/phone back onto a customer row.
     */
    private function restoreCustomers(): void
    {
        $rows = DB::table('performas')->whereNull('customer_id')->get();

        foreach ($rows as $performa) {
            $creditor = DB::table('creditors')->where('id', $performa->creditor_id)->first();
            if (! $creditor) {
                continue;
            }

            $customer = DB::table('customers')->where('phone', $creditor->phone)->first();
            if (! $customer) {
                continue;
            }

            DB::table('performas')->where('id', $performa->id)->update([
                'customer_id' => $customer->id,
            ]);
        }
    }
};
