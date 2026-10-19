<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each user's chosen interface language (es / en).
 *
 * Null means "use the app default" (config app.locale), so existing users
 * simply follow the default until they pick a language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable()->after('must_change_password')
                ->comment('Interface language chosen by the user; null = app default');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
