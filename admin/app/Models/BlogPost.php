<?php

namespace App\Models;

use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A blog post on the public marketing site.
 *
 * Managed by super-admins at /admin/blog; rendered at /blog and
 * /blog/{slug}. Lives in the central DB alongside the other marketing
 * content.
 *
 * The SEO-relevant behaviour is concentrated here rather than in the
 * controller or the views, so the public page, the sitemap and the
 * structured data cannot disagree about whether a post is indexable.
 */
class BlogPost extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';
    protected $table = 'blog_posts';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'slug', 'title', 'subtitle', 'excerpt', 'body',
        'category', 'tags', 'cover_url', 'cover_alt',
        'meta_title', 'meta_description', 'meta_keywords', 'canonical_url', 'noindex',
        'author_name', 'author_role', 'created_by',
        'status', 'published_at', 'is_featured', 'reading_minutes',
    ];

    protected $casts = [
        'tags'            => 'array',
        'noindex'         => 'boolean',
        'is_featured'     => 'boolean',
        'published_at'    => 'datetime',
        'reading_minutes' => 'integer',
        'views'           => 'integer',
    ];

    // ── Scopes ───────────────────────────────────────────────────────

    /**
     * Publicly visible: published AND its date has arrived. The second half
     * is what makes scheduling work — set a future published_at and the post
     * appears on its own.
     */
    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** Eligible for the index, the sitemap and internal linking. */
    public function scopeIndexable(Builder $q): Builder
    {
        return $q->published()->where('noindex', false);
    }

    public function scopeNewestFirst(Builder $q): Builder
    {
        return $q->orderByDesc('published_at')->orderByDesc('id');
    }

    // ── State ────────────────────────────────────────────────────────

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at
            && $this->published_at->isPast();
    }

    /** Published but dated in the future — shown as "Scheduled" in the ops UI. */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at
            && $this->published_at->isFuture();
    }

    /**
     * Should search engines index this? Drafts and scheduled posts are
     * reachable by direct link (so an author can preview and share) but must
     * never enter the index — a draft ranking is worse than no ranking.
     */
    public function isIndexable(): bool
    {
        return $this->isPublished() && ! $this->noindex;
    }

    // ── URLs + SEO ───────────────────────────────────────────────────

    public function getUrlAttribute(): string
    {
        return url('/blog/' . $this->slug);
    }

    public function getCanonicalAttribute(): string
    {
        return trim((string) $this->canonical_url) !== ''
            ? (string) $this->canonical_url
            : Seo::canonical('/blog/' . $this->slug);
    }

    /** The <title>. Falls back to the headline plus the brand. */
    public function getSeoTitleAttribute(): string
    {
        $explicit = trim((string) $this->meta_title);
        if ($explicit !== '') {
            return $explicit;
        }

        return $this->title . ' — ' . tva_setting('content.brand_name', 'Serve AI');
    }

    /**
     * The meta description. Prefers the explicit field, then the excerpt,
     * then the opening of the body — trimmed to a length that survives
     * Google's truncation.
     */
    public function getSeoDescriptionAttribute(): string
    {
        foreach ([$this->meta_description, $this->excerpt, strip_tags((string) $this->body)] as $candidate) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $candidate));
            if ($text !== '') {
                return Str::limit($text, 155, '…');
            }
        }

        return (string) tva_setting('seo.meta_description', '');
    }

    // ── Derived content ──────────────────────────────────────────────

    /**
     * Reading time. Stored when the author sets it, otherwise computed from
     * the body at ~200 words per minute (a widely used average) — never
     * zero, because "0 min read" looks broken.
     */
    public function getReadingTimeAttribute(): int
    {
        if ($this->reading_minutes) {
            return $this->reading_minutes;
        }

        $words = str_word_count(strip_tags((string) $this->body));

        return max(1, (int) ceil($words / 200));
    }

    /** Card/preview copy, falling back to the start of the body. */
    public function getSummaryAttribute(): string
    {
        $text = trim((string) $this->excerpt);
        if ($text !== '') {
            return $text;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->body))), 180, '…');
    }

    /**
     * Posts to surface at the foot of an article: same category first, then
     * anything recent. Purely internal linking — it gives every post inbound
     * links from its siblings instead of relying on the index page alone.
     */
    public function relatedPosts(int $limit = 3)
    {
        $query = static::query()->indexable()->whereKeyNot($this->getKey());

        $sameCategory = $this->category
            ? (clone $query)->where('category', $this->category)->newestFirst()->limit($limit)->get()
            : collect();

        if ($sameCategory->count() >= $limit) {
            return $sameCategory;
        }

        $filler = $query->whereNotIn('id', $sameCategory->pluck('id'))
            ->newestFirst()
            ->limit($limit - $sameCategory->count())
            ->get();

        return $sameCategory->concat($filler);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * A URL-safe, unique slug derived from a title.
     *
     * `$ignoreId` lets an edit keep its own slug. Collisions get a numeric
     * suffix rather than failing the save — an operator writing two posts
     * called "AI receptionist pricing" should not have to invent a slug.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'post';
        $slug = $base;
        $n    = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    /**
     * Every indexable post as sitemap entries. Degrades to an empty array
     * if the table isn't there yet, so a not-yet-migrated install serves a
     * valid sitemap instead of a 500 — same posture as SiteSetting.
     *
     * @return array<int,array{loc:string,lastmod:string,changefreq:string,priority:string}>
     */
    public static function sitemapEntries(): array
    {
        try {
            return static::query()->indexable()->newestFirst()->get()
                ->map(fn (self $p) => [
                    'loc'        => '/blog/' . $p->slug,
                    // updated_at, not published_at: lastmod means "when did
                    // this content last change", and a corrected post should
                    // invite a re-crawl.
                    'lastmod'    => ($p->updated_at ?? $p->published_at)->format('Y-m-d'),
                    'changefreq' => 'monthly',
                    'priority'   => $p->is_featured ? '0.8' : '0.6',
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Distinct categories that actually have an indexable post. */
    public static function activeCategories(): array
    {
        try {
            return static::query()->indexable()
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
