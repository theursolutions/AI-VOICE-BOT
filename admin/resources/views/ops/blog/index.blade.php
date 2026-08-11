@extends('layouts.ops')

@section('content')
<style>
    .blg-statgrid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:18px; }
    @media (max-width:900px) { .blg-statgrid { grid-template-columns:repeat(2,1fr); } }
    .blg-stat { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:15px 18px; }
    .blg-stat__v { font-size:22px; font-weight:800; color:#0f172a; line-height:1.1; }
    .blg-stat__l { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600; margin-top:3px; }

    .blg-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
    .blg-row { display:flex; align-items:center; gap:14px; padding:13px 18px; border-bottom:1px solid #f1f5f9; }
    .blg-row:last-child { border-bottom:none; }
    .blg-row:hover { background:#fafbff; }
    .blg-thumb {
        width:76px; height:44px; border-radius:8px; overflow:hidden; flex-shrink:0;
        background:#e2e8f0; display:flex; align-items:center; justify-content:center;
        font-weight:800; color:#94a3b8; font-size:16px;
    }
    .blg-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .blg-title { font-size:14px; font-weight:650; color:#0f172a; }
    .blg-meta { font-size:11.5px; color:#94a3b8; margin-top:2px; }
    .blg-chip { font-size:10px; padding:2px 8px; border-radius:999px; background:#e2e8f0; color:#475569; font-weight:700; white-space:nowrap; }
    .blg-chip--live { background:#dcfce7; color:#15803d; }
    .blg-chip--draft { background:#fef3c7; color:#92400e; }
    .blg-chip--sched { background:#dbeafe; color:#1e40af; }
    .blg-chip--noidx { background:#fee2e2; color:#b91c1c; }
    .blg-chip--star { background:#fef9c3; color:#a16207; }
    .blg-actions { display:flex; align-items:center; gap:6px; margin-left:auto; }
    .blg-empty { padding:60px 20px; text-align:center; color:#94a3b8; }

    html.dark .blg-stat, html.dark .blg-card { background:#1e293b; border-color:#334155; }
    html.dark .blg-stat__v, html.dark .blg-title { color:#f1f5f9; }
    html.dark .blg-row { border-bottom-color:#334155; }
    html.dark .blg-row:hover { background:#0f172a; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">📰</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">
                Articles <span style="opacity:.75; font-weight:400; font-size:14px;">· published at /blog</span>
            </div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Every published article gets its own indexable page, Article structured data and a
                sitemap entry automatically. The section is called
                “{{ tva_setting('content.blog_label', 'Insights') }}” on the site — rename it in
                <a href="{{ route('ops.content.index') }}" style="color:#fff; text-decoration:underline;">Site content</a>.
            </div>
        </div>
        <a href="{{ route('ops.blog.create') }}" class="btn" style="background:#fff; color:#0f172a; font-weight:700; flex-shrink:0;">
            <i data-lucide="plus" class="w-4 h-4 mr-1 inline"></i> New article
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-warning-soft show mb-4 flex items-center">
            <i data-lucide="alert-triangle" class="w-4 h-4 mr-2"></i> {{ session('error') }}
        </div>
    @endif

    <div class="blg-statgrid">
        <div class="blg-stat"><div class="blg-stat__v">{{ $stats['published'] }}</div><div class="blg-stat__l">Live</div></div>
        <div class="blg-stat"><div class="blg-stat__v">{{ $stats['drafts'] }}</div><div class="blg-stat__l">Drafts</div></div>
        <div class="blg-stat"><div class="blg-stat__v">{{ $stats['scheduled'] }}</div><div class="blg-stat__l">Scheduled</div></div>
        <div class="blg-stat"><div class="blg-stat__v">{{ number_format($stats['views']) }}</div><div class="blg-stat__l">Total views</div></div>
    </div>

    <div class="blg-card">
        @forelse ($posts as $post)
            <div class="blg-row">
                <div class="blg-thumb">
                    @if (trim((string) $post->cover_url) !== '')
                        <img src="{{ $post->cover_url }}" alt="" width="76" height="44" loading="lazy">
                    @else
                        {{ mb_strtoupper(mb_substr($post->title, 0, 1)) }}
                    @endif
                </div>

                <div class="min-w-0" style="flex:1;">
                    <div class="blg-title truncate">{{ $post->title }}</div>
                    <div class="blg-meta truncate">
                        /blog/{{ $post->slug }}
                        @if ($post->published_at) · {{ $post->published_at->format('M j, Y') }} @endif
                        · {{ $post->reading_time }} min
                        @if ($post->views) · {{ number_format($post->views) }} views @endif
                    </div>
                </div>

                @if ($post->category)<span class="blg-chip">{{ $post->category }}</span>@endif
                @if ($post->is_featured)<span class="blg-chip blg-chip--star" title="Shown as the hero on /blog">★ Featured</span>@endif
                @if ($post->noindex)<span class="blg-chip blg-chip--noidx" title="Excluded from search engines and the sitemap">noindex</span>@endif

                @if ($post->isPublished())
                    <span class="blg-chip blg-chip--live">LIVE</span>
                @elseif ($post->isScheduled())
                    <span class="blg-chip blg-chip--sched" title="Goes live {{ $post->published_at->format('M j, Y H:i') }}">SCHEDULED</span>
                @else
                    <span class="blg-chip blg-chip--draft">DRAFT</span>
                @endif

                <div class="blg-actions">
                    {{-- Preview works for drafts too: the public page renders for
                         staff and is noindex until published. --}}
                    <a href="{{ url('/blog/' . $post->slug) }}" target="_blank" rel="noopener"
                       class="btn btn-sm btn-secondary" title="{{ $post->isPublished() ? 'View live page' : 'Preview (staff only, noindex)' }}">
                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                    </a>

                    <a href="{{ route('ops.blog.edit', ['id' => hashid($post->id)]) }}" class="btn btn-sm btn-secondary" title="Edit">
                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                    </a>

                    <form method="POST" action="{{ route('ops.blog.feature', ['id' => hashid($post->id)]) }}" class="inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary"
                                title="{{ $post->is_featured ? 'Remove from the hero slot' : 'Make this the hero article on /blog' }}">
                            <i data-lucide="star" class="w-3.5 h-3.5" @if($post->is_featured) style="color:#eab308;" @endif></i>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('ops.blog.toggle', ['id' => hashid($post->id)]) }}" class="inline">
                        @csrf
                        <button type="submit" class="btn btn-sm {{ $post->status === 'published' ? 'btn-secondary' : 'btn-primary' }}">
                            {{ $post->status === 'published' ? 'Unpublish' : 'Publish' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('ops.blog.delete', ['id' => hashid($post->id)]) }}" class="inline"
                          onsubmit="return confirm('Delete “{{ addslashes($post->title) }}”?\n\nIt will disappear from /blog and the sitemap. If it is already indexed, Google will show a 404 until it re-crawls.');">
                        @csrf
                        <button type="submit" class="btn btn-sm" style="border:1px solid #fecaca; color:#dc2626; background:#fff;" title="Delete">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div class="blg-empty">
                <div style="font-size:34px; margin-bottom:10px;">📝</div>
                <div style="font-size:15px; color:#475569; font-weight:600;">No articles yet.</div>
                <div style="font-size:13px; margin-top:5px;">
                    This is the highest-leverage SEO work available to you — one useful article a week compounds.
                </div>
                <a href="{{ route('ops.blog.create') }}" class="btn btn-primary mt-4">
                    <i data-lucide="plus" class="w-4 h-4 mr-1 inline"></i> Write the first one
                </a>
            </div>
        @endforelse
    </div>
</div>

<script>
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}
</script>
@endsection
