<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price a lot had before it was last changed.
 *
 * When a lot's rate is edited, the rate it replaced is kept here with the
 * pricing mode it belonged to (a per-piece rate and a per-gram rate are not
 * comparable) and the moment it was replaced. Written only by StoneService
 * on an edit that really changes the rate.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->decimal('last_rate', 14, 4)->nullable()->after('price_per_gram')
                ->comment('Rate before the latest price change');
            $table->string('last_rate_mode', 20)->nullable()->after('last_rate')
                ->comment('per_carat | per_piece | per_gram of last_rate');
            $table->timestamp('rate_changed_at')->nullable()->after('last_rate_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->dropColumn(['last_rate', 'last_rate_mode', 'rate_changed_at']);
        });
    }
};
