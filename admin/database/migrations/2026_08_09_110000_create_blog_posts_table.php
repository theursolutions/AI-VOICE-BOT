<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog posts — the marketing site's compounding SEO layer.
 *
 * Sits in the central DB with the rest of the marketing content
 * (site_settings, testimonials) and is managed by super-admins at
 * /admin/blog. Rendered publicly at /blog and /blog/{slug}.
 *
 * Design notes worth keeping in mind when editing this:
 *
 *  • `slug` is unique and is the URL. Changing it changes a live URL, so the
 *    model refuses to re-slug a post that has already been published —
 *    silently breaking an indexed URL is how blogs lose their rankings.
 *
 *  • `meta_title` / `meta_description` are separate from `title` / `excerpt`
 *    on purpose. A good headline and a good search-result title are often
 *    different lengths and different sentences; forcing them to be the same
 *    string guarantees one of them is wrong.
 *
 *  • `published_at` is the SEO-visible date (Article.datePublished) and is
 *    also the gate: a post is public only when status = published AND
 *    published_at has passed. That gives scheduling for free.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();

            // ── URL + content ────────────────────────────────────────
            $table->string('slug', 191)->unique();
            $table->string('title', 191);
            $table->string('subtitle', 255)->nullable();
            // Card copy on /blog, and the fallback meta description.
            $table->text('excerpt')->nullable();
            $table->longText('body');                     // Markdown-ish HTML

            // ── Taxonomy ─────────────────────────────────────────────
            $table->string('category', 80)->nullable();
            $table->json('tags')->nullable();

            // ── Media ────────────────────────────────────────────────
            $table->string('cover_url', 500)->nullable();
            // Never left to chance: a decorative cover needs alt="" and a
            // meaningful one needs real text, and only the author knows which.
            $table->string('cover_alt', 191)->nullable();

            // ── SEO ──────────────────────────────────────────────────
            $table->string('meta_title', 191)->nullable();
            $table->string('meta_description', 320)->nullable();
            // Target keywords for this article.
            //
            // Be clear-eyed about what this is for: Google has ignored
            // <meta name="keywords"> since 2009 and it will not affect
            // ranking. It is kept because (a) the site already emits a
            // site-wide keywords tag, so per-post values keep that
            // consistent, and (b) it is genuinely useful as a written record
            // of the search intent a post was aimed at, which is what you
            // check it against later in Search Console.
            $table->string('meta_keywords', 500)->nullable();
            // Set when a post supersedes another, or is syndicated from
            // elsewhere. Empty = self-referencing canonical (the normal case).
            $table->string('canonical_url', 500)->nullable();
            // Lets an author keep a thin or seasonal post reachable by link
            // without it competing in search.
            $table->boolean('noindex')->default(false);

            // ── Authorship (shown, and used for Article.author) ───────
            $table->string('author_name', 120)->nullable();
            $table->string('author_role', 120)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            // ── Publication ──────────────────────────────────────────
            $table->string('status', 16)->default('draft');   // draft | published
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('reading_minutes')->nullable();
            $table->unsignedBigInteger('views')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The public index query is exactly this: published, dated, newest
            // first.
            $table->index(['status', 'published_at']);
            $table->index('category');
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
