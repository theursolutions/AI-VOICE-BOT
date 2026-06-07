<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('active_client_id')->nullable()->after('password');
            $table->integer('last_picked_at')->nullable()->after('active_client_id');

            $table->index('active_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['active_client_id']);
            $table->dropColumn(['active_client_id', 'last_picked_at']);
        });
    }
};
