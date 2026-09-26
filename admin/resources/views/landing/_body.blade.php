{{--
    Shared body for the product landing pages (resources/views/landing/*).

    Each page defines its own $page array — the words are the page's, this
    partial only lays them out — and passes the same $page['faqs'] to
    App\Support\Schema::faqPage() so the FAQ markup matches what is shown.

    $page keys (all optional except path):
        path        string   this page's path, e.g. '/ai-voice-agent'
        intro       string[] opening paragraphs (trusted HTML — links allowed)
        stepsTitle  string
        steps       array    [[title, body], …]
        featuresTitle, featuresLead
        features    array    [[title, body], …]
        casesTitle, casesLead
        cases       array    [[title, body], …]
        fit         array    ['title' => …, 'html' => …] — honest limits section
        faqs        array    [[question, answer], …] plain text
        related     string[] landing-page paths to link, in order
        ctaTitle, ctaBody
--}}
@php
    $brand    = tva_setting('content.brand_name', 'serveAI');
    $landing  = (array) config('site.landing_pages', []);
    $selfPath = $page['path'] ?? '';

    $relatedPages = collect($page['related'] ?? [])
        ->filter(fn ($p) => $p !== $selfPath && isset($landing[$p]))
        ->map(fn ($p) => ['url' => $p] + $landing[$p])
        ->values();

    // Articles that share a tag with this page. A failing query must not take
    // a product page down, so it degrades to "no articles" instead.
    $wanted   = array_map('strtolower', (array) ($landing[$selfPath]['tags'] ?? []));
    $articles = rescue(fn () => \App\Models\BlogPost::indexable()->newestFirst()->limit(40)->get()
        ->filter(fn ($p) => count(array_intersect($wanted, array_map('strtolower', (array) $p->tags))) > 0)
        ->take(3)
        ->values(), collect(), false);
@endphp

@push('head')
<style>
    .lp { padding: 10px 0 40px; }
    .lp .wrap { max-width: 1080px; }
    .lp-cta-row { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin: -6px 0 34px; }
    .lp-section { margin: 0 0 56px; }
    .lp-section > h2 { font-size: clamp(22px, 3.2vw, 30px); font-weight: 800; letter-spacing: -.02em; margin: 0 0 10px; text-align: center; }
    .lp-section > .lp-lead { color: var(--text-dim); text-align: center; max-width: 660px; margin: 0 auto 26px; font-size: 16px; }
    .lp-intro { max-width: 820px; margin: 0 auto 56px; }
    .lp-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
    /* Four cards read as a row (or 2×2), never as three plus an orphan. */
    .lp-grid--4 { grid-template-columns: repeat(4, 1fr); }
    @media (max-width: 960px) { .lp-grid--4 { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 540px) { .lp-grid--4 { grid-template-columns: 1fr; } }
    .lp-card { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
    .lp-card h3 { font-size: 16px; font-weight: 700; margin: 0 0 8px; color: var(--text); }
    .lp-card p { margin: 0; color: var(--text-dim); font-size: 14.5px; line-height: 1.6; }
    .lp-steps { counter-reset: lpstep; }
    .lp-steps .lp-card h3::before {
        counter-increment: lpstep; content: counter(lpstep, decimal-leading-zero);
        display: block; font-family: 'JetBrains Mono', ui-monospace, monospace;
        font-size: 12px; color: var(--neon-2); margin-bottom: 8px; letter-spacing: .08em;
    }
    .lp-fit { max-width: 820px; margin: 0 auto 56px; }
    .lp-faq { max-width: 820px; margin: 0 auto; }
    .lp-faq details { border: 1px solid var(--line); border-radius: 14px; background: var(--panel); margin: 0 0 10px; overflow: hidden; }
    .lp-faq summary { cursor: pointer; padding: 16px 20px; font-weight: 600; font-size: 15px; color: var(--text); list-style: none; }
    .lp-faq summary::-webkit-details-marker { display: none; }
    .lp-faq details > div { padding: 0 20px 18px; color: var(--text-dim); font-size: 14.5px; line-height: 1.6; }
    a.lp-card { display: block; transition: border-color .15s, transform .15s; }
    a.lp-card:hover { border-color: var(--line-hot); transform: translateY(-2px); }
    a.lp-card .lp-more { display: inline-block; margin-top: 10px; font-size: 13px; font-weight: 600; color: var(--neon-2); }
    .lp-kicker { font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--text-dim2); margin-bottom: 6px; }
</style>
@endpush

<section class="lp">
    <div class="wrap">
        <div class="lp-cta-row">
            <a href="{{ url('/register') }}" class="btn">Start free — no card required</a>
            <a href="{{ url('/pricing') }}" class="btn btn--ghost">See pricing</a>
        </div>

        @if (!empty($page['intro']))
            <div class="prose lp-intro">
                @foreach ($page['intro'] as $para)
                    <p @if($loop->first) class="lead" @endif>{!! $para !!}</p>
                @endforeach
            </div>
        @endif

        @if (!empty($page['steps']))
            <div class="lp-section">
                <h2>{{ $page['stepsTitle'] ?? 'How it works' }}</h2>
                <div class="lp-grid lp-steps {{ count($page['steps']) === 4 ? 'lp-grid--4' : '' }}">
                    @foreach ($page['steps'] as [$title, $body])
                        <div class="lp-card"><h3>{{ $title }}</h3><p>{{ $body }}</p></div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (!empty($page['features']))
            <div class="lp-section">
                <h2>{{ $page['featuresTitle'] ?? 'What you get' }}</h2>
                @isset($page['featuresLead'])<p class="lp-lead">{{ $page['featuresLead'] }}</p>@endisset
                <div class="lp-grid">
                    @foreach ($page['features'] as [$title, $body])
                        <div class="lp-card"><h3>{{ $title }}</h3><p>{{ $body }}</p></div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (!empty($page['cases']))
            <div class="lp-section">
                <h2>{{ $page['casesTitle'] ?? 'Who uses it' }}</h2>
                @isset($page['casesLead'])<p class="lp-lead">{{ $page['casesLead'] }}</p>@endisset
                <div class="lp-grid {{ count($page['cases']) === 4 ? 'lp-grid--4' : '' }}">
                    @foreach ($page['cases'] as [$title, $body])
                        <div class="lp-card"><h3>{{ $title }}</h3><p>{{ $body }}</p></div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (!empty($page['fit']))
            <div class="prose lp-fit">
                <h2>{{ $page['fit']['title'] }}</h2>
                {!! $page['fit']['html'] !!}
            </div>
        @endif

        @if (!empty($page['faqs']))
            <div class="lp-section">
                <h2>Frequently asked questions</h2>
                <div class="lp-faq">
                    @foreach ($page['faqs'] as $i => [$q, $a])
                        <details @if($i === 0) open @endif>
                            <summary>{{ $q }}</summary>
                            <div>{{ $a }}</div>
                        </details>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($relatedPages->isNotEmpty())
            <div class="lp-section">
                <h2>Related</h2>
                <div class="lp-grid">
                    @foreach ($relatedPages as $rp)
                        <a href="{{ url($rp['url']) }}" class="lp-card">
                            <h3>{{ ucfirst($rp['label']) }}</h3>
                            <p>{{ $rp['blurb'] }}</p>
                            <span class="lp-more">Learn more →</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($articles->isNotEmpty())
            <div class="lp-section">
                <h2>Further reading</h2>
                <div class="lp-grid">
                    @foreach ($articles as $post)
                        <a href="{{ $post->url }}" class="lp-card">
                            <div class="lp-kicker">{{ $post->category ?: tva_setting('content.blog_label', 'Insights') }}</div>
                            <h3>{{ $post->title }}</h3>
                            <p>{{ \Illuminate\Support\Str::limit((string) $post->excerpt, 140) }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="page-cta">
            <h2>{{ $page['ctaTitle'] ?? 'Try it on your own data' }}</h2>
            <p>{{ $page['ctaBody'] ?? 'Connect a data source, pick a voice and go live. Free to start, no credit card.' }}</p>
            <a href="{{ url('/register') }}" class="btn">Start free →</a>
            <a href="{{ url('/contact') }}" class="btn btn--ghost" style="margin-left:10px;">Talk to us</a>
        </div>
    </div>
</section>
