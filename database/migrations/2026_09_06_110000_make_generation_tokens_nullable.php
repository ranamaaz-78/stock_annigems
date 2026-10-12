<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make generated codes nullable so a disabled feature can skip future
 * generation without inventing a dummy token.
 *
 * Existing rows keep the values they were issued. Unique indexes stay:
 * MySQL and SQLite both allow many NULLs under a unique constraint, so
 * "no barcode" does not collide with another "no barcode".
 *
 * Disabling a toggle must never rewrite or hide a token already printed.
 */
return new class extends Migration
{
    /**
     * Allow future stones, jewellery pieces and invoices to be created
     * without a barcode or public token.
     */
    public function up(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable()->change();
        });

        Schema::table('jewellery_items', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable()->change();
            $table->string('qr_token', 64)->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('verification_token', 64)->nullable()->change();
        });
    }

    /**
     * Restore NOT NULL. Only safe if every row has a value.
     */
    public function down(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable(false)->change();
        });

        Schema::table('jewellery_items', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable(false)->change();
            $table->string('qr_token', 64)->nullable(false)->change();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('verification_token', 64)->nullable(false)->change();
        });
    }
};
