<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allowance granted to one workspace outside its plan.
 *
 * For everything the catalogue cannot express: a pilot that needs 40,000
 * messages on a Starter price, two extra seats promised in a sales call, an
 * apology after an outage. All of that was previously done by inventing a plan,
 * which leaves the catalogue full of one-customer rows nobody dares delete, or
 * by editing a plan every other customer is also on.
 *
 * ADDITIVE, exactly like a purchased add-on. `clientLimit()` already composed
 * plan + add-ons, so a grant is a third term in the same sum rather than a
 * parallel notion of entitlement — which means every limit check, meter and
 * upsell already respects it with no further work, including the ones written
 * after this.
 *
 * WHY A TABLE RATHER THAN A COLUMN ON clients: a grant has provenance. Who
 * granted it, when, why, and when it lapses are the questions asked six months
 * later when a customer is on more than they pay for and nobody remembers
 * agreeing to it. A JSON blob of numbers answers none of them.
 *
 * EXPIRY IS THE POINT, not a nicety. A grant with no end date is a permanent
 * discount created by whoever was on the call, and the usual reason for one — a
 * trial, a migration, a goodwill gesture — is temporary. Null is still allowed,
 * because some grants really are permanent, but it has to be chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // The features.key this tops up. Stored as a key rather than an id
            // so a grant survives the feature being rebuilt, and reads plainly
            // in an audit log — "messages +20000" needs no join to understand.
            $table->string('feature_key', 80);

            // Signed: -1 means unlimited, matching the plan_features convention,
            // and a negative value is also how a grant could pull an allowance
            // DOWN if that is ever needed.
            $table->integer('value');

            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // One row per client per feature: a top-up is a single number, and
            // allowing several would make the total depend on rows nobody is
            // looking at. Raising a grant edits it.
            $table->unique(['client_id', 'feature_key']);
            // Read on every limit check.
            $table->index(['client_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_grants');
    }
};
