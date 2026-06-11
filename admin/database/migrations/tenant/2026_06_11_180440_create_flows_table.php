<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-project conversation flow definitions.
     *
     *   definition JSON shape:
     *   {
     *     "nodes": [ { "id": "n1", "type": "start", "data": {...}, "position": {"x":0,"y":0} }, ... ],
     *     "edges": [ { "id": "e1-2", "source": "n1", "target": "n2", "sourceHandle": "1" }, ... ],
     *     "settings": { "language": "en", "timeout_secs": 8, "max_retries": 2 }
     *   }
     *
     * Stored as JSON rather than relational tables — a flow loads/saves
     * as a single document, and there's no use case for querying inside
     * the shape (only the visual editor reads it).
     *
     * `assets` table (added in a sibling migration) holds the uploaded
     * audio files referenced by Say-nodes via asset_id.
     *
     * Soft deletes via the `deleted_at` integer column that
     * IntSoftDeletes expects — same pattern as sessions/leads/voices.
     */
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('project_id')->index();
            $table->string('name', 160);
            $table->string('slug', 160)->nullable();
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft')->index();
            $table->json('definition')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('language', 16)->default('en');
            // Lets the editor save a description / notes alongside the
            // flow without bloating the definition JSON.
            $table->text('description')->nullable();
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('update_at')->nullable();
            $table->unsignedInteger('deleted_at')->nullable()->index();
        });

        Schema::create('flow_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('project_id')->index();
            $table->unsignedBigInteger('flow_id')->nullable()->index();
            $table->string('label', 120);          // human label shown in the editor
            $table->string('language', 16)->default('en');
            $table->string('mime', 80);            // audio/wav, audio/mpeg…
            $table->string('storage_path', 255);   // relative path on the public disk
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('update_at')->nullable();
            $table->unsignedInteger('deleted_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_assets');
        Schema::dropIfExists('flows');
    }
};
