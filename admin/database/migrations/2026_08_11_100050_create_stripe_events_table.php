<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for Stripe webhooks.
 *
 * Stripe guarantees AT LEAST ONCE delivery, not exactly once. It retries on
 * any non-2xx, and it can legitimately send the same event twice. Without a
 * ledger, a retried `invoice.paid` double-extends a period and a retried
 * `checkout.session.completed` can create a second subscription row.
 *
 * The UNIQUE index on stripe_event_id is the actual guarantee — the handler
 * INSERTs first and treats a duplicate-key violation as "already seen, skip".
 * Checking-then-inserting would still race under concurrent delivery.
 *
 * Rows are kept (not deleted after processing) so failed events can be
 * replayed and so there is an audit trail of what Stripe actually told us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            // evt_… — the idempotency key. UNIQUE is load-bearing.
            $table->string('stripe_event_id', 120)->unique();

            $table->string('type', 100)->index();
            $table->string('api_version', 40)->nullable();
            $table->boolean('livemode')->nullable();

            // pending → processed | failed | skipped
            $table->string('status', 20)->default('pending')->index();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();

            // Full payload, so an event can be replayed without re-fetching.
            $table->longText('payload')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
