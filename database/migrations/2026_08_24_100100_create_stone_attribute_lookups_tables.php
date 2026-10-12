<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global stone lookup vocabularies (types, shapes, colors, clarities, cuts).
 *
 * Not branch-scoped: every branch shares one vocabulary so "VS1" cannot
 * diverge into "vs 1" at another counter. Colors and clarities may bind to
 * a stone type (diamond D–J grades) or stay global (null stone_type_id).
 *
 * Any branch user with stones.attributes may edit these rows — that is
 * intentional and documented on the attributes screen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stone_types', function (Blueprint $table) {
            $this->lookupColumns($table);
        });

        Schema::create('stone_shapes', function (Blueprint $table) {
            $this->lookupColumns($table);
        });

        Schema::create('stone_cuts', function (Blueprint $table) {
            $this->lookupColumns($table);
        });

        Schema::create('stone_colors', function (Blueprint $table) {
            $this->lookupColumns($table, bindType: true);
        });

        Schema::create('stone_clarities', function (Blueprint $table) {
            $this->lookupColumns($table, bindType: true);
        });
    }

    /**
     * Shared columns for a managed lookup table.
     */
    private function lookupColumns(Blueprint $table, bool $bindType = false): void
    {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('name', 100);
        if ($bindType) {
            $table->foreignId('stone_type_id')->nullable()->constrained('stone_types')->restrictOnDelete();
        }
        $table->boolean('is_active')->default(true);
        $table->unsignedSmallInteger('sort_order')->default(0);
        $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        $table->string('created_by_role', 30)->nullable();
        $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
        $table->string('updated_by_role', 30)->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->unique('name');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stone_clarities');
        Schema::dropIfExists('stone_colors');
        Schema::dropIfExists('stone_cuts');
        Schema::dropIfExists('stone_shapes');
        Schema::dropIfExists('stone_types');
    }
};
