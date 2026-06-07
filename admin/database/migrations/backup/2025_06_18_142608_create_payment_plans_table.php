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
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name', 100)->nullable();
            $table->float('price', null, 0)->nullable();
            $table->string('discount_type', 20)->nullable();
            $table->string('discount', 50)->nullable();
            $table->string('currency', 20)->nullable();
            $table->text('desctiption')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->nullable()->default('Yes');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->integer('deleted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
