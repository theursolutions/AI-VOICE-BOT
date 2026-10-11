<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The super-admin notification panel: one row per platform event (a new
 * user, client, project, website contact or payment), shared by every
 * super admin. Who has read what is kept per admin in the reads table, so a
 * super admin added later still sees the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);              // user | client | project | contact | payment
            $table->string('title', 190);
            $table->string('body', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->json('details')->nullable();     // label => value, as shown in the email
            $table->timestamp('created_at')->nullable();

            $table->index(['kind', 'id']);
        });

        Schema::create('ops_notification_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('ops_notification_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at')->nullable();

            $table->primary(['user_id', 'ops_notification_id']);
            $table->foreign('ops_notification_id')->references('id')->on('ops_notifications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_notification_reads');
        Schema::dropIfExists('ops_notifications');
    }
};
