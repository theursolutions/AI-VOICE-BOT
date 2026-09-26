@php
    $brand = tva_setting('content.brand_name', 'serveAI');

    // From the catalogue, not typed in: this line said "from $19/month" through
    // two repricings. Dollars, because a search snippet is the same for every
    // visitor; the rupee price is on the cards themselves.
    $from = \App\Models\Billing\Plan::startingPrice()?->formatted();

    // Rupees only when a rupee gateway actually serves Pakistan — the same
    // routing checkout uses. Without one, Pakistani customers pay in dollars
    // like everyone else, and the answer has to say so.
    $rupees = app(\App\Services\Billing\Gateways\GatewayRegistry::class)->currencyForCountry('PK') === 'PKR';

    $faqs = [
        ['What happens after my 7 free days?',
         'Nothing disappears. Your workspace switches to read-only — you keep your login, your leads, your transcripts and your export — and your agent stops answering new customers until you pick a plan. Choose one and everything switches straight back on.'],
        ['Do I need a credit card to start?',
         'No. The 7-day free window needs no card at all. You only enter payment details when you choose a paid plan.'],
        ['Which currency am I charged in?', $rupees
            ? 'In Pakistan, in rupees — the rupee price on the plan is exactly what you pay, through our local payment partner. Everywhere else, in US dollars; if we can tell which country you\'re in we also show an approximate amount in your local currency, but that figure is for reference only and you are charged the USD price.'
            : 'Always US dollars. If we can tell which country you\'re in, we also show an approximate amount in your local currency to save you doing the maths — but that figure is for reference only and your card is charged the USD price.'],
        ['Can I change plans later?',
         'Any time, in one click from your billing page. Upgrades take effect immediately and the difference is prorated on your next invoice — no surprise mid-month charge.'],
        ['What if I go over my monthly allowance?',
         'On a paid plan your agent keeps working and the extra usage is billed at the overage rate for your tier, which gets cheaper as you move up. We\'d rather your phone kept being answered than stop mid-month.'],
        ['Can I cancel whenever I want?',
         'Yes. Cancel from your billing page and you keep full access until the end of the period you\'ve already paid for. No contracts, no cancellation fees, and you can export your data on the way out.'],
        ['Do you offer annual billing?',
         'Yes — pay yearly and get two months free on every paid plan. You can switch between monthly and annual whenever you like.'],
    ];
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Pricing',
    'pageTitle'       => 'One agent. Every channel. <span class="accent">One simple price.</span>',
    'pageSubtitle'    => 'Answer every call, chat and message with AI that knows your business. Start free for 7 days — no credit card.',
    'seoTitle'        => 'Pricing — AI Customer Support Plans | ' . $brand,
    'metaDescription' => 'Simple ' . $brand . ' pricing: start free for 7 days with no card, then '
        . ($from ? 'from ' . $from . '/month' : 'low monthly plans')
        . ' for AI voice, web chat, WhatsApp, Instagram and Facebook with built-in lead capture and CRM.',
    'breadcrumbs'     => [['name' => 'Pricing', 'url' => '/pricing']],
    // FAQPage inside the page's single @graph (it used to be a second,
    // free-standing script block), generated from the visible list below.
    'jsonLd'          => [\App\Support\Schema::faqPage('/pricing', $faqs)],
])

{{--
    The plans live on the HOMEPAGE (/#pricing). This page is the standalone,
    deep-linkable version — it exists because "<product> pricing" is one of the
    highest-intent search queries there is and it wants its own indexable URL
    with its own title, description and breadcrumb.

    Both render the SAME partial from the SAME database-driven view model, so
    they can never disagree about what a plan costs or contains. If you'd rather
    not have a standalone page at all, delete the /pricing route in
    routes/billing.php and its entries in config/site.php — the homepage section
    is entirely independent of it.
--}}

@section('content')
{{-- article--wide: this page is a card grid, not prose, so it needs the full
     1240px container instead of the 820px reading measure .article defaults to. --}}
<section class="article article--wide" style="padding-top:10px">
    <div class="wrap">
        @include('partials.pricing-plans')

        {{-- ── FAQ ─────────────────────────────────────────────────── --}}
        <div style="margin-top:66px">
            <div style="text-align:center;margin:0 auto 26px;max-width:640px">
                <h2 style="font-size:clamp(23px,3.4vw,33px);font-weight:800;letter-spacing:-.02em;margin:0 0 10px">
                    Billing questions, answered
                </h2>
            </div>

            {{-- $faqs is defined at the top of the file so the same array feeds
                 this list and the FAQPage node in the page's JSON-LD graph. --}}

            @foreach ($faqs as $i => [$q, $a])
                <details style="border:1px solid var(--line);border-radius:14px;background:var(--panel);margin:0 0 10px;overflow:hidden"
                         @if($i === 0) open @endif>
                    <summary style="cursor:pointer;padding:16px 20px;font-weight:600;font-size:15px;color:var(--text);list-style:none">
                        {{ $q }}
                    </summary>
                    <div style="padding:0 20px 18px;color:var(--text-dim);font-size:14.5px">{{ $a }}</div>
                </details>
            @endforeach
        </div>

        {{-- ── Trust ───────────────────────────────────────────────── --}}
        <div style="margin-top:60px">
            <div style="text-align:center;margin:0 auto 24px;max-width:640px">
                <h2 style="font-size:clamp(23px,3.4vw,33px);font-weight:800;letter-spacing:-.02em;margin:0 0 10px">
                    Safe to hand your customers to
                </h2>
                <p style="color:var(--text-dim);margin:0;font-size:15.5px">
                    You're giving an AI access to your customers and your data. Here's what protects both.
                </p>
            </div>

            <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                @foreach ([
                    ['Your own database', 'Every workspace runs in its own isolated database. Nothing pooled, nothing shared.'],
                    ['You gate the AI', 'Choose table by table and column by column what the model may read. Sensitive fields stay invisible.'],
                    ['Secure payments', 'Card details go straight to Stripe and never touch our servers. We only ever see the last four digits.'],
                    ['No lock-in', 'Export your leads and conversations whenever you want — including after you cancel.'],
                ] as [$title, $body])
                    <div style="border:1px solid var(--line);border-radius:14px;background:var(--panel);padding:20px">
                        <h4 style="margin:0 0 6px;font-size:14.5px;font-weight:700;color:var(--text)">{{ $title }}</h4>
                        <p style="margin:0;font-size:13px;color:var(--text-dim)">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── CTA ─────────────────────────────────────────────────── --}}
        <div class="page-cta" style="margin-top:56px">
            <h2>Try it on your own data, free for 7 days.</h2>
            <p>Connect a data source, pick a voice, drop the widget on your site. No card, no commitment.</p>
            <a href="{{ url('/register') }}" class="btn">Start free →</a>
        </div>

    </div>
</section>
@endsection
