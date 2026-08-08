<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track both tokens and, crucially, when the working one dies.
 *
 * The original flow stored whatever Facebook returned from the OAuth
 * exchange — a SHORT-LIVED user token, good for one to two hours. Every
 * connection silently stopped working the same afternoon it was made, with
 * nothing in the schema to explain why or to warn anyone in advance.
 *
 * Now:
 *   short_lived_token  what Meta first returned (kept for diagnosis)
 *   access_token       the working token — long-lived, and what we send with
 *   token_expires_at   NULL means never expires (page tokens derived from a
 *                      long-lived user token, and system-user tokens)
 *
 * Additive only: existing rows keep their access_token and simply have no
 * expiry recorded, so nothing breaks on deploy.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('channel_connections', function (Blueprint $table) {
            $table->text('short_lived_token')->nullable()->after('access_token');
            $table->timestamp('token_obtained_at')->nullable()->after('short_lived_token');
            // NULL = does not expire. Indexed so a scheduled refresh job can
            // find what is about to lapse without scanning the table.
            $table->timestamp('token_expires_at')->nullable()->index()->after('token_obtained_at');
            $table->json('token_scopes')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('channel_connections', function (Blueprint $table) {
            $table->dropIndex(['token_expires_at']);
            $table->dropColumn(['short_lived_token', 'token_obtained_at', 'token_expires_at', 'token_scopes']);
        });
    }
};
