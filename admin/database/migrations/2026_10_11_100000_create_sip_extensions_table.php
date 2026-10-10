<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIP accounts on our Asterisk.
 *
 * sip_extensions — a client's own extensions (Telephony → Extensions), for
 * desk phones, MicroSIP/Zoiper and the admin's browser phone. One Asterisk
 * serves every client, so the account name carries the client:
 * "c<client>_<extension>". Main DB for that reason: it must be unique
 * across tenants.
 *
 * web_call_sessions — one row per website "Call us" click: the one-call
 * guest account issued for it, and the caller's IP, which is what the
 * daily limit counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sip_extensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('extension', 10);
            $table->string('name', 100);
            $table->string('kind', 10)->default('app');   // app | webrtc
            $table->string('sip_username', 40)->unique();
            $table->text('secret');                        // encrypted
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('pushed_at')->nullable();    // last accepted by Asterisk
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'extension']);
        });

        Schema::create('web_call_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('client_id');
            $table->string('ip', 45);
            $table->string('origin', 255)->nullable();
            $table->string('page_url', 500)->nullable();
            $table->string('sip_username', 40)->unique();
            $table->text('secret');                        // encrypted
            $table->string('status', 20)->default('issued'); // issued | started | ended | expired
            $table->unsignedBigInteger('session_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'ip', 'started_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_call_sessions');
        Schema::dropIfExists('sip_extensions');
    }
};
