<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per renewal notice actually sent.
 *
 * Exists to make sending idempotent. The reminder command runs on a schedule
 * and asks "whose period ends in 7 days" — a question that stays true for the
 * whole of that day, so without a record of what has already gone out the
 * customer receives the same reminder on every run. A row written per
 * (subscription, period, offset, channel) makes a second send impossible rather
 * than unlikely.
 *
 * KEYED ON period_end, not just the subscription: the same subscription is
 * reminded again next month, and next month's notice must not be suppressed by
 * this month's. Including the period in the key is what lets the record be
 * permanent — it doubles as the history of what a customer was told and when,
 * which is the first thing anyone asks when a renewal is disputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_notices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('subscription_id');

            // The period this notice was about.
            $table->timestamp('period_end');

            // Which of the configured offsets this was (7, 3, 1 …).
            $table->unsignedSmallInteger('days_before');

            // email | whatsapp
            $table->string('channel', 20);

            // Where it went, recorded as sent rather than looked up later — the
            // address on file may change, and the question is always where the
            // notice actually went.
            $table->string('destination', 190)->nullable();

            $table->boolean('delivered')->default(false);
            $table->string('error', 500)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // The guard. A duplicate insert fails rather than sending twice.
            $table->unique(
                ['subscription_id', 'period_end', 'days_before', 'channel'],
                'renewal_notice_once'
            );

            $table->index(['client_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_notices');
    }
};
