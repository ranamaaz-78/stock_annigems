<?php

use App\Services\CodeSequenceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Retire the separate Creditors registry: performas hang off sales customers.
 *
 * Every creditor is carried into customers first, so no contact is lost: a
 * customer with the same phone (trashed ones included, since the phone key is
 * global) is reused, otherwise a new customer is registered with the next
 * customer code. Performas are then re-pointed at that customer, and the
 * creditors table, its permissions, code sequence and settings are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performas', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('performa_number');
        });

        $this->carryCreditorsIntoCustomers();

        Schema::table('performas', function (Blueprint $table) {
            $table->dropForeign(['creditor_id']);
        });

        // SQLite keeps the column's index after dropForeign; drop it first.
        if (Schema::hasIndex('performas', 'performas_creditor_id_foreign')) {
            Schema::table('performas', function (Blueprint $table) {
                $table->dropIndex('performas_creditor_id_foreign');
            });
        }

        Schema::table('performas', function (Blueprint $table) {
            $table->dropColumn('creditor_id');
        });

        Schema::table('performas', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->index('customer_id');
        });

        Schema::dropIfExists('creditors');

        $permissionIds = DB::table('permissions')->where('name', 'like', 'creditors.%')->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        DB::table('code_sequences')->where('key', 'creditor')->delete();
        DB::table('settings')->whereIn('key', ['numbering_creditor_prefix', 'numbering_creditor_start'])->delete();
    }

    /**
     * Structure only: the creditor registry comes back empty and performas
     * keep their customer link. Creditor rows are not recreated.
     */
    public function down(): void
    {
        Schema::create('creditors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('creditor_code', 20)->unique();
            $table->string('name', 150);
            $table->string('phone', 30);
            $table->string('alternate_phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('nie', 20)->nullable();
            $table->string('address', 2000)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'phone']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'name']);
        });

        Schema::table('performas', function (Blueprint $table) {
            $table->unsignedBigInteger('creditor_id')->nullable()->after('performa_number');
            $table->foreign('creditor_id')->references('id')->on('creditors')->restrictOnDelete();
        });

        DB::table('code_sequences')->insertOrIgnore([
            'key' => 'creditor',
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Register (or reuse by phone) a customer for every creditor, and point
     * each performa at its creditor's customer.
     */
    private function carryCreditorsIntoCustomers(): void
    {
        $creditors = DB::table('creditors')->orderBy('id')->get();
        if ($creditors->isEmpty()) {
            return;
        }

        $now = now();
        $codes = app(CodeSequenceService::class);

        foreach ($creditors as $creditor) {
            $customer = DB::table('customers')->where('phone', $creditor->phone)->first();

            if ($customer) {
                $customerId = $customer->id;
                if ($customer->deleted_at !== null) {
                    DB::table('customers')->where('id', $customerId)->update(['deleted_at' => null, 'updated_at' => $now]);
                }
            } else {
                $customerId = DB::table('customers')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'customer_code' => $codes->nextCustomerCode(),
                    'name' => $creditor->name,
                    'phone' => $creditor->phone,
                    'alternate_phone' => $creditor->alternate_phone,
                    'email' => $creditor->email,
                    'nie' => $creditor->nie,
                    'address' => $creditor->address,
                    'city' => $creditor->city,
                    'notes' => $creditor->notes,
                    'status' => $creditor->status,
                    'created_by' => $creditor->created_by,
                    'created_by_role' => $creditor->created_by_role,
                    'created_at' => $creditor->created_at ?? $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('performas')->where('creditor_id', $creditor->id)->update(['customer_id' => $customerId]);
        }
    }
};
