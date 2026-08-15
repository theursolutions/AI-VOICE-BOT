@extends('layouts.ops')

@section('content')
{{--
    Article editor. Create and edit share this file — the only difference is
    the form action and whether the slug is still editable.

    Rich text is Quill 2 (MIT, free, no API key, no account). It emits plain
    semantic HTML — h2/h3, p, ul, ol, blockquote, pre, a, img — which is
    exactly what the public article template is styled for, and exactly what
    Google parses. Toolbar is deliberately limited: no font pickers or colour
    swatches, because inline styling in body copy is how a blog stops looking
    like one site.
--}}
@php
    $isEdit = (bool) $post->exists;
    $action = $isEdit
        ? route('ops.blog.update', ['id' => hashid($post->id)])
        : route('ops.blog.store');
@endphp

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<style>
    .bf-grid { display:grid; grid-template-columns: 1fr 330px; gap:18px; align-items:start; }
    @media (max-width:1100px) { .bf-grid { grid-template-columns:1fr; } }

    .bf-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px 22px; margin-bottom:16px; }
    .bf-card__t { font-size:14px; font-weight:700; color:#0f172a; margin-bottom:4px; display:flex; align-items:center; gap:7px; }
    .bf-card__s { font-size:12px; color:#64748b; margin-bottom:15px; }

    .bf-field { margin-bottom:14px; }
    .bf-field > label { display:block; font-size:12px; font-weight:650; color:#334155; margin-bottom:5px; }
    .bf-field .hint { font-weight:400; color:#94a3b8; font-size:11px; margin-left:5px; }
    .bf-input, .bf-textarea, .bf-select {
        width:100%; padding:9px 12px; border:1px solid #e2e8f0; border-radius:9px;
        background:#fff; font-size:13.5px; color:#0f172a; font-family:inherit;
    }
    .bf-input:focus, .bf-textarea:focus, .bf-select:focus {
        outline:none; border-color:var(--tva-accent); box-shadow:0 0 0 3px rgba(255,184,0,.15);
    }
    .bf-textarea { resize:vertical; min-height:74px; }
    .bf-counter { font-size:11px; color:#94a3b8; margin-top:4px; text-align:right; }
    .bf-counter.is-over { color:#dc2626; font-weight:650; }

    .bf-toggle { display:flex; align-items:flex-start; gap:10px; padding:11px 13px; border:1px solid #e2e8f0; border-radius:10px; background:#fafbff; margin-bottom:12px; }
    .bf-toggle input { width:17px; height:17px; margin-top:1px; accent-color:#c97a00; flex-shrink:0; }
    .bf-toggle b { font-size:13px; color:#0f172a; }
    .bf-toggle span { display:block; font-size:11.5px; color:#64748b; }

    /* Editor. min-height keeps it feeling like a page rather than a box. */
    #bfEditor { min-height:460px; font-size:15px; line-height:1.7; }
    .ql-toolbar.ql-snow, .ql-container.ql-snow { border-color:#e2e8f0; }
    .ql-toolbar.ql-snow { border-radius:9px 9px 0 0; background:#fafbff; }
    .ql-container.ql-snow { border-radius:0 0 9px 9px; }
    .ql-editor h2 { font-size:22px; font-weight:800; margin:1.2em 0 .5em; }
    .ql-editor h3 { font-size:18px; font-weight:700; margin:1em 0 .4em; }
    .ql-editor blockquote { border-left:3px solid #c97a00; padding-left:14px; color:#475569; }
    .ql-editor img { max-width:100%; border-radius:8px; }

    .bf-cover { width:100%; aspect-ratio:16/9; border-radius:11px; border:2px dashed #cbd5e1; background:#f8fafc; overflow:hidden; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:12px; }
    .bf-cover img { width:100%; height:100%; object-fit:cover; }

    .bf-serp { border:1px solid #e2e8f0; border-radius:11px; padding:13px 15px; background:#fafbff; }
    .bf-serp__u { color:#0f7d27; font-size:12px; word-break:break-all; }
    .bf-serp__t { color:#1a0dab; font-size:16px; margin:2px 0; line-height:1.3; }
    .bf-serp__d { color:#4d5156; font-size:12.5px; }

    .bf-savebar {
        position:sticky; bottom:0; z-index:20; margin-top:6px;
        background:rgba(255,255,255,.94); backdrop-filter:blur(8px);
        border:1px solid #e2e8f0; border-radius:12px; padding:12px 18px;
        display:flex; align-items:center; justify-content:space-between; gap:12px;
        box-shadow:0 -6px 20px -12px rgba(0,0,0,.25);
    }

    html.dark .bf-card, html.dark .bf-serp { background:#1e293b; border-color:#334155; }
    html.dark .bf-card__t { color:#f1f5f9; }
    html.dark .bf-field > label { color:#cbd5e1; }
    html.dark .bf-input, html.dark .bf-textarea, html.dark .bf-select { background:#0f172a; color:#f1f5f9; border-color:#334155; }
    html.dark .bf-toggle { background:#0f172a; border-color:#334155; }
    html.dark .bf-toggle b { color:#f1f5f9; }
    html.dark .bf-savebar { background:rgba(15,23,42,.94); border-color:#334155; }
    html.dark .ql-toolbar.ql-snow { background:#0f172a; }
    html.dark .ql-editor { color:#f1f5f9; }
</style>

<div class="content" style="max-width:1180px;">
    <div class="flex items-center gap-3 mt-6 mb-4">
        <a href="{{ route('ops.blog.index') }}" class="btn btn-secondary btn-sm">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5 mr-1 inline"></i> Articles
        </a>
        <div style="font-size:19px; font-weight:700;">{{ $title }}</div>
        @if ($isEdit)
            <a href="{{ url('/blog/' . $post->slug) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm ml-auto">
                <i data-lucide="external-link" class="w-3.5 h-3.5 mr-1 inline"></i>
                {{ $post->isPublished() ? 'View live' : 'Preview' }}
            </a>
        @endif
    </div>

    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif
    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" id="bfForm">
        @csrf
        {{-- Quill writes into this on submit. --}}
        <input type="hidden" name="body" id="bfBody">

        <div class="bf-grid">
            {{-- ══════════ Main column ══════════ --}}
            <div>
                <div class="bf-card">
                    <div class="bf-field">
                        <label>Headline <span class="hint">what readers and Google see first</span></label>
                        <input type="text" name="title" id="bfTitle" class="bf-input" maxlength="191" required
                               value="{{ old('title', $post->title) }}" placeholder="How much does an AI receptionist cost in Pakistan?">
                    </div>

                    <div class="bf-field">
                        <label>Standfirst <span class="hint">optional — one line under the headline</span></label>
                        <input type="text" name="subtitle" class="bf-input" maxlength="255"
                               value="{{ old('subtitle', $post->subtitle) }}">
                    </div>

                    <div class="bf-field">
                        <label>URL <span class="hint">
                            @if ($isEdit && $post->isPublished())
                                locked — a published URL keeps its slug so links and ranking survive
                            @else
                                leave blank to generate from the headline
                            @endif
                        </span></label>
                        <div style="display:flex; align-items:center; gap:0;">
                            <span style="font-size:12.5px; color:#94a3b8; padding:9px 2px 9px 0; white-space:nowrap;">/blog/</span>
                            <input type="text" name="slug" id="bfSlug" class="bf-input" maxlength="191"
                                   value="{{ old('slug', $post->slug) }}"
                                   pattern="[a-z0-9\-]+"
                                   @if ($isEdit && $post->isPublished()) readonly style="background:#f1f5f9; cursor:not-allowed;" @endif
                                   placeholder="ai-receptionist-cost-pakistan">
                        </div>
                    </div>
                </div>

                <div class="bf-card">
                    <div class="bf-card__t"><i data-lucide="type" class="w-4 h-4"></i> Article</div>
                    <div class="bf-card__s">
                        Structure it with <b>Heading 2</b> for sections and <b>Heading 3</b> for sub-points —
                        the page's H1 is the headline above, so starting at H2 keeps the outline valid.
                    </div>
                    <div id="bfEditor">{!! old('body', $post->body) !!}</div>
                    <div class="bf-counter" id="bfWords" style="text-align:left;"></div>
                </div>
            </div>

            {{-- ══════════ Sidebar ══════════ --}}
            <div>
                <div class="bf-card">
                    <div class="bf-card__t"><i data-lucide="send" class="w-4 h-4"></i> Publishing</div>

                    <div class="bf-field">
                        <label>Status</label>
                        <select name="status" class="bf-select">
                            <option value="draft"     @selected(old('status', $post->status) === 'draft')>Draft — not public</option>
                            <option value="published" @selected(old('status', $post->status) === 'published')>Published</option>
                        </select>
                    </div>

                    <div class="bf-field">
                        <label>Publish date <span class="hint">future = scheduled</span></label>
                        <input type="datetime-local" name="published_at" class="bf-input"
                               value="{{ old('published_at', $post->published_at?->format('Y-m-d\TH:i')) }}">
                    </div>

                    <label class="bf-toggle">
                        <input type="checkbox" name="noindex" value="1" @checked(old('noindex', $post->noindex))>
                        <div>
                            <b>Hide from search engines</b>
                            <span>Keeps the page reachable by link but out of Google and the sitemap. For thin or seasonal posts.</span>
                        </div>
                    </label>
                </div>

                <div class="bf-card">
                    <div class="bf-card__t"><i data-lucide="image" class="w-4 h-4"></i> Cover image</div>
                    <div class="bf-card__s">1200×675 (16:9) works best. Used on the card, the article, and in link previews.</div>

                    <div class="bf-cover mb-3" id="bfCoverBox">
                        @if (trim((string) $post->cover_url) !== '')
                            <img src="{{ $post->cover_url }}" alt="" id="bfCoverImg">
                        @else
                            <span id="bfCoverEmpty">No cover — a coloured placeholder is used</span>
                        @endif
                    </div>

                    <div class="bf-field">
                        <label>Upload</label>
                        <input type="file" name="cover" id="bfCoverFile" class="bf-input" accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                    <div class="bf-field">
                        <label>…or paste a URL</label>
                        <input type="text" name="cover_url" class="bf-input" maxlength="500"
                               value="{{ old('cover_url', $post->cover_url) }}" placeholder="https://… or /storage/…">
                    </div>
                    <div class="bf-field">
                        <label>Alt text <span class="hint">describes the image for screen readers &amp; Google Images</span></label>
                        <input type="text" name="cover_alt" class="bf-input" maxlength="191"
                               value="{{ old('cover_alt', $post->cover_alt) }}"
                               placeholder="A receptionist desk with an empty chair">
                    </div>
                </div>

                <div class="bf-card">
                    <div class="bf-card__t"><i data-lucide="search" class="w-4 h-4"></i> Search appearance</div>
                    <div class="bf-card__s">How this looks in Google. Leave blank to derive from the headline and excerpt.</div>

                    <div class="bf-serp mb-3">
                        <div class="bf-serp__u">{{ rtrim(config('app.url'), '/') }}/blog/<span id="serpSlug">{{ $post->slug ?: 'your-article' }}</span></div>
                        <div class="bf-serp__t" id="serpTitle">{{ $post->seo_title ?: 'Your headline here' }}</div>
                        <div class="bf-serp__d" id="serpDesc">{{ $post->exists ? $post->seo_description : 'Your meta description here.' }}</div>
                    </div>

                    <div class="bf-field">
                        <label>Meta title <span class="hint">≤ 60 chars</span></label>
                        <input type="text" name="meta_title" id="bfMetaTitle" class="bf-input" maxlength="191"
                               value="{{ old('meta_title', $post->meta_title) }}">
                        <div class="bf-counter" data-count-for="bfMetaTitle" data-max="60"></div>
                    </div>

                    <div class="bf-field">
                        <label>Meta description <span class="hint">≤ 155 chars</span></label>
                        <textarea name="meta_description" id="bfMetaDesc" class="bf-textarea" maxlength="320">{{ old('meta_description', $post->meta_description) }}</textarea>
                        <div class="bf-counter" data-count-for="bfMetaDesc" data-max="155"></div>
                    </div>

                    <div class="bf-field">
                        <label>Target keywords <span class="hint">comma separated</span></label>
                        <input type="text" name="meta_keywords" class="bf-input" maxlength="500"
                               value="{{ old('meta_keywords', $post->meta_keywords) }}"
                               placeholder="ai receptionist pricing, ai receptionist cost pakistan">
                        <div class="bf-card__s" style="margin:6px 0 0;">
                            Worth knowing: Google has ignored the keywords tag since 2009, so this
                            won't move rankings. Its real value is recording the search intent you
                            aimed at, so you can check it against Search Console later.
                        </div>
                    </div>

                    <div class="bf-field">
                        <label>Canonical URL <span class="hint">only if this republishes something else</span></label>
                        <input type="url" name="canonical_url" class="bf-input" maxlength="500"
                               value="{{ old('canonical_url', $post->canonical_url) }}">
                    </div>
                </div>

                <div class="bf-card">
                    <div class="bf-card__t"><i data-lucide="tag" class="w-4 h-4"></i> Organise</div>

                    <div class="bf-field">
                        <label>Category <span class="hint">groups articles and adds a filter</span></label>
                        <input type="text" name="category" class="bf-input" maxlength="80" list="bfCats"
                               value="{{ old('category', $post->category) }}" placeholder="Guides">
                        <datalist id="bfCats">
                            @foreach ($categories as $c)<option value="{{ $c }}">@endforeach
                        </datalist>
                    </div>

                    <div class="bf-field">
                        <label>Tags <span class="hint">comma separated, max 12</span></label>
                        <input type="text" name="tags_csv" class="bf-input" maxlength="400"
                               value="{{ old('tags_csv', is_array($post->tags) ? implode(', ', $post->tags) : '') }}"
                               placeholder="whatsapp, automation, pricing">
                    </div>

                    <div class="bf-field">
                        <label>Excerpt <span class="hint">card copy; also the fallback meta description</span></label>
                        <textarea name="excerpt" class="bf-textarea" maxlength="600">{{ old('excerpt', $post->excerpt) }}</textarea>
                    </div>

                    <label class="bf-toggle" style="margin-bottom:0;">
                        <input type="checkbox" name="noindex_placeholder" disabled hidden>
                    </label>
                </div>

                <div class="bf-card">
                    <div class="bf-card__t"><i data-lucide="user" class="w-4 h-4"></i> Byline</div>
                    <div class="bf-field">
                        <label>Author</label>
                        <input type="text" name="author_name" class="bf-input" maxlength="120"
                               value="{{ old('author_name', $post->author_name ?: auth()->user()->name) }}">
                    </div>
                    <div class="bf-field">
                        <label>Role</label>
                        <input type="text" name="author_role" class="bf-input" maxlength="120"
                               value="{{ old('author_role', $post->author_role) }}" placeholder="Founder">
                    </div>
                    <div class="bf-field" style="margin-bottom:0;">
                        <label>Reading time <span class="hint">blank = calculated</span></label>
                        <input type="number" name="reading_minutes" class="bf-input" min="1" max="180"
                               value="{{ old('reading_minutes', $post->reading_minutes) }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="bf-savebar">
            <div style="font-size:12px; color:#64748b;">
                @if ($isEdit)
                    Editing <b>/blog/{{ $post->slug }}</b>
                @else
                    Saving as a draft keeps it off the site until you publish.
                @endif
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('ops.blog.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="save" class="w-3.5 h-3.5 mr-1 inline"></i>
                    {{ $isEdit ? 'Save changes' : 'Create article' }}
                </button>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function () {
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}

    /* ── Rich text ────────────────────────────────────────────────────
       Toolbar limited on purpose: headings, emphasis, lists, quote, code,
       link, image. No fonts, sizes or colours — inline styling in body copy
       is what makes a blog look like six different websites. */
    var quill = new Quill('#bfEditor', {
        theme: 'snow',
        placeholder: 'Write the article…',
        modules: {
            toolbar: [
                [{ header: [2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['blockquote', 'code-block'],
                ['link', 'image'],
                ['clean'],
            ],
        },
    });

    var form  = document.getElementById('bfForm');
    var body  = document.getElementById('bfBody');
    var words = document.getElementById('bfWords');

    function countWords() {
        var t = quill.getText().trim();
        var n = t ? t.split(/\s+/).length : 0;
        words.textContent = n + ' words · about ' + Math.max(1, Math.ceil(n / 200)) + ' min read'
            + (n < 300 && n > 0 ? '  ⚠ under 300 words rarely ranks for anything' : '');
    }
    quill.on('text-change', countWords);
    countWords();

    form.addEventListener('submit', function (e) {
        // Move the editor's HTML into the real field. Quill leaves an empty
        // paragraph behind when you clear it, which would pass a `required`
        // check while being blank — so normalise that to empty.
        var html = quill.root.innerHTML;
        body.value = (html === '<p><br></p>') ? '' : html;

        if (!body.value.trim()) {
            e.preventDefault();
            alert('The article body is empty.');
        }
    });

    /* ── Slug: auto-fill from the headline until the author edits it ── */
    var title = document.getElementById('bfTitle');
    var slug  = document.getElementById('bfSlug');
    var slugTouched = slug.value.trim() !== '';
    slug.addEventListener('input', function () { slugTouched = true; });

    function slugify(s) {
        return s.toLowerCase().trim()
            .replace(/[^\w\s-]/g, '')
            .replace(/[\s_]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '')
            .slice(0, 80);
    }

    /* ── Live Google preview ───────────────────────────────────────── */
    var mTitle = document.getElementById('bfMetaTitle');
    var mDesc  = document.getElementById('bfMetaDesc');
    var sSlug  = document.getElementById('serpSlug');
    var sTitle = document.getElementById('serpTitle');
    var sDesc  = document.getElementById('serpDesc');

    function refresh() {
        if (!slugTouched && !slug.readOnly) slug.value = slugify(title.value);
        sSlug.textContent  = slug.value || 'your-article';
        sTitle.textContent = mTitle.value.trim() || (title.value.trim() || 'Your headline here');
        var d = mDesc.value.trim();
        if (!d) {
            var ex = form.querySelector('[name="excerpt"]');
            d = (ex && ex.value.trim()) || quill.getText().trim().slice(0, 155);
        }
        sDesc.textContent = d || 'Your meta description here.';
    }
    [title, slug, mTitle, mDesc, form.querySelector('[name="excerpt"]')].forEach(function (el) {
        if (el) el.addEventListener('input', refresh);
    });
    quill.on('text-change', refresh);
    refresh();

    /* ── Character counters that turn red past the useful limit ────── */
    document.querySelectorAll('[data-count-for]').forEach(function (el) {
        var input = document.getElementById(el.getAttribute('data-count-for'));
        var max   = parseInt(el.getAttribute('data-max'), 10);
        function tick() {
            var n = input.value.length;
            el.textContent = n + ' / ' + max;
            el.classList.toggle('is-over', n > max);
        }
        input.addEventListener('input', tick);
        tick();
    });

    /* ── Instant cover preview ─────────────────────────────────────── */
    document.getElementById('bfCoverFile').addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0];
        if (!f) return;
        var box = document.getElementById('bfCoverBox');
        box.innerHTML = '<img src="' + URL.createObjectURL(f) + '" alt="">';
    });
})();
</script>
@endsection
