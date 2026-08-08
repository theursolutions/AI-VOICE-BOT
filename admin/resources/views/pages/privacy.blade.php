@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $email = tva_setting('content.contact_email', 'info@serveai.com.pk');
    $phone = tva_setting('content.contact_phone', '+92 349 149 4383');
    $addr  = tva_setting('content.contact_address', 'Arfa Software Technology Park, Lahore, Pakistan');
    $effective = 'June 28, 2026';
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Legal',
    'pageTitle'       => 'Privacy Policy',
    'pageSubtitle'    => 'Plain-English: what we collect, why, and the control you keep over it.',
    'pageMeta'        => 'Last updated: ' . $effective,
    'seoTitle'        => 'Privacy Policy — ' . $brand,
    'metaDescription' => 'How ' . $brand . ' collects, uses, stores and protects personal and conversation data — written in plain language, with your rights and our retention periods spelled out.',
    'breadcrumbs'     => [['name' => 'Privacy Policy', 'url' => '/privacy']],
])

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <p class="lead">This Privacy Policy explains how <strong>{{ $brand }}</strong> ("we", "us", "our") handles information when you visit our website, create an account, or use our AI receptionist and CRM services (the "Service"). We've kept it readable on purpose.</p>

            <div class="note">In short: we only collect what we need to run the Service, we don't sell your data, and the conversation data your business creates belongs to you. You can export or delete it at any time.</div>

            <h2 id="who-we-are">1. Who this covers</h2>
            <p>This policy applies to two groups: <strong>our customers</strong> (businesses who sign up to use {{ $brand }}) and <strong>their end-users</strong> (the people who call, chat, or message an AI agent powered by {{ $brand }}). When your business uses our Service to talk to your customers, you are the "controller" of that conversation data and we act as your "processor".</p>

            <h2 id="what-we-collect">2. What we collect</h2>
            <h3>Information you give us</h3>
            <ul>
                <li><strong>Account details</strong> — your name, email, password, business name, and workspace settings.</li>
                <li><strong>Business content</strong> — the data sources you connect (website content, documents, databases, webhooks) so the AI can answer from your information.</li>
                <li><strong>Billing details</strong> — handled by our payment provider; we store only what we need to manage your subscription, never your full card number.</li>
            </ul>
            <h3>Information created when the Service is used</h3>
            <ul>
                <li><strong>Conversations</strong> — transcripts and recordings of calls, chats, and messages handled by your AI agents, plus any leads extracted from them.</li>
                <li><strong>Usage &amp; device data</strong> — IP address, browser type, pages visited, and basic analytics that help us keep the Service secure and improve it.</li>
                <li><strong>Cookies</strong> — see our <a href="{{ url('/cookies') }}">Cookie Policy</a>.</li>
            </ul>

            <h2 id="how-we-use">3. How we use information</h2>
            <ul>
                <li>To provide, operate, and secure the Service.</li>
                <li>To answer your end-users' questions using only the data sources you connect.</li>
                <li>To capture and organise leads for your CRM.</li>
                <li>To process payments and manage your subscription.</li>
                <li>To send service notices, respond to support requests, and — only if you opt in — product updates.</li>
                <li>To detect, prevent, and investigate fraud, abuse, and security issues.</li>
            </ul>
            <p>We do <strong>not</strong> sell your personal data, and we do not use your private business or conversation data to train public AI models.</p>

            <h2 id="ai-providers">4. AI processing</h2>
            <p>To generate replies, the Service may send the relevant parts of a conversation and your connected data to an AI model provider you choose (for example a hosted provider, or a private model running on your own infrastructure). You control which engine is used and, through field-level access controls, exactly which of your data the AI is allowed to see.</p>

            <h2 id="sharing">5. When we share information</h2>
            <p>We share information only with:</p>
            <ul>
                <li><strong>Service providers</strong> who help us run the Service (hosting, telephony, messaging, AI inference, payment, email), bound by contracts to protect it.</li>
                <li><strong>Your chosen integrations</strong> — the channels and data sources you connect.</li>
                <li><strong>Authorities</strong>, where required by law or to protect rights and safety.</li>
                <li><strong>A successor</strong>, if the business is involved in a merger or acquisition (you'll be notified).</li>
            </ul>

            <h2 id="retention">6. How long we keep it</h2>
            <p>We keep account data while your account is active. Conversation and lead data is kept until you delete it or close your account. After closure, we remove or anonymise data within a reasonable period, except where we must retain records for legal, tax, or security reasons.</p>

            <h2 id="security">7. How we protect it</h2>
            <p>Each customer workspace is isolated in its own database, access is restricted and logged, and data is encrypted in transit. You can read more on our <a href="{{ url('/security') }}">Security</a> page. No system is perfectly secure, but we work hard to keep yours safe.</p>

            <h2 id="rights">8. Your rights</h2>
            <p>Depending on where you live, you may have the right to access, correct, export, or delete your personal data, and to object to or restrict certain processing. You can do much of this yourself from your dashboard, or contact us and we'll help. End-users should contact the business they interacted with first; we'll support that business in responding.</p>

            <h2 id="children">9. Children</h2>
            <p>The Service is for businesses and is not directed at children under 16. We don't knowingly collect their data.</p>

            <h2 id="international">10. International transfers</h2>
            <p>We may process data in countries other than yours. Where we do, we use appropriate safeguards to protect it.</p>

            <h2 id="changes">11. Changes to this policy</h2>
            <p>We'll update this page when our practices change and revise the "last updated" date above. Significant changes will be communicated through the Service.</p>

            <h2 id="contact">12. Contact us</h2>
            <p>Questions about privacy? Reach us at:</p>
            <ul>
                <li>Email: <a href="mailto:{{ $email }}">{{ $email }}</a></li>
                @if ($phone)<li>Phone: <a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a></li>@endif
                @if ($addr)<li>Address: {{ $addr }}</li>@endif
            </ul>

            <div class="note">This document is a general template provided for convenience and is not legal advice. Please have it reviewed by a qualified professional and tailored to your jurisdiction before relying on it.</div>
        </div>
    </div>
</section>
@endsection
