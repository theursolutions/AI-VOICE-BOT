<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-account messaging support on sessions:
 *
 *  - `channel_account` — the business number/page the customer messaged
 *    (WhatsApp phone_number_id / FB page id / IG id). Combined with
 *    `channel` it pins the conversation to one inbound connection, so
 *    replies route back out the same number and a customer who messages
 *    two different numbers gets two separate threads.
 *  - `last_inbound_at` — when the customer last messaged us, used to
 *    enforce Meta's 24-hour customer-service window for free-form replies.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
            $table->string('channel_account', 191)->nullable()->after('external_id');
            $table->integer('last_inbound_at')->nullable()->after('last_activity_at');
            $table->index(['channel', 'channel_account', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
            $table->dropIndex(['channel', 'channel_account', 'status']);
            $table->dropColumn(['channel_account', 'last_inbound_at']);
        });
    }
};
