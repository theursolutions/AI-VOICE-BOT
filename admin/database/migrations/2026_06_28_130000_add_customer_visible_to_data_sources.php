<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audience gate for data sources.
 *
 * `customer_visible` decides whether a source may be used to answer
 * questions from CUSTOMERS (the public web chat + voice widget). It does
 * NOT affect the internal "Ask AI" assistant, which always sees every
 * source (subject to the team member's RBAC project permissions).
 *
 * Deny-by-default: new and existing sources start hidden from customers.
 * The project owner explicitly opts a source in on the data-source page.
 * With nothing opted in, the customer bot answers only general questions
 * about the project (no retrieval context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->boolean('customer_visible')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('customer_visible');
        });
    }
};
