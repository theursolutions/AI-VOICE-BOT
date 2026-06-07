<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_summaries', function (Blueprint $table) {
            $table->bigInteger('session_id')->primary();
            $table->integer('project_id');
            $table->longText('summary')->nullable();
            $table->bigInteger('last_message_id')->nullable();
            $table->integer('token_count')->nullable();
            $table->integer('updated_at')->nullable();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_summaries');
    }
};
