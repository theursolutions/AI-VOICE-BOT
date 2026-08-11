<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing columns on `clients` — the billable entity.
 *
 * NOTE ON TIMESTAMP CONVENTION: `clients` is a legacy table with integer unix
 * created_at/updated_at/deleted_at and `public $timestamps = false` on the
 * model. The columns added here are proper DATETIMEs, cast explicitly on the
 * model. Mixing is deliberate — new billing code should not inherit the old
 * convention, and these columns are only ever read through the Client model's
 * casts. See SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §5 C2/C3.
 *
 * `billing_status` and `access_state` are a DERIVED CACHE of what the
 * `subscriptions` table says. `subscriptions` remains authoritative; these
 * exist so the high-volume widget/API path can gate on a single already-loaded
 * column instead of joining on every inbound customer message.
 * SubscriptionService is the only writer — see its syncClientCache().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Stripe Customer. Not named *_id: DecodeHashids rewrites any
            // request key matching `*_id`. See ANALYSIS §5 C1.
            $table->string('stripe_customer_ref', 120)->nullable()->unique()->after('json_data');

            $table->string('billing_email', 190)->nullable()->after('stripe_customer_ref');
            $table->string('billing_name', 190)->nullable()->after('billing_email');
            // ISO-3166 alpha-2, as reported by Stripe at checkout. This is the
            // TAX/billing country of record — not the IP-detected display
            // country, which is a guess and never persisted here.
            $table->string('billing_country', 2)->nullable()->after('billing_name');

            // Default payment method summary, for the billing page. Never a
            // full PAN — Stripe holds the instrument, we hold the label.
            $table->string('pm_type', 40)->nullable()->after('billing_country');
            $table->string('pm_last_four', 4)->nullable()->after('pm_type');

            // ── Derived cache (authoritative source: subscriptions) ──────
            $table->unsignedBigInteger('current_plan_id')->nullable()->index()->after('pm_last_four');
            $table->string('billing_status', 30)->nullable()->index()->after('current_plan_id');

            // active | read_only | widget_only | locked
            // Read by EnsureSubscribed and by the public widget endpoint.
            $table->string('access_state', 20)->default('active')->index()->after('billing_status');

            $table->timestamp('billing_synced_at')->nullable()->after('access_state');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_customer_ref',
                'billing_email',
                'billing_name',
                'billing_country',
                'pm_type',
                'pm_last_four',
                'current_plan_id',
                'billing_status',
                'access_state',
                'billing_synced_at',
            ]);
        });
    }
};
