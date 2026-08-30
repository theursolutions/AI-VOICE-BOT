<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which calls a brain is allowed to serve.
 *
 * One customer message costs four LLM calls, and only one of them — the reply —
 * is ever read by a human. The other three classify a tool, extract lead fields
 * and compress history into a summary; each returns JSON or internal prose that
 * nobody sees. Paying reply-grade rates for all four was ~26% of the bill for no
 * benefit a customer could perceive.
 *
 * With this column a super admin can point replies at a capable model and the
 * machinery at a cheap one, which measured at 22% off the per-message cost with
 * no change to what the customer reads.
 *
 * 'any' is the default and the pre-existing behaviour, so an install that never
 * touches this keeps working exactly as it did.
 *
 * NOTE: the split applies to the PLATFORM POOL ONLY. A client who brought their
 * own key gets every call on their brain, because all four carry the customer's
 * words and routing some of them through our provider account is the precise
 * thing bring-your-own-key exists to prevent. That rule lives in BrainResolver,
 * not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_brains', function (Blueprint $table) {
            $table->enum('serves', ['any', 'reply', 'machinery'])
                ->default('any')
                ->after('priority');

            // Resolution reads (serves, priority) inside an is_active +
            // is_verified scope on every uncached lookup.
            $table->index(['serves', 'priority'], 'ai_brains_serves_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_brains', function (Blueprint $table) {
            $table->dropIndex('ai_brains_serves_priority_idx');
            $table->dropColumn('serves');
        });
    }
};
