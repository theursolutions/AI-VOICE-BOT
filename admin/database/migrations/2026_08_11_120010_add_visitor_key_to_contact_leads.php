<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a captured lead back to the visitor record that produced it.
 *
 * This is the legitimate answer to "who is this person and how do we reach
 * them": we cannot read an email out of someone's browser (same-origin policy
 * makes that impossible, and buying it from a data broker is a different
 * decision entirely) — but the moment a visitor volunteers a phone number or
 * email, this column joins that contact to everything we already know about
 * their visit: pages read, referrer, campaign, location, device.
 *
 * Nullable and un-constrained on purpose: leads arriving from channels with no
 * web visit behind them (a phone call, an imported list) simply leave it null,
 * and pruning old analytics rows must never cascade into deleting a lead.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_leads', function (Blueprint $table) {
            $table->char('visitor_key', 40)->nullable()->after('referrer');
            $table->index('visitor_key');
        });
    }

    public function down(): void
    {
        Schema::table('contact_leads', function (Blueprint $table) {
            $table->dropIndex(['visitor_key']);
            $table->dropColumn('visitor_key');
        });
    }
};
