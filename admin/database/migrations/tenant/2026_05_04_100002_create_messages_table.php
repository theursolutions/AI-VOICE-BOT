<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('session_id');
            $table->integer('project_id');
            $table->enum('role', ['user', 'assistant', 'system', 'tool']);
            $table->longText('content')->nullable();
            $table->string('audio_url', 512)->nullable();
            $table->integer('tokens_in')->nullable();
            $table->integer('tokens_out')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->string('model_used', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();

            $table->index(['session_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
