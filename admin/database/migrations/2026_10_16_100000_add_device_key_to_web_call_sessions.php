<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website-call limits per DEVICE, not per IP: everyone on one office or home
 * network shares a public IP, and five people there must get five calls.
 * The call button remembers a random id per browser and sends it along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_call_sessions', function (Blueprint $table) {
            $table->string('device_key', 64)->nullable()->after('ip');
            $table->index(['project_id', 'device_key']);
        });
    }

    public function down(): void
    {
        Schema::table('web_call_sessions', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'device_key']);
            $table->dropColumn('device_key');
        });
    }
};
