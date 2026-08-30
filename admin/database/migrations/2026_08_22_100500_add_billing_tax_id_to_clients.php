<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax registration number, so billing details can be edited in the product.
 *
 * The one thing Stripe's hosted portal did that the app could not. `clients`
 * already carried billing_name, billing_email and billing_country — written by
 * the checkout webhook and never editable — so this completes the set and lets
 * the portal be retired without losing a capability.
 *
 * Free text, deliberately. A GST number, a VAT number, an NTN and an EIN have
 * nothing in common but the fact that a customer needs it printed on an invoice,
 * and validating per country would reject a legitimate identifier long before it
 * ever caught a typo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('billing_tax_id', 60)->nullable()->after('billing_country');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('billing_tax_id');
        });
    }
};
