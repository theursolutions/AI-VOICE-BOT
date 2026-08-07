<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-wide marketing-site settings (master DB, not tenant-scoped).
     * A simple typed key→value store driving the public website:
     *   group  : 'seo' | 'content'  (key prefix, for filtered reads)
     *   key    : dotted, e.g. 'seo.meta_title', 'content.hero_title'
     *   value  : JSON-encoded (strings, bools, and arrays all round-trip)
     *
     * Managed from the super-admin console (/admin/seo, /admin/content).
     * Defaults live in config/site.php; a row here overrides its default.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->string('group', 32)->default('general')->index();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
