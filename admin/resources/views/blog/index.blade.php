@php
    use App\Support\Seo;
    $brand = tva_setting('content.brand_name', 'Serve AI');

    // Category filters are real, crawlable URLs, but they are slices of the
    // same set of articles — so page 2+ and every filtered view is noindex,
    // follow. The links still pass authority to the articles; the thin
    // near-duplicate listings just stay out of the index.
    $isSlice = $category || $posts->currentPage() > 1;

    // Section name is configurable (Insights / Resources / Research / …);
    // the URL stays /blog either way.
    $label   = tva_setting('content.blog_label', 'Insights');
    $tagline = tva_setting('content.blog_tagline', 'Practical writing on AI receptionists, WhatsApp automation and turning conversations into customers.');

    $pageHeading = $category
        ? $category
        : $brand . ' <span class="accent">' . e($label) . '</span>';
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => $label,
    'pageTitle'       => $pageHeading,
    'pageSubtitle'    => $category
        ? 'Articles on ' . $category . ' from the ' . $brand . ' team.'
        : $tagline,
    'seoTitle'        => $category
        ? $category . ' — ' . $label . ' | ' . $brand
        : $label . ' — AI receptionists, WhatsApp automation & customer conversations | ' . $brand,
    'metaDescription' => $category
        ? 'Articles about ' . $category . ' from ' . $brand . ' — practical guidance for businesses using AI to answer calls, chats and messages.'
        : 'Practical guides on AI receptionists, AI voice agents, WhatsApp Business automation and lead capture — written for businesses that lose customers to unanswered calls.',
    'canonicalPath'   => '/blog',
    'pageNoindex'     => $isSlice,
    'breadcrumbs'     => $category
        ? [['name' => $label, 'url' => '/blog'], ['name' => $category, 'url' => '/blog?category=' . urlencode($category)]]
        : [['name' => $label, 'url' => '/blog']],
    'pageSchemaType'  => 'CollectionPage',
])

@push('head')
<style>
    /* ── Category filter rail ── */
    .blog-filters { display:flex; flex-wrap:wrap; gap:9px; justify-content:center; margin: 0 0 34px; }
    .blog-filter {
        font-size:12.5px; font-weight:600; padding:7px 15px; border-radius:999px;
        border:1px solid var(--line); color:var(--text-dim); background:rgba(0,0,0,.25);
        transition:border-color .15s, color .15s, background .15s;
    }
    .blog-filter:hover { color:var(--text); border-color:var(--line-hot); }
    .blog-filter.is-active { background:var(--neon-btn); border-color:var(--neon-btn); color:#fff; }

    /* ── Featured hero card ── */
    .blog-hero {
        display:grid; grid-template-columns: 1.15fr 1fr; gap:0;
        border:1px solid var(--line); border-radius:20px; overflow:hidden;
        background:var(--panel); backdrop-filter:blur(8px); margin-bottom:34px;
        transition:border-color .18s, transform .18s;
    }
    .blog-hero:hover { border-color:var(--line-hot); transform:translateY(-2px); }
    @media (max-width:820px) { .blog-hero { grid-template-columns:1fr; } }
    .blog-hero__media { position:relative; min-height:280px; background:#0a0d14; }
    .blog-hero__media img { width:100%; height:100%; object-fit:cover; display:block; }
    .blog-hero__body { padding:34px clamp(22px,3vw,38px); display:flex; flex-direction:column; justify-content:center; }
    .blog-hero__body h2 { font-size:clamp(22px,2.6vw,30px); font-weight:800; letter-spacing:-.015em; line-height:1.2; margin:12px 0 12px; }
    .blog-hero__body p { color:var(--text-dim); font-size:15px; margin:0 0 20px; }

    /* ── Post grid ── */
    .blog-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:22px; }
    @media (max-width:1000px) { .blog-grid { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    @media (max-width:640px)  { .blog-grid { grid-template-columns:1fr; } }

    .blog-card {
        display:flex; flex-direction:column; border:1px solid var(--line); border-radius:16px;
        overflow:hidden; background:var(--panel); backdrop-filter:blur(8px);
        transition:border-color .18s, transform .18s, box-shadow .18s;
    }
    .blog-card:hover { border-color:var(--line-hot); transform:translateY(-3px); box-shadow:0 18px 40px -22px rgba(0,0,0,.8); }
    /* Fixed aspect ratio so a mixed bag of image sizes still lays out on a
       clean grid — and so nothing shifts as images arrive (CLS). */
    .blog-card__media { aspect-ratio:16/9; background:#0a0d14; overflow:hidden; }
    .blog-card__media img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .4s ease; }
    .blog-card:hover .blog-card__media img { transform:scale(1.04); }
    .blog-card__body { padding:19px 20px 22px; display:flex; flex-direction:column; flex:1; }
    .blog-card__title { font-size:17px; font-weight:700; line-height:1.32; margin:10px 0 9px; letter-spacing:-.01em; }
    .blog-card__excerpt { font-size:13.8px; color:var(--text-dim); margin:0 0 16px; flex:1; }
    .blog-card__foot { display:flex; align-items:center; gap:9px; font-size:11.5px; color:var(--text-dim2); padding-top:13px; border-top:1px solid var(--line); }

    /* ── Shared bits ── */
    .blog-tag {
        display:inline-flex; align-items:center; font-size:10.5px; font-weight:700;
        letter-spacing:.09em; text-transform:uppercase; color:var(--neon-2);
        background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.25);
        border-radius:999px; padding:4px 11px;
    }
    /* Placeholder for a post with no cover: a deterministic gradient derived
       from the slug, so it's stable per-post and still looks designed rather
       than like a broken image. */
    .blog-ph { width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
    .blog-ph span { font-size:34px; font-weight:900; color:rgba(255,255,255,.22); letter-spacing:-.03em; }

    /* ── Pagination ── */
    .blog-pager { display:flex; align-items:center; justify-content:center; gap:8px; margin-top:38px; flex-wrap:wrap; }
    .blog-pager a, .blog-pager span {
        min-width:38px; height:38px; padding:0 12px; display:inline-flex; align-items:center; justify-content:center;
        border:1px solid var(--line); border-radius:10px; font-size:13.5px; color:var(--text-dim);
    }
    .blog-pager a:hover { border-color:var(--line-hot); color:var(--text); }
    .blog-pager .is-current { background:var(--neon-btn); border-color:var(--neon-btn); color:#fff; font-weight:700; }
    .blog-pager .is-disabled { opacity:.35; }

    .blog-empty { text-align:center; padding:70px 20px; color:var(--text-dim); }
</style>
@endpush

@section('content')
<section class="article">
    <div class="wrap" style="max-width:1180px;">

        @if (count($categories))
            <nav class="blog-filters" aria-label="Article categories">
                <a href="{{ url('/blog') }}" class="blog-filter {{ $category ? '' : 'is-active' }}">All</a>
                @foreach ($categories as $c)
                    <a href="{{ url('/blog?category=' . urlencode($c)) }}"
                       class="blog-filter {{ $category === $c ? 'is-active' : '' }}">{{ $c }}</a>
                @endforeach
            </nav>
        @endif

        {{-- ── Featured article ─────────────────────────────────────── --}}
        @if ($featured)
            <a href="{{ $featured->url }}" class="blog-hero">
                <div class="blog-hero__media">
                    @include('blog._cover', ['post' => $featured, 'eager' => true, 'sizes' => '(max-width:820px) 100vw, 55vw'])
                </div>
                <div class="blog-hero__body">
                    @if ($featured->category)<span class="blog-tag">{{ $featured->category }}</span>@endif
                    <h2>{{ $featured->title }}</h2>
                    <p>{{ $featured->summary }}</p>
                    <div style="display:flex; align-items:center; gap:9px; font-size:12px; color:var(--text-dim2);">
                        <time datetime="{{ $featured->published_at->toDateString() }}">{{ $featured->published_at->format('M j, Y') }}</time>
                        <span aria-hidden="true">·</span>
                        <span>{{ $featured->reading_time }} min read</span>
                    </div>
                </div>
            </a>
        @endif

        {{-- ── Grid ─────────────────────────────────────────────────── --}}
        @if ($posts->count())
            <div class="blog-grid">
                @foreach ($posts as $post)
                    <article class="blog-card">
                        <a href="{{ $post->url }}" class="blog-card__media" aria-label="{{ $post->title }}">
                            @include('blog._cover', ['post' => $post, 'eager' => false, 'sizes' => '(max-width:640px) 100vw, (max-width:1000px) 50vw, 33vw'])
                        </a>
                        <div class="blog-card__body">
                            @if ($post->category)<span class="blog-tag" style="align-self:flex-start;">{{ $post->category }}</span>@endif
                            <h2 class="blog-card__title"><a href="{{ $post->url }}">{{ $post->title }}</a></h2>
                            <p class="blog-card__excerpt">{{ Str::limit($post->summary, 130) }}</p>
                            <div class="blog-card__foot">
                                <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('M j, Y') }}</time>
                                <span aria-hidden="true">·</span>
                                <span>{{ $post->reading_time }} min read</span>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($posts->hasPages())
                <nav class="blog-pager" aria-label="Pagination">
                    @if ($posts->onFirstPage())
                        <span class="is-disabled" aria-hidden="true">← Newer</span>
                    @else
                        <a href="{{ $posts->previousPageUrl() }}" rel="prev">← Newer</a>
                    @endif

                    @foreach ($posts->getUrlRange(max(1, $posts->currentPage() - 2), min($posts->lastPage(), $posts->currentPage() + 2)) as $page => $url)
                        @if ($page == $posts->currentPage())
                            <span class="is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach

                    @if ($posts->hasMorePages())
                        <a href="{{ $posts->nextPageUrl() }}" rel="next">Older →</a>
                    @else
                        <span class="is-disabled" aria-hidden="true">Older →</span>
                    @endif
                </nav>
            @endif
        @elseif (! $featured)
            <div class="blog-empty">
                <div style="font-size:38px; margin-bottom:12px;" aria-hidden="true">📝</div>
                <p style="font-size:16px; color:var(--text);">Nothing published here yet.</p>
                <p style="font-size:14px;">Our first articles are on the way — check back soon.</p>
            </div>
        @endif
    </div>
</section>

{{-- Blog → product. Every article page inherits this route back to the
     commercial pages, which is the point of publishing them. --}}
<section class="article" style="padding-top:0;">
    <div class="wrap">
        <div class="page-cta">
            <h2>Stop losing customers to unanswered calls</h2>
            <p>{{ $brand }} answers every call, chat and message — 24/7, in your own voice.</p>
            <a href="{{ url('/register') }}" class="btn">Start free — no card required →</a>
        </div>
    </div>
</section>
@endsection
