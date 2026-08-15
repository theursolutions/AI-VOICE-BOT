@php
    use App\Support\Seo;
    $brand = tva_setting('content.brand_name', 'Serve AI');

    $author = trim((string) $post->author_name) !== '' ? $post->author_name : $brand;

    // Article structured data. Every field here is visible on the page —
    // headline, dates, author, cover — which is the rule for markup: describe
    // what the reader sees, never assert more than that.
    $articleLd = array_filter([
        '@type'            => 'BlogPosting',
        '@id'              => $post->canonical . '#article',
        'headline'         => Str::limit($post->title, 110),   // Google ignores longer
        'description'      => $post->seo_description,
        'url'              => $post->canonical,
        'datePublished'    => $post->published_at?->toIso8601String(),
        'dateModified'     => ($post->updated_at ?? $post->published_at)?->toIso8601String(),
        'inLanguage'       => 'en',
        'wordCount'        => str_word_count(strip_tags((string) $post->body)) ?: null,
        'articleSection'   => $post->category ?: null,
        'keywords'         => is_array($post->tags) && $post->tags ? implode(', ', $post->tags) : null,
        'image'            => trim((string) $post->cover_url) !== '' ? Seo::absolute($post->cover_url) : null,
        'author'           => array_filter([
            '@type'    => 'Person',
            'name'     => $author,
            'jobTitle' => $post->author_role ?: null,
        ]),
        'publisher'        => ['@id' => Seo::origin() . '/#organization'],
        'isPartOf'         => ['@id' => Seo::origin() . '/#website'],
        'mainEntityOfPage' => ['@id' => $post->canonical . '#webpage'],
    ]);
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => $post->category ?: 'Article',
    'pageTitle'       => $post->title,
    'pageSubtitle'    => $post->subtitle ?: null,
    'pageMeta'        => ($post->published_at?->format('F j, Y') ?? '') . ' · ' . $post->reading_time . ' min read',
    'seoTitle'        => $post->seo_title,
    'metaDescription' => $post->seo_description,
    'metaKeywords'    => $post->meta_keywords,
    'canonicalPath'   => '/blog/' . $post->slug,
    // A draft or scheduled post is viewable by staff via direct link, but must
    // never enter the index. Same flag covers an author-set noindex.
    'pageNoindex'     => ! $post->isIndexable(),
    'ogType'          => 'article',
    'breadcrumbs'     => array_values(array_filter([
        ['name' => tva_setting('content.blog_label', 'Insights'), 'url' => '/blog'],
        $post->category ? ['name' => $post->category, 'url' => '/blog?category=' . urlencode($post->category)] : null,
        ['name' => $post->title, 'url' => '/blog/' . $post->slug],
    ])),
    'pageSchemaType'  => 'WebPage',
    'jsonLd'          => [$articleLd],
])

@push('head')
<style>
    /* ── Cover ── */
    .post-cover {
        max-width:900px; margin:0 auto 6px; border-radius:18px; overflow:hidden;
        border:1px solid var(--line); background:#0a0d14; aspect-ratio:16/9;
    }
    .post-cover img { width:100%; height:100%; object-fit:cover; display:block; }
    .post-cover .blog-ph { width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
    .post-cover .blog-ph span { font-size:64px; font-weight:900; color:rgba(255,255,255,.2); }
    figcaption.post-caption { text-align:center; font-size:12px; color:var(--text-dim2); margin-top:9px; }

    /* ── Byline ── */
    .post-byline {
        display:flex; align-items:center; gap:12px; max-width:820px; margin:26px auto 0;
        padding:14px 18px; border:1px solid var(--line); border-radius:14px; background:var(--surface, rgba(0,0,0,.25));
    }
    .post-byline__av {
        width:42px; height:42px; border-radius:50%; flex-shrink:0;
        background:linear-gradient(135deg, var(--neon), #1e3a8a);
        display:flex; align-items:center; justify-content:center;
        font-weight:800; font-size:15px; color:#fff;
    }
    .post-byline__name { font-size:14px; font-weight:700; color:var(--text); }
    .post-byline__meta { font-size:12px; color:var(--text-dim2); }

    /* ── Article typography: the part that decides whether people read ── */
    .post-body { font-size:16.5px; line-height:1.78; color:#c9d4e0; }
    .post-body > :first-child { margin-top:0; }
    .post-body h2 {
        font-size:clamp(21px,2.4vw,26px); font-weight:800; color:var(--text);
        letter-spacing:-.012em; margin:42px 0 14px; scroll-margin-top:90px;
    }
    .post-body h3 { font-size:18.5px; font-weight:700; color:var(--text); margin:30px 0 10px; scroll-margin-top:90px; }
    .post-body p { margin:0 0 19px; }
    .post-body ul, .post-body ol { margin:0 0 20px; padding-left:24px; }
    .post-body li { margin:0 0 9px; }
    .post-body a { color:var(--neon-2); text-decoration:underline; text-underline-offset:2px; }
    .post-body a:hover { color:var(--neon); }
    .post-body strong { color:var(--text); font-weight:650; }
    .post-body blockquote {
        margin:26px 0; padding:16px 22px; border-left:3px solid var(--neon);
        background:rgba(59,130,246,.06); border-radius:0 12px 12px 0;
        font-size:16.5px; color:var(--text);
    }
    .post-body blockquote p:last-child { margin-bottom:0; }
    /* Any image inside body copy gets the same treatment as the cover:
       constrained, rounded, and never able to overflow on a phone. */
    .post-body img {
        max-width:100%; height:auto; display:block; margin:26px auto;
        border-radius:14px; border:1px solid var(--line);
    }
    .post-body figure { margin:26px 0; }
    .post-body figcaption { font-size:12.5px; color:var(--text-dim2); text-align:center; margin-top:8px; }
    .post-body pre {
        background:#080b11; border:1px solid var(--line); border-radius:12px;
        padding:16px 18px; overflow-x:auto; font-size:13.5px; margin:0 0 22px;
    }
    .post-body code { font-family:'JetBrains Mono', ui-monospace, monospace; font-size:.92em; }
    .post-body :not(pre) > code {
        background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.2);
        padding:2px 6px; border-radius:6px; color:#dbeafe;
    }
    .post-body table { width:100%; border-collapse:collapse; margin:0 0 22px; font-size:14.5px; }
    .post-body th, .post-body td { text-align:left; padding:11px 13px; border-bottom:1px solid var(--line); vertical-align:top; }
    .post-body th { color:var(--text); font-weight:650; }
    .post-body hr { border:none; border-top:1px solid var(--line); margin:34px 0; }

    /* ── Tags ── */
    .post-tags { display:flex; flex-wrap:wrap; gap:8px; margin-top:32px; padding-top:22px; border-top:1px solid var(--line); }
    .post-tag { font-size:11.5px; color:var(--text-dim); border:1px solid var(--line); border-radius:999px; padding:5px 12px; }

    /* ── Related ── */
    .rel-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:18px; }
    @media (max-width:900px) { .rel-grid { grid-template-columns:1fr; } }
    .rel-card {
        border:1px solid var(--line); border-radius:14px; overflow:hidden;
        background:var(--panel); transition:border-color .18s, transform .18s;
    }
    .rel-card:hover { border-color:var(--line-hot); transform:translateY(-2px); }
    .rel-card__media { aspect-ratio:16/9; background:#0a0d14; overflow:hidden; }
    .rel-card__media img { width:100%; height:100%; object-fit:cover; display:block; }
    .rel-card__media .blog-ph { width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
    .rel-card__media .blog-ph span { font-size:28px; font-weight:900; color:rgba(255,255,255,.22); }
    .rel-card__body { padding:15px 17px 18px; }
    .rel-card__title { font-size:15px; font-weight:700; line-height:1.35; margin:0 0 7px; }
    .rel-card__meta { font-size:11.5px; color:var(--text-dim2); }
</style>
@endpush

@section('content')

@unless ($post->isPublished())
    <div class="wrap" style="max-width:820px;">
        <div class="note" style="margin:0 0 22px; border-color:#f59e0b; background:rgba(245,158,11,.08); color:#fcd34d;">
            <b>{{ $post->isScheduled() ? 'Scheduled' : 'Draft' }} preview.</b>
            {{ $post->isScheduled()
                ? 'This goes live on ' . $post->published_at->format('F j, Y \a\t H:i') . '.'
                : 'Only staff can see this page, and it is marked noindex.' }}
        </div>
    </div>
@endunless

<section class="article" style="padding-top:6px;">
    <div class="wrap">
        <figure class="post-cover">
            @include('blog._cover', ['post' => $post, 'eager' => true, 'sizes' => '(max-width:900px) 100vw, 900px'])
        </figure>
        @if (trim((string) $post->cover_alt) !== '')
            <figcaption class="post-caption">{{ $post->cover_alt }}</figcaption>
        @endif

        <div class="post-byline">
            <div class="post-byline__av" aria-hidden="true">{{ mb_strtoupper(mb_substr($author, 0, 1)) }}</div>
            <div>
                <div class="post-byline__name">{{ $author }}</div>
                <div class="post-byline__meta">
                    {{ $post->author_role ?: 'Serve AI team' }}
                    @if ($post->published_at)
                        · <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('F j, Y') }}</time>
                    @endif
                    · {{ $post->reading_time }} min read
                </div>
            </div>
        </div>
    </div>
</section>

<section class="article" style="padding-top:22px;">
    <div class="wrap">
        <div class="prose">
            {{-- The body is authored HTML written by a super-admin — the same
                 trust level as every other field in the ops console. It is NOT
                 user-generated content, so it is rendered unescaped on purpose;
                 escaping it would print the markup instead of the article. --}}
            <div class="post-body">{!! $post->body !!}</div>

            @if (is_array($post->tags) && count($post->tags))
                <div class="post-tags">
                    @foreach ($post->tags as $tag)
                        <span class="post-tag">#{{ $tag }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>

{{-- ── Related articles: gives every post inbound links from its siblings,
     rather than depending on the index page as the only route in. ── --}}
@if ($related->count())
<section class="article" style="padding-top:8px;">
    <div class="wrap" style="max-width:1000px;">
        <h2 style="font-size:20px; font-weight:800; margin:0 0 18px;">Keep reading</h2>
        <div class="rel-grid">
            @foreach ($related as $r)
                <a href="{{ $r->url }}" class="rel-card">
                    <div class="rel-card__media">
                        @include('blog._cover', ['post' => $r, 'eager' => false, 'sizes' => '(max-width:900px) 100vw, 33vw'])
                    </div>
                    <div class="rel-card__body">
                        <div class="rel-card__title">{{ $r->title }}</div>
                        <div class="rel-card__meta">{{ $r->published_at->format('M j, Y') }} · {{ $r->reading_time }} min read</div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="article" style="padding-top:8px;">
    <div class="wrap">
        <div class="page-cta">
            <h2>Never miss another customer</h2>
            <p>{{ $brand }} answers your calls, chats and WhatsApp messages 24/7 — in your own voice.</p>
            <a href="{{ url('/register') }}" class="btn">Start free — no card required →</a>
            <div style="margin-top:14px;">
                <a href="{{ url('/blog') }}" style="font-size:13.5px; color:var(--neon-2);">← All {{ strtolower(tva_setting('content.blog_label', 'Insights')) }}</a>
            </div>
        </div>
    </div>
</section>
@endsection
