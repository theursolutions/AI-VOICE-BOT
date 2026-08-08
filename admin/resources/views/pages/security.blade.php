@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $email = tva_setting('content.contact_email', 'info@serveai.com.pk');
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Trust',
    'pageTitle'       => 'Security &amp; Trust',
    'pageSubtitle'    => 'You hand an AI the keys to your customers and your data. Here is how we keep both safe.',
    'seoTitle'        => 'Security & data protection — ' . $brand,
    'metaDescription' => 'How ' . $brand . ' protects your business and customer data: per-tenant database isolation, column-level AI access controls, encryption in transit and a full audit trail.',
    'breadcrumbs'     => [['name' => 'Security', 'url' => '/security']],
])

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <p class="lead">Security isn't a feature we bolt on — it's how {{ $brand }} is built. Every design choice starts from one question: does this keep your data, and your customers' trust, safe?</p>

            <h2 id="isolation">Your data is isolated</h2>
            <p>Every customer workspace runs in its own dedicated database. Your conversations, leads, and connected data are never pooled or mixed with other businesses. One tenant can never see another's data — by design, not by policy.</p>

            <h2 id="ai-control">You decide what the AI can see</h2>
            <p>When you connect a database, you choose — table by table and column by column — exactly what the AI is allowed to read. Sensitive fields like payment details, internal notes, or personal identifiers can be hidden from the model entirely, so they never leave your control.</p>

            <h2 id="encryption">Encrypted and access-controlled</h2>
            <p>Data is encrypted in transit. Access to systems is restricted to the minimum needed to run the Service, protected by authentication, and recorded. Credentials and API keys are stored securely and never exposed in plain text.</p>

            <h2 id="handover">Humans stay in the loop</h2>
            <p>Your team can take over any conversation instantly from the shared inbox and hand it back to the AI when finished. You're always one click away from stepping in.</p>

            <h2 id="audit">Everything is logged</h2>
            <p>Administrative actions and conversations are recorded and replayable, giving you a complete, transparent trail of what happened, when, and who did it.</p>

            <h2 id="choice">Run it your way</h2>
            <p>Use our managed cloud, bring your own AI provider keys, or run language models entirely on your own infrastructure for maximum privacy. Your stack, your rules.</p>

            <h2 id="ownership">You own your data</h2>
            <p>Your data belongs to you. Export your leads and conversations whenever you like, and if you ever leave, take everything with you. No lock-in, no hostage data.</p>

            <h2 id="roles">Team access you control</h2>
            <p>Invite your team with custom roles and per-project permissions. Decide who can see billing, who can edit automations, and who can work the inbox — right down to the project level.</p>

            <h2 id="report">Reporting a concern</h2>
            <p>Found a vulnerability or have a security question? We take it seriously. Email <a href="mailto:{{ $email }}">{{ $email }}</a> and our team will respond promptly. Please don't publicly disclose an issue before we've had a chance to address it.</p>

            <div class="note">We continually improve our security posture as we grow. This page describes our current practices and will be updated as they evolve.</div>
        </div>

        <div class="page-cta" style="margin-top:36px;">
            <h2>Security questions before you commit?</h2>
            <p>Talk to us — we're happy to walk your team through exactly how your data is handled.</p>
            <a href="{{ url('/contact') }}" class="btn">Contact our team</a>
        </div>
    </div>
</section>
@endsection
