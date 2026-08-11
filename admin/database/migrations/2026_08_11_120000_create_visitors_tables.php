<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passive visitor analytics for the public marketing site.
 *
 * Two tables rather than one:
 *
 *   visitors            — one row per distinct visitor, carrying the derived
 *                         facts (location, device, browser) plus rolling
 *                         first/last-seen and a page-view counter. The ops
 *                         list and the headline count read this directly, so
 *                         neither needs a GROUP BY over every hit ever taken.
 *   visitor_page_views  — the raw trail, one row per page. Only read when an
 *                         operator drills into a single visitor.
 *
 * Everything stored here comes from the request itself — IP, User-Agent,
 * Accept-Language, Referer — so nothing is asked of the visitor and no
 * client-side script is involved. Location is derived from the IP after the
 * fact; see App\Support\IpLocator.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();

            // Stable per-visitor fingerprint: hash(ip + user agent + app key).
            // Deliberately NOT a cookie — no client storage is touched, so
            // there is nothing to disclose or ask about. The trade-off is
            // accuracy: two people behind one NAT on the same browser build
            // collapse into one row, and the same person on wifi vs mobile
            // data counts twice.
            $table->char('visitor_key', 40)->unique();

            $table->string('ip', 45)->nullable();

            // ── Derived from the IP (filled in later, never inline on an
            //    HTTP lookup — see IpLocator) ────────────────────────────
            $table->string('continent', 40)->nullable();
            $table->string('country', 80)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('region', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('postal', 20)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('org', 120)->nullable();           // ISP / carrier
            $table->string('asn', 24)->nullable();            // e.g. AS17557
            $table->string('connection_type', 24)->nullable();// broadband | mobile | hosting
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            // pending | done | private | failed — `failed` and `private` stop
            // us retrying an address that will never resolve.
            $table->string('geo_status', 12)->default('pending');

            // ── Derived from the User-Agent ────────────────────────────
            $table->string('user_agent', 500)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('browser_version', 30)->nullable();
            $table->string('os', 60)->nullable();
            $table->string('device_type', 20)->nullable();   // desktop|mobile|tablet|bot
            $table->boolean('is_bot')->default(false);

            $table->string('language', 20)->nullable();       // from Accept-Language

            // ── Acquisition ────────────────────────────────────────────
            $table->string('landing_path', 500)->nullable();  // first page seen
            $table->string('last_path', 500)->nullable();
            $table->string('referrer', 500)->nullable();      // first referrer
            $table->string('referrer_host', 120)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();

            $table->unsignedInteger('page_views')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // The ops list is "newest first, optionally excluding bots".
            $table->index(['is_bot', 'last_seen_at']);
            $table->index('last_seen_at');
            $table->index('ip');
            $table->index('country_code');
            // Lets the geolocate command find work without a full scan.
            $table->index('geo_status');
        });

        Schema::create('visitor_page_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visitor_id');
            $table->string('path', 500);
            $table->string('referrer', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['visitor_id', 'created_at']);
            $table->foreign('visitor_id')->references('id')->on('visitors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_page_views');
        Schema::dropIfExists('visitors');
    }
};
