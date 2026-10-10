<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each admin user's softphone login: the SIP extension the in-browser
 * phone registers as (e.g. 1002 on our Asterisk), its password (encrypted
 * at rest) and the WebSocket address of the SIP server.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_phone_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('extension', 32);
            $table->text('sip_password');
            $table->string('ws_url', 255);
            $table->string('display_name', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_phone_accounts');
    }
};
