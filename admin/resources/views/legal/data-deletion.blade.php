@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $email = tva_setting('content.contact_email', 'info@serveai.com.pk');
    $effective = 'August 13, 2026';
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Legal',
    'pageTitle'       => 'Data Deletion',
    'pageSubtitle'    => 'How to have everything we hold about you permanently erased.',
    'pageMeta'        => 'Last updated: ' . $effective,
    'seoTitle'        => 'Data Deletion Instructions — ' . $brand,
    'metaDescription' => 'How to request deletion of your data from ' . $brand . ', what gets deleted, and how long it takes.',
    'breadcrumbs'     => [['name' => 'Data Deletion', 'url' => '/data-deletion']],
])

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <p class="lead">If you have messaged a business that uses <strong>{{ $brand }}</strong> on WhatsApp, Facebook Messenger or Instagram, this page explains how to have everything we hold about you permanently deleted.</p>

            <div class="note">Deletion is permanent and cannot be undone. Conversations removed this way cannot be recovered by you, by us, or by the business you were messaging.</div>

            <h2 id="who">1. Who this page is for</h2>
            <p>Two different situations, with two different routes:</p>
            <ul>
                <li><strong>You messaged a business</strong> that uses {{ $brand }} to answer its WhatsApp, Messenger or Instagram — use the steps below.</li>
                <li><strong>You have a {{ $brand }} account</strong> for your own business — deleting your account from <em>Profile → Delete account</em> removes your workspace and all of its data. This page is not the route for that.</li>
            </ul>

            <h2 id="how">2. How to request deletion</h2>

            <h3>Option A — through Facebook or Instagram</h3>
            <p>If you messaged the business on Messenger or Instagram, you can trigger deletion from Meta directly, and we are notified automatically:</p>
            <ol>
                <li>Open <strong>Settings &amp; privacy → Settings</strong> in the Facebook or Instagram app.</li>
                <li>Go to <strong>Apps and websites</strong>.</li>
                <li>Find <strong>{{ $brand }}</strong> in the list and choose <strong>Remove</strong>.</li>
                <li>When asked, confirm that you also want your data deleted.</li>
            </ol>
            <p>Meta sends us the request immediately and gives you a confirmation code plus a link where you can check the status at any time.</p>

            <h3>Option B — email us</h3>
            <p>Email <a href="mailto:{{ $email }}?subject=Data%20deletion%20request">{{ $email }}</a> with the subject <strong>“Data deletion request”</strong>, and tell us:</p>
            <ul>
                <li>which platform you used — WhatsApp, Messenger or Instagram;</li>
                <li>the phone number or account name you messaged from;</li>
                <li>the name of the business you were messaging, if you remember it.</li>
            </ul>
            <p>We reply with a confirmation once the deletion is complete. If we cannot match your details to any data, we will tell you that too rather than leaving you guessing.</p>

            <h2 id="what">3. What gets deleted</h2>
            <p>Everything we hold that is about you personally:</p>
            <ul>
                <li>every conversation between you and the business, on every channel;</li>
                <li>every message in those conversations, including voice notes, images and files;</li>
                <li>your display name and profile photo, wherever we cached them;</li>
                <li>any lead or contact record created from your conversations.</li>
            </ul>

            <h3>What we keep, and why</h3>
            <p>One thing survives: a record that a deletion request was made and completed. It contains the date, the platform, and the anonymous account identifier the platform gave us — no messages, no name, no photo, nothing readable about you.</p>
            <p>We keep it so that we can prove the deletion actually happened if you or a regulator ever asks. Deleting that record too would make the promise on this page impossible to verify.</p>
            <p>Separately, the business you messaged may hold its own copy of information you gave it — an order, a booking, an invoice — in its own systems. We cannot delete that on their behalf. If that applies, contact the business directly.</p>

            <h2 id="how-long">4. How long it takes</h2>
            <p>Requests are processed automatically and normally complete within a few minutes. Our commitment is <strong>within 30 days</strong> in all cases, which is the limit set by data-protection law.</p>
            <p>If you used Option A, the status link Meta gave you shows the outcome as soon as it is done.</p>

            <h2 id="contact">5. Questions</h2>
            <p>If anything here is unclear, or a request has not completed, email <a href="mailto:{{ $email }}">{{ $email }}</a> and a person will look at it.</p>
            <p>See also our <a href="{{ url('/privacy') }}">Privacy Policy</a>, which covers what we collect and why.</p>
        </div>
    </div>
</section>
@endsection
