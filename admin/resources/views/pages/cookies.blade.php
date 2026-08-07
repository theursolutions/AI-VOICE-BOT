@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $email = tva_setting('content.contact_email', 'hello@serveai.com');
    $effective = 'June 28, 2026';
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Legal',
    'pageTitle'       => 'Cookie Policy',
    'pageSubtitle'    => 'The small files that keep you signed in and help us improve the site.',
    'pageMeta'        => 'Last updated: ' . $effective,
    'metaDescription' => 'How ' . $brand . ' uses cookies and similar technologies.',
])

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <p class="lead">This Cookie Policy explains how <strong>{{ $brand }}</strong> uses cookies and similar technologies on our website and Service.</p>

            <h2 id="what">What are cookies?</h2>
            <p>Cookies are small text files stored on your device when you visit a website. They let the site remember things — like keeping you signed in — and help us understand how the site is used.</p>

            <h2 id="types">The cookies we use</h2>
            <table>
                <thead>
                    <tr><th>Type</th><th>What it does</th><th>Can you turn it off?</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Essential</strong></td><td>Sign-in, security, and core features. The Service can't work without these.</td><td>No — they're required.</td></tr>
                    <tr><td><strong>Preferences</strong></td><td>Remembers settings like your language or layout choices.</td><td>Yes</td></tr>
                    <tr><td><strong>Analytics</strong></td><td>Helps us see which pages are used so we can improve them. Only loaded if you've configured an analytics tool.</td><td>Yes</td></tr>
                    <tr><td><strong>Marketing</strong></td><td>Used by advertising/pixel tools, only if you enable them.</td><td>Yes</td></tr>
                </tbody>
            </table>

            <h2 id="third-party">Third-party cookies</h2>
            <p>If your workspace enables analytics or advertising tools (for example a website analytics tag or a social pixel), those providers may set their own cookies. They're governed by the provider's own policies.</p>

            <h2 id="manage">Managing cookies</h2>
            <p>You can control or delete cookies through your browser settings, and most browsers let you block them. Note that blocking essential cookies will stop you from signing in or using parts of the Service.</p>

            <h2 id="changes">Changes</h2>
            <p>We may update this policy as our use of cookies changes. The "last updated" date above shows the latest version.</p>

            <h2 id="contact">Contact</h2>
            <p>Questions about cookies? Email <a href="mailto:{{ $email }}">{{ $email }}</a>. See also our <a href="{{ url('/privacy') }}">Privacy Policy</a>.</p>
        </div>
    </div>
</section>
@endsection
