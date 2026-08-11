<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlogPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Super-admin CRUD for the public articles at /blog.
 *
 * The section's name in navigation ("Insights", "Resources", …) is edited
 * with the rest of the landing copy in /admin/content; this screen owns the
 * articles themselves.
 *
 * Two rules encoded here are worth knowing before editing:
 *
 *  1. A published post's slug is FROZEN. The slug is a live, possibly-indexed
 *     URL, and silently changing it throws away whatever ranking and inbound
 *     links it had. Renaming would need a 301 table we don't have, so the
 *     honest behaviour is to refuse and say so.
 *
 *  2. Publishing stamps `published_at` once and never rewrites it. That date
 *     is Article.datePublished; bumping it on every edit would tell Google
 *     the piece is brand new each time, which is both false and a pattern
 *     it discounts.
 */
class BlogController extends Controller
{
    private function rules(?int $id = null): array
    {
        return [
            'title'            => 'required|string|max:191',
            'slug'             => 'nullable|string|max:191|regex:/^[a-z0-9\-]+$/',
            'subtitle'         => 'nullable|string|max:255',
            'excerpt'          => 'nullable|string|max:600',
            'body'             => 'required|string|max:200000',
            'category'         => 'nullable|string|max:80',
            'tags_csv'         => 'nullable|string|max:400',
            'cover_alt'        => 'nullable|string|max:191',
            // Not `url`: an uploaded cover is a site-relative /storage/ path,
            // which the url rule rejects. Shape is checked in coverFrom().
            'cover_url'        => 'nullable|string|max:500',
            'cover'            => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:4096',
            'meta_title'       => 'nullable|string|max:191',
            'meta_description' => 'nullable|string|max:320',
            'meta_keywords'    => 'nullable|string|max:500',
            'canonical_url'    => 'nullable|url|max:500',
            'author_name'      => 'nullable|string|max:120',
            'author_role'      => 'nullable|string|max:120',
            'reading_minutes'  => 'nullable|integer|min:1|max:180',
            'status'           => 'required|in:draft,published',
            'published_at'     => 'nullable|date',
        ];
    }

    /**
     * Resolve the cover for a save: an uploaded file wins, else the URL field
     * verbatim. Clearing both removes the image — that's the "remove"
     * affordance, same as testimonials.
     */
    private function coverFrom(Request $request): ?string
    {
        if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
            $file = $request->file('cover');
            $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            // Slug-prefixed filename: descriptive rather than a random hash,
            // which is what Google's image guidance asks for.
            $name = Str::slug($request->input('title', 'post')) . '-'
                  . substr(md5($file->getClientOriginalName() . microtime(true)), 0, 8) . '.' . $ext;

            return Storage::url($file->storeAs('site/blog', $name, 'public'));
        }

        $url = trim((string) $request->input('cover_url', ''));
        if ($url === '') {
            return null;
        }

        // Absolute http(s) or site-relative only — never javascript: or
        // data:, which would render straight into an <img src> on a public page.
        if (! preg_match('#^(https?://|/)#i', $url)) {
            return null;
        }

        return $url;
    }

    /** "ai, whatsapp, pricing" → ['ai', 'whatsapp', 'pricing'] */
    private function tagsFrom(Request $request): ?array
    {
        $tags = collect(explode(',', (string) $request->input('tags_csv', '')))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->unique()
            ->take(12)          // a post with 30 tags has no topic
            ->values()
            ->all();

        return $tags ?: null;
    }

    public function index(Request $request): View
    {
        $title = 'Articles';

        $posts = BlogPost::query()
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 ELSE 1 END")   // drafts need attention first
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        $stats = [
            'total'     => $posts->count(),
            'published' => $posts->filter->isPublished()->count(),
            'drafts'    => $posts->where('status', BlogPost::STATUS_DRAFT)->count(),
            'scheduled' => $posts->filter->isScheduled()->count(),
            'views'     => (int) $posts->sum('views'),
        ];

        return view('ops.blog.index', compact('title', 'posts', 'stats'));
    }

    /**
     * The editor gets its own page rather than a modal: long-form writing
     * needs the room, and one rich-text instance per page beats one per row.
     */
    public function create(Request $request): View
    {
        return view('ops.blog.form', [
            'title'      => 'New article',
            'post'       => new BlogPost(['status' => BlogPost::STATUS_DRAFT]),
            'categories' => $this->knownCategories(),
        ]);
    }

    public function edit(Request $request, int $id): View
    {
        $post = BlogPost::findOrFail($id);

        return view('ops.blog.form', [
            'title'      => 'Edit article',
            'post'       => $post,
            'categories' => $this->knownCategories(),
        ]);
    }

    /** Categories already in use, offered as suggestions in the form. */
    private function knownCategories(): array
    {
        return BlogPost::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $post = new BlogPost();
        $post->slug = BlogPost::uniqueSlug($data['slug'] ?: $data['title']);
        $this->fill($post, $data, $request);
        $post->created_by = auth()->id();

        // Publishing without a date means "now".
        if ($post->status === BlogPost::STATUS_PUBLISHED && ! $post->published_at) {
            $post->published_at = now();
        }

        $post->save();

        AuditLog::record('blog.create', [
            'target_type' => 'blog_post',
            'target_id'   => $post->id,
            'payload'     => ['slug' => $post->slug, 'status' => $post->status],
        ]);

        return redirect()
            ->route('ops.blog.index')
            ->with('success', $post->isPublished()
                ? "\"{$post->title}\" is live at /blog/{$post->slug}."
                : "\"{$post->title}\" saved as a " . ($post->isScheduled() ? 'scheduled post.' : 'draft.'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $post = BlogPost::findOrFail($id);
        $data = $request->validate($this->rules($id));

        $wasPublished = $post->isPublished();
        $notices      = [];

        // Slug changes: allowed while unpublished, refused afterwards.
        $requested = trim((string) ($data['slug'] ?? ''));
        if ($requested !== '' && $requested !== $post->slug) {
            if ($wasPublished) {
                $notices[] = 'The URL was left unchanged — a published article keeps its slug so its links and ranking survive.';
            } else {
                $post->slug = BlogPost::uniqueSlug($requested, $post->id);
            }
        }

        $this->fill($post, $data, $request);

        // First publish stamps the date; later edits never rewrite it.
        if ($post->status === BlogPost::STATUS_PUBLISHED && ! $post->published_at) {
            $post->published_at = now();
        }

        $post->save();

        AuditLog::record('blog.update', [
            'target_type' => 'blog_post',
            'target_id'   => $post->id,
            'payload'     => ['slug' => $post->slug, 'status' => $post->status],
        ]);

        return back()
            ->with('success', "\"{$post->title}\" updated." . ($notices ? ' ' . implode(' ', $notices) : ''));
    }

    /** Publish ⇄ unpublish without touching the content. */
    public function toggle(Request $request, int $id): RedirectResponse
    {
        $post = BlogPost::findOrFail($id);

        if ($post->status === BlogPost::STATUS_PUBLISHED) {
            $post->status = BlogPost::STATUS_DRAFT;
            $message = "\"{$post->title}\" unpublished — it's out of /blog and the sitemap.";
        } else {
            $post->status = BlogPost::STATUS_PUBLISHED;
            $post->published_at = $post->published_at ?: now();
            $message = "\"{$post->title}\" published at /blog/{$post->slug}.";
        }

        $post->save();

        AuditLog::record('blog.toggle', [
            'target_type' => 'blog_post',
            'target_id'   => $post->id,
            'payload'     => ['status' => $post->status],
        ]);

        return back()->with('success', $message);
    }

    /** Feature / unfeature — the hero slot on /blog. */
    public function feature(Request $request, int $id): RedirectResponse
    {
        $post = BlogPost::findOrFail($id);

        // One hero at a time, or the index would show several "lead" stories.
        if (! $post->is_featured) {
            BlogPost::where('is_featured', true)->update(['is_featured' => false]);
        }

        $post->is_featured = ! $post->is_featured;
        $post->save();

        return back()->with('success', $post->is_featured
            ? "\"{$post->title}\" is now the featured article."
            : "\"{$post->title}\" is no longer featured.");
    }

    /**
     * Soft-delete. The row is retained so an accidental delete of a ranking
     * article is recoverable, and so the slug stays reserved — reusing it for
     * different content is a confusing thing to serve a returning visitor.
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $post  = BlogPost::findOrFail($id);
        $title = $post->title;

        $post->delete();

        AuditLog::record('blog.delete', [
            'target_type' => 'blog_post',
            'target_id'   => $id,
            'payload'     => ['slug' => $post->slug, 'title' => $title],
        ]);

        return back()->with('success', "\"{$title}\" deleted — it's gone from /blog and the sitemap.");
    }

    /** Everything except slug/published_at, which have their own rules. */
    private function fill(BlogPost $post, array $data, Request $request): void
    {
        $post->title            = $data['title'];
        $post->subtitle         = $data['subtitle'] ?? null;
        $post->excerpt          = $data['excerpt'] ?? null;
        $post->body             = $data['body'];
        $post->category         = $data['category'] ?? null;
        $post->tags             = $this->tagsFrom($request);
        $post->cover_url        = $this->coverFrom($request);
        $post->cover_alt        = $data['cover_alt'] ?? null;
        $post->meta_title       = $data['meta_title'] ?? null;
        $post->meta_description = $data['meta_description'] ?? null;
        $post->meta_keywords    = $data['meta_keywords'] ?? null;
        $post->canonical_url    = $data['canonical_url'] ?? null;
        $post->noindex          = $request->boolean('noindex');
        $post->author_name      = $data['author_name'] ?? null;
        $post->author_role      = $data['author_role'] ?? null;
        $post->reading_minutes  = $data['reading_minutes'] ?? null;
        $post->status           = $data['status'];

        // An explicit date wins (that's how scheduling is set); otherwise
        // leave whatever is already there alone.
        if (! empty($data['published_at'])) {
            $post->published_at = $data['published_at'];
        }
    }
}
