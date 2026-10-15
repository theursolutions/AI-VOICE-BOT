<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM lead "Add to CRM" created for a prospect, so adding it twice reuses
 * that lead instead of creating another, and the prospect page can link to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('lg_prospects', function (Blueprint $t) {
            $t->unsignedBigInteger('lead_id')->nullable()->after('contact_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('lg_prospects', function (Blueprint $t) {
            $t->dropColumn('lead_id');
        });
    }
};
