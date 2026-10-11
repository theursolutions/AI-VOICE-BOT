<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language the visitor picked in the "Call us" box (English / اردو):
 * the AI greets them in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_call_sessions', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->after('page_url');
        });
    }

    public function down(): void
    {
        Schema::table('web_call_sessions', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
