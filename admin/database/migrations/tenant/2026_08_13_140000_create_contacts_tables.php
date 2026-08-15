<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The person behind the conversations.
 *
 * Until now a `lead` hung off a single `session`, and a session is scoped to
 * one channel and one business account. So the same human writing on
 * WhatsApp and then Instagram produced two sessions, two leads and no
 * connection between them — the inbox greeted a returning customer as a
 * stranger. For a product called a CRM that is the gap that matters most.
 *
 * Two tables rather than columns on `sessions`:
 *
 *   contacts            one row per human
 *   contact_identities  the handles they are known by — a WhatsApp number,
 *                       an IGSID, a PSID. One contact, many identities.
 *
 * The identity table is what makes merging possible without rewriting
 * history: linking two contacts means repointing their identity rows, not
 * touching a single message.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('contacts', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');

            $table->string('name', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();
            // Stored locally by ContactAvatars, or set by an agent.
            $table->string('avatar', 512)->nullable();

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->integer('first_seen_at')->nullable();
            $table->integer('last_seen_at')->nullable();

            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('deleted_at')->nullable();

            // Email and phone are the only identifiers strong enough to merge
            // on, so they are indexed for exactly that lookup. NOT unique:
            // two people genuinely share a family email, and a unique
            // constraint would turn that into a failed insert on an inbound
            // message rather than a merge decision.
            $table->index(['project_id', 'email']);
            $table->index(['project_id', 'phone']);
            $table->index(['project_id', 'last_seen_at']);
        });

        Schema::connection('tenant')->create('contact_identities', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');
            $table->unsignedBigInteger('contact_id');

            // whatsapp | instagram | facebook | web | phone …
            $table->string('channel', 24);
            // wa_id / IGSID / PSID — whatever the platform calls this person.
            $table->string('external_id', 191);
            // The business account they reached us on, when it matters.
            $table->string('channel_account', 191)->nullable();

            $table->integer('created_at')->nullable();

            // The heart of it: one handle belongs to exactly one contact.
            // Enforced in the database because resolution runs from a queue
            // worker, and two messages arriving together would otherwise
            // race and create duplicate people.
            $table->unique(['project_id', 'channel', 'external_id'], 'contact_identity_unique');
            $table->index('contact_id');
        });

        // Attach existing records. Nullable because backfill is a separate,
        // resumable step — an un-backfilled row must keep working.
        Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable()->after('project_id');
            $table->index('contact_id');
        });

        Schema::connection('tenant')->table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable()->after('project_id');
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('leads', function (Blueprint $table) {
            $table->dropIndex(['contact_id']);
            $table->dropColumn('contact_id');
        });
        Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
            $table->dropIndex(['contact_id']);
            $table->dropColumn('contact_id');
        });
        Schema::connection('tenant')->dropIfExists('contact_identities');
        Schema::connection('tenant')->dropIfExists('contacts');
    }
};
