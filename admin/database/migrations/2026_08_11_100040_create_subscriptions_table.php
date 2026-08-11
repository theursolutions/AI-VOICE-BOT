<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per workspace subscription. The BILLABLE IS THE CLIENT (workspace),
 * not the User and not the Project — see SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md
 * §1.3 for why.
 *
 * This single table covers BOTH states:
 *
 *   Free window  — status='free', stripe_id NULL, free_ends_at set.
 *                  No Stripe customer is created until the first paid action.
 *   Paid/trial   — status mirrors stripe_status, stripe_id set.
 *
 * STRIPE IS AUTHORITATIVE for anything paid. `stripe_status` is written ONLY
 * by the webhook handler (or an explicit reconcile). `status` is our own
 * normalised view, which additionally understands 'free' and 'expired' —
 * states Stripe has no opinion about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->unsignedBigInteger('plan_price_id')->nullable()->index();

            // Reserved for future multi-subscription workspaces (add-ons).
            $table->string('type', 40)->default('default');

            // Our normalised lifecycle state. Superset of Stripe's:
            //   free | trialing | active | past_due | canceled | unpaid
            //   | incomplete | incomplete_expired | expired | paused
            $table->string('status', 30)->default('free')->index();

            // ── Stripe mirror (webhook-written only) ─────────────────────
            // Not named *_id — DecodeHashids would rewrite those keys.
            // See SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §5 C1.
            $table->string('stripe_subscription_ref', 120)->nullable()->unique();
            $table->string('stripe_customer_ref', 120)->nullable()->index();
            $table->string('stripe_price_ref', 120)->nullable();
            $table->string('stripe_status', 40)->nullable()->index();
            $table->unsignedInteger('quantity')->default(1);

            // Denormalised from plan_prices for fast rendering and so an
            // archived price still reports what the customer actually pays.
            $table->string('interval', 20)->nullable();
            $table->unsignedInteger('unit_amount')->nullable();   // USD cents
            $table->string('currency', 3)->default('usd');

            // ── Dates ────────────────────────────────────────────────────
            $table->timestamp('free_started_at')->nullable();
            $table->timestamp('free_ends_at')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();       // access truly stops

            // First failed payment. past_due grace is measured from here.
            $table->timestamp('past_due_since')->nullable();

            // ── Degraded access (free window expiry / dunning exhausted) ──
            $table->timestamp('read_only_since')->nullable();
            $table->timestamp('purge_after')->nullable()->index();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status'], 'subscriptions_client_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
