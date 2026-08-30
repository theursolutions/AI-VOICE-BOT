<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A professional mailbox a project sends/receives through. Lives in the
 * central DB, like channel_connections — project_id is a bare column
 * (no belongsTo) so this package stays decoupled from the host app's models.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');

            $table->string('label', 191)->nullable();
            $table->string('from_name', 191)->nullable();
            $table->string('from_email', 191);

            $table->string('imap_host', 191);
            $table->unsignedInteger('imap_port')->default(993);
            $table->string('imap_encryption', 8)->default('ssl');   // ssl|tls|none
            $table->string('imap_username', 191);
            $table->text('imap_password')->nullable();              // encrypted

            $table->string('smtp_host', 191);
            $table->unsignedInteger('smtp_port')->default(587);
            $table->string('smtp_encryption', 8)->default('tls');   // ssl|tls|none
            $table->string('smtp_username', 191);
            $table->text('smtp_password')->nullable();              // encrypted

            // Reserved for a later Gmail/Outlook OAuth pass — unused for now,
            // present so that addition needs no schema change.
            $table->string('oauth_provider', 32)->nullable();
            $table->text('oauth_token')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->timestamp('oauth_expires_at')->nullable();

            $table->string('status', 16)->default('enabled');       // enabled|disabled

            $table->string('last_uid', 64)->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'from_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
