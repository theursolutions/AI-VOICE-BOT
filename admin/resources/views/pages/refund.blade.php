@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $email = tva_setting('content.contact_email', 'hello@serveai.com');
    $effective = 'June 28, 2026';
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Legal',
    'pageTitle'       => 'Refund &amp; Return Policy',
    'pageSubtitle'    => 'When you can get your money back, and how to ask. No fine-print games.',
    'pageMeta'        => 'Last updated: ' . $effective,
    'metaDescription' => 'Refund and cancellation policy for ' . $brand . ' subscriptions and usage charges.',
])

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <p class="lead"><strong>{{ $brand }}</strong> is a software subscription service. Because it's digital and delivered instantly, this policy explains exactly when refunds apply so there are no surprises.</p>

            <div class="note">Short version: start free, so you can try before you pay. New paid subscriptions are covered by a 14-day money-back guarantee. Usage charges (like calls and messages already delivered) and renewals after the guarantee window are generally non-refundable.</div>

            <h2 id="free-trial">1. Try before you buy</h2>
            <p>You can use {{ $brand }} on a free plan with no credit card required. We encourage you to test the Service thoroughly before upgrading to a paid plan, so you know it fits your business.</p>

            <h2 id="guarantee">2. 14-day money-back guarantee</h2>
            <p>If you're not satisfied with your <strong>first</strong> paid subscription, contact us within <strong>14 days</strong> of the initial charge and we'll refund that subscription fee. The guarantee applies once per customer and covers the base subscription fee only.</p>

            <h2 id="usage">3. Usage-based charges</h2>
            <p>Charges for services already delivered — such as phone minutes, voice generation, and messages sent through connected channels — are <strong>non-refundable</strong>, because real third-party costs are incurred the moment they're used.</p>

            <h2 id="renewals">4. Renewals</h2>
            <p>Subscriptions renew automatically so your Service isn't interrupted. Renewal charges are non-refundable. To avoid a renewal, cancel before your billing date (see below). If a renewal charges you by accident within 48 hours and the Service hasn't been used in the new period, contact us — we'll do our best to help.</p>

            <h2 id="cancel">5. How to cancel</h2>
            <p>You can cancel anytime from your dashboard under billing settings, or by emailing <a href="mailto:{{ $email }}">{{ $email }}</a>. Cancellation stops future renewals. You keep access until the end of the period you've already paid for, and you can export your data before it ends.</p>

            <h2 id="how-to-request">6. How to request a refund</h2>
            <p>Email <a href="mailto:{{ $email }}">{{ $email }}</a> from the address on your account with your workspace name and the reason for the request. We aim to respond within 2 business days and to process approved refunds within 5–10 business days to your original payment method.</p>

            <h2 id="exceptions">7. Exceptions</h2>
            <ul>
                <li>Accounts suspended or terminated for breaching our <a href="{{ url('/terms') }}">Terms of Service</a> are not eligible for refunds.</li>
                <li>Custom, enterprise, or annual prepaid plans may have their own terms set out in a separate agreement.</li>
                <li>Nothing here limits rights you may have under mandatory consumer-protection laws in your country.</li>
            </ul>

            <h2 id="returns">8. "Returns"</h2>
            <p>{{ $brand }} is a digital service, so there's nothing physical to return. Closing or downgrading your account is the equivalent of a return — your access ends and no further charges are made.</p>

            <h2 id="contact">9. Contact</h2>
            <p>Need help with billing or a refund? Email <a href="mailto:{{ $email }}">{{ $email }}</a> and a real person will get back to you.</p>

            <div class="note">This document is a general template provided for convenience and is not legal advice. Please tailor the timeframes and terms to your business and local consumer law before relying on it.</div>
        </div>
    </div>
</section>
@endsection
