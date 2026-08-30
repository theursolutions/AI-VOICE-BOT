<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per payment attempt, whichever gateway took it.
 *
 * Stripe keeps its own invoices and we simply read them back. PayFast keeps
 * nothing we can query as a ledger — it authorises a payment and returns a
 * code — so if we do not record the attempt ourselves there is no answer to
 * "did this customer pay in March", and no way to reconcile a callback that
 * arrives twice or arrives late.
 *
 * Written BEFORE the customer is sent to the gateway, in `pending`. That
 * ordering is the point: a row created only on success cannot explain a
 * customer who was charged but never came back, which is exactly the case
 * anyone actually needs the record for.
 *
 * `reference` is ours (the basket id we generate) rather than the gateway's,
 * because we have to name the payment before the gateway has seen it. The
 * gateway's own id lands in `gateway_ref` once it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('plan_price_id')->nullable();

            $table->string('gateway', 30);

            // Our correlation id, generated before the handoff. Unique because
            // a duplicate would make a callback ambiguous, which is the one
            // thing this table exists to prevent.
            $table->string('reference', 64)->unique();

            // Theirs, once known.
            $table->string('gateway_ref', 120)->nullable();

            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PKR');

            // pending | paid | failed | abandoned
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason', 500)->nullable();

            // What the period buys, so a successful charge can extend the
            // subscription without re-deriving it from a plan that may have
            // been repriced in the meantime.
            $table->string('interval', 20)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            // The provider's own answer, verbatim. Support questions are
            // unanswerable without it, and it is the only record of what a
            // gateway actually said at the time.
            $table->json('raw')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['gateway', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_charges');
    }
};
