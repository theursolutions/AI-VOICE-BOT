<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The model backends the platform can route a call to.
 *
 * One table for both scopes, keyed on `client_id`:
 *   NULL      a platform brain, managed by the super admin, shared by everyone
 *   set       that client's own brain, using their own key and their own bill
 *
 * Keeping them in one table means the resolver walks a single ordered list
 * instead of reconciling two, and a client brain and a platform brain are the
 * same shape everywhere downstream.
 *
 * Replaces BrainSettingsController's env-file write, which could not work in the
 * container deployment: it wrote to voice-engine/.env, a path absent from the app
 * image and read by no one, in a container the voice-engine cannot see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_brains', function (Blueprint $table) {
            $table->id();

            // NULL = platform brain. Not a foreign key: clients are soft-deleted
            // here (deleted_at is a unix int), and a cascade would take a paying
            // client's brain configuration with a reversible deletion.
            $table->unsignedBigInteger('client_id')->nullable();

            $table->string('name', 120);

            // How to talk to it, not who made it. `openai_compat` covers every
            // provider speaking the OpenAI chat-completions wire format —
            // DeepSeek, OpenRouter, Together, Cerebras, Groq, Gemini — which is
            // why bring-your-own-brain works without a code change per vendor.
            // `anthropic` and `ollama` have their own wire formats.
            $table->enum('kind', ['openai_compat', 'anthropic', 'ollama'])->default('openai_compat');

            // Which preset this came from, for the UI only. Never used for
            // routing — `kind` decides that.
            $table->string('preset', 40)->nullable();

            $table->string('base_url', 255)->nullable();
            $table->string('model', 120)->nullable();

            // Encrypted at rest and never returned to the browser. Nullable
            // because a local Ollama brain has no key.
            $table->text('api_key')->nullable();

            $table->unsignedSmallInteger('max_tokens')->default(4096);

            // Position within its own scope. Lower runs first.
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(false);

            // Proven to work by a real one-token call. A brain that has never
            // passed this cannot be activated, because a mistyped key would
            // otherwise become a silent outage for every conversation it serves.
            $table->boolean('is_verified')->default(false);
            $table->integer('verified_at')->nullable();
            $table->string('verify_error', 500)->nullable();

            // Quota. NULL = unlimited. Counted in tokens (in + out), because
            // that is what every provider bills on and what /llm/respond
            // already returns to us.
            $table->unsignedBigInteger('quota_tokens')->nullable();
            $table->enum('quota_window', ['month', 'total'])->default('month');
            $table->unsignedBigInteger('tokens_used')->default(0);
            $table->integer('quota_reset_at')->nullable();

            // What a client is told this brain is called. Platform brains show a
            // neutral tier label — "Standard", "Fast" — so the vendor and model
            // behind our pricing stay ours. A client's own brain shows its real
            // name, since they configured it.
            $table->string('public_label', 60)->nullable();

            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();

            // The resolver's exact query: active brains in a scope, by priority.
            $table->index(['client_id', 'is_active', 'priority'], 'ai_brains_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_brains');
    }
};
