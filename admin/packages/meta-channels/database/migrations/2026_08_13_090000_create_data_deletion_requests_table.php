<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta's data-deletion callback is not a fire-and-forget webhook: the reply
 * must contain a status URL the user can open later to see what happened,
 * which means every request has to be durable and addressable.
 *
 * Keeping the row after the purge is deliberate and is not a contradiction
 * of the deletion itself. What survives is the fact that a request was made
 * and honoured — no message bodies, no profile, just an opaque platform id.
 * That record is what lets us answer "did you actually delete my data?"
 * months later, which is exactly the question this endpoint exists to
 * answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->id();

            // Which platform asked, and for whom. `external_user_id` is a
            // PSID/IGSID — page-scoped and useless outside our own data,
            // which is why it is safe to keep.
            $table->string('provider', 32);
            $table->string('external_user_id', 191);

            // Public handle for the status page. Random, not sequential, so
            // one confirmation code cannot be used to enumerate others'.
            $table->string('confirmation_code', 64)->unique();

            $table->string('status', 24)->default('pending'); // pending|completed|failed
            $table->string('source', 32)->default('meta_callback'); // or manual_request

            // What was actually removed, for the status page to report.
            $table->unsignedInteger('sessions_deleted')->default(0);
            $table->unsignedInteger('messages_deleted')->default(0);

            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // A repeat request for the same user is common (people click
            // twice); this makes finding the earlier one cheap.
            $table->index(['provider', 'external_user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
    }
};
