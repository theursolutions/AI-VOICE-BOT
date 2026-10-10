<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Reset limit" for a visitor IP (Ops → Website calls, Telephony → Website
 * call button): their calls stop counting toward the daily limit without
 * being deleted, so the "how many people tried it" numbers stay true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_call_sessions', function (Blueprint $table) {
            $table->boolean('counts_toward_limit')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('web_call_sessions', function (Blueprint $table) {
            $table->dropColumn('counts_toward_limit');
        });
    }
};
