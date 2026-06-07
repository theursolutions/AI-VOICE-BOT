<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voices', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');
            $table->enum('provider', ['coqui', 'elevenlabs']);
            $table->string('name');
            $table->string('reference_url', 512)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('language', 10)->default('en');
            $table->enum('status', ['training', 'ready', 'failed'])->default('training');
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->nullable()->default('Yes');

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voices');
    }
};
