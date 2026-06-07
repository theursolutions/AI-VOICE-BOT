<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('session_id');
            $table->integer('project_id');
            $table->json('fields')->nullable();
            $table->float('confidence')->nullable();
            $table->enum('status', ['new', 'qualified', 'converted', 'rejected'])->default('new');
            $table->integer('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->nullable()->default('Yes');

            $table->index(['project_id', 'status']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
