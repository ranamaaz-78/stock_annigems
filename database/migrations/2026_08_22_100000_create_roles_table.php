<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System roles table (Module 2).
 *
 * Holds machine names (admin, cashier, etc.) and display metadata.
 * Built-in roles are marked is_system=true and cannot be deleted by branch Admins.
 * No uuid — roles are never addressed by URL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique()->comment('Machine name, e.g. admin, cashier');
            $table->string('display_name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(true)->comment('Built-in roles cannot be deleted');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
