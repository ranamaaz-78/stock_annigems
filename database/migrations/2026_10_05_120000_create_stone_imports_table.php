<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Excel import of stone lots.
 *
 * Records what the operator uploaded and how it went, so a sheet that was
 * only partly accepted can be reviewed later. `rejects_path` points at the
 * Excel of the rows that were not inserted, on the private stone-imports
 * disk; it is null when every row went in.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stone_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('filename', 255)->comment('File name as the operator uploaded it');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('inserted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->string('rejects_path', 255)->nullable()->comment('Private disk path of the rows-to-fix workbook');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stone_imports');
    }
};
