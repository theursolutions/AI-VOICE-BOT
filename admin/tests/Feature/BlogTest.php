<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The blog exists to rank, so these tests guard the properties that decide
 * whether it can: one canonical URL per article, correct indexability,
 * valid Article markup, and drafts that never leak into search.
 */
class BlogTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'slug'         => 'test-article',
            'title'        => 'Test article',
            'excerpt'      => 'A short summary of the test article.',
            'body'         => '<h2>Section</h2><p>' . str_repeat('word ', 400) . '</p>',
            'category'     => 'Guides',
            'status'       => BlogPost::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ], $overrides));
    }

    // ── Publication state ────────────────────────────────────────────

    public function test_a_published_post_is_public_and_indexable(): void
    {
        $post = $this->makePost();

        $this->get('/blog/' . $post->slug)->assertOk()->assertSee('Test article');
        $this->get('/blog')->assertOk()->assertSee('Test article');

        $this->assertTrue($post->isPublished());
        $this->assertTrue($post->isIndexable());
    }

    public function test_a_draft_is_404_for_the_public_and_absent_from_the_index(): void
    {
        $post = $this->makePost(['slug' => 'draft-post', 'title' => 'Draft post', 'status' => BlogPost::STATUS_DRAFT]);

        $this->get('/blog/draft-post')->assertNotFound();
        $this->get('/blog')->assertOk()->assertDontSee('Draft post');
        $this->assertFalse($post->isIndexable());
    }

    public function test_a_future_dated_post_stays_hidden_until_its_date(): void
    {
        $post = $this->makePost([
            'slug'         => 'scheduled-post',
            'title'        => 'Scheduled post',
            'published_at' => now()->addWeek(),
        ]);

        $this->assertTrue($post->isScheduled());
        $this->assertFalse($post->isPublished());
        $this->get('/blog/scheduled-post')->assertNotFound();
        $this->get('/blog')->assertDontSee('Scheduled post');
    }

    // ── Search-engine contract ───────────────────────────────────────

    public function test_an_article_declares_a_self_referencing_canonical_and_is_indexable(): void
    {
        $post = $this->makePost();

        $res = $this->get('/blog/' . $post->slug)->assertOk();

        $res->assertSee('<link rel="canonical" href="' . Seo::canonical('/blog/' . $post->slug) . '">', false);
        $res->assertSee('name="robots" content="index, follow', false);
        $res->assertSee('property="og:type" content="article"', false);
    }

    public function test_tracking_parameters_do_not_change_an_articles_canonical(): void
    {
        $post = $this->makePost();
        $expected = '<link rel="canonical" href="' . Seo::canonical('/blog/' . $post->slug) . '">';

        $this->get('/blog/' . $post->slug)->assertSee($expected, false);
        $this->get('/blog/' . $post->slug . '?utm_source=x&fbclid=y')->assertSee($expected, false);
    }

    public function test_noindex_flag_keeps_a_post_out_of_the_index_and_the_sitemap(): void
    {
        $post = $this->makePost(['slug' => 'hidden-post', 'title' => 'Hidden post', 'noindex' => true]);

        // Still readable by a visitor who has the link…
        $this->get('/blog/hidden-post')->assertOk()
            ->assertSee('name="robots" content="noindex, follow"', false);

        // …but invisible to search.
        $this->assertFalse($post->isIndexable());
        $this->assertStringNotContainsString('/blog/hidden-post', $this->get('/sitemap.xml')->getContent());
    }

    public function test_filtered_and_paginated_listings_are_noindex(): void
    {
        $this->makePost();

        // The unfiltered first page is the real listing.
        $this->get('/blog')->assertSee('name="robots" content="index, follow', false);

        // A category slice is a thin near-duplicate of it.
        $this->get('/blog?category=Guides')->assertSee('name="robots" content="noindex, follow"', false);
    }

    public function test_published_posts_appear_in_the_sitemap_with_a_real_lastmod(): void
    {
        $post = $this->makePost();

        $body = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>' . Seo::canonical('/blog/' . $post->slug) . '</loc>', $body);
        $this->assertStringContainsString('<loc>' . Seo::canonical('/blog') . '</loc>', $body);
        // lastmod tracks the content, not the date of the request.
        $this->assertStringContainsString($post->updated_at->format('Y-m-d'), $body);
    }

    public function test_drafts_never_reach_the_sitemap(): void
    {
        $this->makePost(['slug' => 'draft-in-sitemap', 'status' => BlogPost::STATUS_DRAFT]);

        $this->assertStringNotContainsString(
            '/blog/draft-in-sitemap',
            $this->get('/sitemap.xml')->getContent(),
        );
    }

    public function test_the_article_emits_valid_blogposting_structured_data(): void
    {
        $post = $this->makePost(['author_name' => 'Umer', 'author_role' => 'Founder']);

        $html = $this->get('/blog/' . $post->slug)->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $ld = json_decode($m[1] ?? '', true);
        $this->assertIsArray($ld, 'JSON-LD is not valid JSON: ' . json_last_error_msg());

        $graph = collect($ld['@graph'] ?? []);
        $this->assertContains('BlogPosting', $graph->pluck('@type')->all());
        $this->assertContains('BreadcrumbList', $graph->pluck('@type')->all());

        $article = $graph->firstWhere('@type', 'BlogPosting');
        $this->assertSame($post->title, $article['headline']);
        $this->assertNotEmpty($article['datePublished']);
        $this->assertNotEmpty($article['dateModified']);
        $this->assertSame('Umer', $article['author']['name']);
        $this->assertSame('Guides', $article['articleSection']);
    }

    public function test_each_article_gets_a_unique_title_and_description(): void
    {
        $a = $this->makePost(['slug' => 'first',  'title' => 'First article',  'excerpt' => 'Summary of the first.']);
        $b = $this->makePost(['slug' => 'second', 'title' => 'Second article', 'excerpt' => 'Summary of the second.']);

        $titles = [];
        foreach ([$a, $b] as $p) {
            preg_match('#<title>(.*?)</title>#s', $this->get('/blog/' . $p->slug)->getContent(), $t);
            $titles[] = $t[1];
            // A heading is not a title tag: no escaped markup may leak in.
            $this->assertStringNotContainsString('&lt;', $t[1]);
        }

        $this->assertCount(2, array_unique($titles));
    }

    // ── Model behaviour ──────────────────────────────────────────────

    public function test_slugs_are_unique_even_for_identical_titles(): void
    {
        $this->makePost(['slug' => BlogPost::uniqueSlug('AI receptionist pricing')]);
        $second = BlogPost::uniqueSlug('AI receptionist pricing');

        $this->assertSame('ai-receptionist-pricing-2', $second);
    }

    public function test_reading_time_is_derived_from_the_body_and_never_zero(): void
    {
        $this->assertSame(2, $this->makePost()->reading_time);                       // ~400 words
        $this->assertSame(1, $this->makePost(['slug' => 'tiny', 'body' => '<p>Hi.</p>'])->reading_time);
        $this->assertSame(9, $this->makePost(['slug' => 'fixed', 'reading_minutes' => 9])->reading_time);
    }

    public function test_meta_description_falls_back_through_excerpt_then_body(): void
    {
        $explicit = $this->makePost(['slug' => 'a', 'meta_description' => 'Explicit description.']);
        $this->assertSame('Explicit description.', $explicit->seo_description);

        $viaExcerpt = $this->makePost(['slug' => 'b', 'meta_description' => null, 'excerpt' => 'From the excerpt.']);
        $this->assertSame('From the excerpt.', $viaExcerpt->seo_description);

        $viaBody = $this->makePost(['slug' => 'c', 'meta_description' => null, 'excerpt' => null]);
        $this->assertNotEmpty($viaBody->seo_description);
        $this->assertLessThanOrEqual(156, mb_strlen($viaBody->seo_description));
    }

    public function test_related_posts_prefer_the_same_category_and_exclude_self(): void
    {
        $subject = $this->makePost(['slug' => 'subject', 'category' => 'Guides']);
        $this->makePost(['slug' => 'sibling', 'category' => 'Guides']);
        $this->makePost(['slug' => 'other',   'category' => 'News']);
        $this->makePost(['slug' => 'a-draft', 'category' => 'Guides', 'status' => BlogPost::STATUS_DRAFT]);

        $related = $subject->relatedPosts(3);

        $this->assertFalse($related->contains('slug', 'subject'), 'A post must not relate to itself.');
        $this->assertFalse($related->contains('slug', 'a-draft'), 'Drafts must not be linked from a live page.');
        $this->assertTrue($related->contains('slug', 'sibling'));
    }

    // ── Ops console ──────────────────────────────────────────────────

    public function test_the_ops_blog_console_is_super_admin_only(): void
    {
        $this->get('/admin/blog')->assertRedirect();          // guest → login

        // 404 rather than 403 is deliberate (App\Http\Middleware\IsSuperAdmin):
        // a 403 confirms the console exists, a 404 does not.
        $user = \App\Models\User::factory()->create(['is_super_admin' => false]);
        $this->actingAs($user)->get('/admin/blog')->assertNotFound();
        $this->actingAs($user)->get('/admin/blog/create')->assertNotFound();
    }

    public function test_a_super_admin_can_see_the_console_and_the_editor(): void
    {
        $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
        $post  = $this->makePost();

        $this->actingAs($admin)->get('/admin/blog')->assertOk()->assertSee('Test article');
        $this->actingAs($admin)->get('/admin/blog/create')->assertOk();
        $this->actingAs($admin)->get('/admin/blog/' . $post->id . '/edit')->assertOk()->assertSee('Test article');
    }

    public function test_a_published_slug_cannot_be_changed(): void
    {
        $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
        $post  = $this->makePost(['slug' => 'locked-url']);

        $this->actingAs($admin)->post('/admin/blog/' . $post->id, [
            'title'  => 'Renamed article',
            'slug'   => 'a-brand-new-url',
            'body'   => '<p>Updated body.</p>',
            'status' => BlogPost::STATUS_PUBLISHED,
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame('locked-url', $post->slug, 'A live URL must survive an edit.');
        $this->assertSame('Renamed article', $post->title, 'The content itself still updates.');
    }

    public function test_publishing_stamps_the_date_once_and_never_rewrites_it(): void
    {
        $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
        $post  = $this->makePost(['slug' => 'dated', 'status' => BlogPost::STATUS_DRAFT, 'published_at' => null]);

        $this->actingAs($admin)->post('/admin/blog/' . $post->id . '/toggle');
        $first = $post->fresh()->published_at;
        $this->assertNotNull($first);

        // An ordinary edit later must not move datePublished.
        $this->actingAs($admin)->post('/admin/blog/' . $post->id, [
            'title'  => 'Dated',
            'body'   => '<p>Edited later.</p>',
            'status' => BlogPost::STATUS_PUBLISHED,
        ]);

        $this->assertEquals($first->timestamp, $post->fresh()->published_at->timestamp);
    }

    public function test_only_one_post_can_be_featured_at_a_time(): void
    {
        $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
        $a = $this->makePost(['slug' => 'feat-a', 'is_featured' => true]);
        $b = $this->makePost(['slug' => 'feat-b']);

        $this->actingAs($admin)->post('/admin/blog/' . $b->id . '/feature');

        $this->assertFalse($a->fresh()->is_featured, 'Two heroes would mean two "lead" stories on /blog.');
        $this->assertTrue($b->fresh()->is_featured);
    }
}
