<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');
            $table->enum('channel', ['web', 'whatsapp', 'twilio', 'plivo', 'api']);
            $table->string('external_id', 191)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('customer_email')->nullable();
            $table->bigInteger('voice_id')->nullable();
            $table->enum('status', ['active', 'ended', 'failed'])->default('active');
            $table->integer('started_at')->nullable();
            $table->integer('ended_at')->nullable();
            $table->integer('last_activity_at')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->nullable()->default('Yes');

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'last_activity_at']);
            $table->index(['channel', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
