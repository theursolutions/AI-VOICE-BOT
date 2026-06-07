<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work queue for customer-hosted agents (Tier 3b).
 *
 * Flow:
 *   1. AgentResolver enqueues a row (status=pending).
 *   2. Agent long-polls GET /agent/poll, picks the oldest pending row
 *      for itself, transitions to in_progress.
 *   3. Agent runs SQL locally, POSTs /agent/result, row goes to done.
 *   4. AgentResolver polls for status=done, reads result, returns.
 *
 * `request_id` is the externally-shared id; rows are pruned after
 * `completed_at + 1 hour`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_queries', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('agent_id');
            $table->string('request_id', 64)->unique();
            $table->text('sql');
            $table->json('params')->nullable();
            $table->integer('max_rows')->default(100);
            $table->enum('status', ['pending', 'in_progress', 'done', 'failed', 'timeout'])
                  ->default('pending');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('picked_at')->nullable();
            $table->integer('completed_at')->nullable();

            $table->index(['agent_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_queries');
    }
};
