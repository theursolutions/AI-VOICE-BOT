<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public blog: /blog and /blog/{slug}.
 *
 * Deliberately thin — the SEO decisions (what is indexable, what the
 * canonical is, what the meta description falls back to) all live on the
 * BlogPost model so the page, the sitemap and the structured data cannot
 * drift apart.
 */
class BlogController extends Controller
{
    /** Posts per page. Keeps the index light and the pagination shallow. */
    private const PER_PAGE = 9;

    public function index(Request $request): View
    {
        $category = trim((string) $request->query('category')) ?: null;

        $query = BlogPost::query()->indexable()->newestFirst();

        if ($category) {
            $query->where('category', $category);
        }

        $posts = $query->paginate(self::PER_PAGE)->withQueryString();

        // The lead article gets a wide hero card — but only on page one of the
        // unfiltered list, or the same post would headline several URLs and
        // every one of them would look like the blog's front page.
        $featured = null;
        if (! $category && $posts->currentPage() === 1) {
            $featured = BlogPost::query()->indexable()
                ->where('is_featured', true)
                ->newestFirst()
                ->first()
                ?? $posts->first();

            if ($featured) {
                // Don't print the hero twice.
                $posts->setCollection($posts->getCollection()->reject(fn ($p) => $p->id === $featured->id)->values());
            }
        }

        return view('blog.index', [
            'posts'      => $posts,
            'featured'   => $featured,
            'categories' => BlogPost::activeCategories(),
            'category'   => $category,
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $post = BlogPost::where('slug', $slug)->firstOrFail();

        // Drafts and scheduled posts stay reachable by direct link so authors
        // can preview and share them, but only for a signed-in staff user —
        // and they are noindex regardless (see the view).
        if (! $post->isPublished() && ! optional($request->user())->is_super_admin) {
            abort(404);
        }

        // Fire-and-forget view counter. Never let analytics break a page:
        // a locked row or a missing column must not 500 an article.
        try {
            BlogPost::whereKey($post->id)->update(['views' => $post->views + 1]);
        } catch (\Throwable $e) {
            // no-op
        }

        return view('blog.show', [
            'post'    => $post,
            'related' => $post->relatedPosts(3),
        ]);
    }
}
