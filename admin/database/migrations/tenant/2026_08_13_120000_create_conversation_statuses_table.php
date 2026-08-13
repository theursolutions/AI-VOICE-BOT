<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project conversation statuses, defined by the customer.
 *
 * Deliberately a TABLE rather than widening the `sessions.status` enum. Two
 * reasons, and the second is the one that matters:
 *
 *  1. `sessions.status` is machine state — active / ended / failed — written
 *    by the engine. This is human state: "Waiting on customer", "Escalated",
 *    "Won". Mixing them means one column with two owners and no way to tell
 *    which set a value belongs to.
 *  2. An enum cannot be edited by a customer. Every new status would be a
 *    migration and a deploy, which is exactly what "users can create their
 *    own statuses" rules out.
 *
 * `is_closing` is what stops this being decorative: a status flagged closing
 * marks the conversation done, so the inbox filters and counts can treat it
 * as closed without hard-coding anyone's chosen label.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('conversation_statuses', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');
            $table->string('name', 60);
            // Hex, chosen from a fixed palette in the UI rather than free
            // text, so a status can never be styled invisible.
            $table->string('color', 9)->default('#64748b');
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Applied to a brand-new conversation, if the project sets one.
            $table->boolean('is_default')->default(false);
            // Treat conversations in this status as finished.
            $table->boolean('is_closing')->default(false);
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->default('Yes');

            $table->index(['project_id', 'status']);
        });

        // The assignment itself. A nullable column rather than a value inside
        // sessions.metadata, because the inbox filters on it — a JSON path
        // cannot use an index, and MySQL and SQLite disagree on the syntax.
        if (! Schema::connection('tenant')->hasColumn('sessions', 'conversation_status_id')) {
            Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
                $table->unsignedBigInteger('conversation_status_id')->nullable()->after('handoff_status');
                $table->index('conversation_status_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('sessions', 'conversation_status_id')) {
            Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
                $table->dropIndex(['conversation_status_id']);
                $table->dropColumn('conversation_status_id');
            });
        }
        Schema::connection('tenant')->dropIfExists('conversation_statuses');
    }
};
