@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'About us',
    'pageTitle'       => 'Every business deserves an assistant that <span class="accent">never sleeps.</span>',
    'pageSubtitle'    => 'We built ' . $brand . ' so a missed call or a slow reply never costs you another customer.',
    'metaDescription' => 'About ' . $brand . ' — our mission to give every business an AI receptionist and CRM that works around the clock.',
])

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <h2 id="why">Why we exist</h2>
            <p>Most businesses lose customers in the gap between "interested" and "answered". A call rings out after hours. A WhatsApp message sits unread during the lunch rush. A website visitor has a question at midnight and no one's there. Each one is a customer who quietly goes elsewhere.</p>
            <p>Big companies solve this with call centres and large teams. Everyone else just loses the lead. We thought that was unfair — so we built {{ $brand }} to give any business, of any size, an assistant that picks up every time.</p>

            <h2 id="what">What {{ $brand }} does</h2>
            <p>{{ $brand }} is an AI receptionist and CRM in one. It answers your phone calls, web chats, and social messages 24/7 — in a natural human voice, in your customer's language — using only your real business information. It qualifies the people worth your time, books them in, and drops every lead straight into a CRM your team can act on.</p>
            <p>No code. No engineers. No call centre. You connect your information, pick a voice, and go live the same day.</p>

            <h2 id="believe">What we believe</h2>
            <ul>
                <li><strong>Speed wins customers.</strong> The fastest reply usually gets the sale. Your AI replies instantly, every time.</li>
                <li><strong>Your data is yours.</strong> Isolated, controlled by you, and exportable whenever you want. No lock-in.</li>
                <li><strong>Simple beats complicated.</strong> Powerful technology should feel effortless to the person using it.</li>
                <li><strong>Honesty over hype.</strong> AI is a tool to grow your business — we'll always tell you what it can and can't do.</li>
            </ul>

            <h2 id="who">Who it's for</h2>
            <p>Shops and online stores, clinics and salons, real-estate offices, restaurants, tradespeople, agencies, and B2B teams — anyone who has customers and can't afford to miss them. If people call, message, or fill in a form on your site, {{ $brand }} pays for itself.</p>

            <div class="note">We're just getting started. {{ $brand }} is built to grow with you — new channels, new languages, and smarter automation are always on the way.</div>
        </div>

        <div class="page-cta" style="margin-top:36px;">
            <h2>See it answer for your business</h2>
            <p>Spin up your own AI agent in minutes — free, no credit card required.</p>
            <a href="{{ url('/register') }}" class="btn">Start free</a>
            <a href="{{ url('/contact') }}" class="btn btn--ghost" style="margin-left:10px;">Talk to us</a>
        </div>
    </div>
</section>
@endsection
