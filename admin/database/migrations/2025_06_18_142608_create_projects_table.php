<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name');
            $table->integer('client_id');
            $table->string('url')->nullable();
            $table->string('description')->nullable();
            $table->string('niche', 100)->nullable();
            $table->string('project_api_key')->nullable();
            $table->string('project_api_secret')->nullable();
            $table->string('db_type', 20)->nullable();
            $table->string('db_host', 50)->nullable();
            $table->string('db_port', 10)->nullable();
            $table->string('db_name', 100)->nullable();
            $table->string('db_user', 100)->nullable();
            $table->string('db_password', 100)->nullable();
            $table->json('json_data')->nullable();
            $table->json('db_schema')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->nullable()->default('Yes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
