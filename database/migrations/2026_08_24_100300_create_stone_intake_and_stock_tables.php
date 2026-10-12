<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stone intake batches, line items, stock movements, and per-user drafts.
 *
 * Intakes are immutable historical records. Corrections wait for a later
 * adjustment once out-flow exists. Movements store quantity_before and
 * quantity_after so the ledger is self-verifying.
 *
 * Drafts persist the in-progress scan list on the server (not browser
 * storage) so a refresh or device change cannot lose a fifty-packet batch.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stone_intakes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('intake_number', 20)->unique()->comment('System-wide SIN-000125');
            $table->date('intake_date');
            $table->unsignedInteger('total_stone_types')->default(0);
            $table->unsignedInteger('total_quantity')->default(0);
            $table->decimal('total_carat', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('performed_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'intake_date']);
        });

        Schema::create('stone_intake_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stone_intake_id')->constrained('stone_intakes')->restrictOnDelete();
            $table->foreignId('stone_id')->constrained('stones')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('carat_snapshot', 8, 3);
            $table->string('name_snapshot', 150);
            $table->unsignedInteger('scanned_count')->default(0)
                ->comment('How many of quantity came from scanner vs typed');
            $table->timestamps();

            $table->unique(['stone_intake_id', 'stone_id']);
        });

        Schema::create('stone_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('stone_id')->constrained('stones')->restrictOnDelete();
            $table->string('type', 30)->comment('StoneMovementType; only intake is used today');
            $table->integer('quantity_change')->comment('Signed: positive in, negative out');
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            $table->nullableMorphs('reference');
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('performed_by_role', 30)->nullable();
            $table->timestamps();

            $table->index(['stone_id', 'created_at']);
        });

        Schema::create('stone_intake_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->json('payload')->comment('rows, date, notes, beep mute');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stone_intake_drafts');
        Schema::dropIfExists('stone_stock_movements');
        Schema::dropIfExists('stone_intake_items');
        Schema::dropIfExists('stone_intakes');
    }
};
