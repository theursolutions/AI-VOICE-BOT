<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-window / trial abuse control.
 *
 * A User can create unlimited Client workspaces (SetupController), so keying
 * the free week on the workspace alone gives away unlimited free weeks to
 * anyone willing to click "new workspace". This table records the identities
 * that have already consumed one.
 *
 * Kinds:
 *   user   — the owning user id
 *   email  — NORMALISED email (lowercased, +tags stripped, dots removed for
 *            Gmail-family domains). Defeats me+1@, me+2@, m.e@.
 *   card   — Stripe PaymentMethod `fingerprint`, which is stable for the same
 *            physical card across different Stripe Customers. The strongest
 *            signal available, and the main reason to collect a card.
 *   domain — the business website's registrable domain: one free week per
 *            business, not per employee.
 *
 * `value` is stored HASHED (sha256). We only ever need equality, and an
 * unhashed table of every signup email + card fingerprint is a liability
 * that buys nothing.
 *
 * A hit does NOT block the purchase — the customer goes straight to paid
 * checkout with no free window. Hard-blocking a paying customer over a
 * heuristic is always the wrong trade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_fingerprints', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->enum('kind', ['user', 'email', 'card', 'domain'])->index();
            $table->string('value_hash', 64);          // sha256 hex

            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // What was consumed: 'free_window' or 'trial'.
            $table->string('consumed_for', 30)->default('free_window');
            $table->timestamp('consumed_at')->nullable();

            // Super-admin override — an audited "grant another free week".
            $table->boolean('is_waived')->default(false);
            $table->unsignedBigInteger('waived_by')->nullable();
            $table->timestamp('waived_at')->nullable();

            $table->timestamps();

            $table->unique(['kind', 'value_hash', 'consumed_for'], 'trial_fingerprints_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_fingerprints');
    }
};
