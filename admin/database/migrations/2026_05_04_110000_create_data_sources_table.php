<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project pluggable data sources. Replaces the implicit `projects.db_*`
 * one-shot DB integration with a polymorphic model that supports:
 *
 *   website     — crawled HTML pages (Tier 1 RAG)
 *   document    — uploaded PDFs / CSVs / TXT  (Tier 1 RAG)
 *   crm_oauth   — HubSpot/Salesforce/etc. via OAuth (Tier 2)
 *   database    — direct DB credentials (Tier 3a — current behaviour)
 *   agent       — customer-hosted query agent (Tier 3b)
 *
 * `config` JSON holds type-specific fields:
 *   website:   { url, depth, last_crawl_at }
 *   document:  { storage_disk, files: [{path, original_name, mime, size}] }
 *   crm_oauth: { provider, access_token, refresh_token, expires_at, scopes }
 *   database:  { type, host, port, name, user, password, schema }
 *   agent:     { agent_id, endpoint, public_key }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');
            $table->enum('type', ['website', 'document', 'crm_oauth', 'database', 'agent']);
            $table->string('name');
            $table->json('config')->nullable();
            $table->enum('status', ['pending', 'active', 'expired', 'failed', 'disabled'])
                  ->default('pending');
            $table->integer('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->nullable()->default('Yes');

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
