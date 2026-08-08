@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $email = tva_setting('content.contact_email', 'info@serveai.com.pk');
    $effective = 'June 28, 2026';
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Legal',
    'pageTitle'       => 'Terms of Service',
    'pageSubtitle'    => 'The agreement between you and ' . $brand . ' — written to be read, not skipped.',
    'pageMeta'        => 'Last updated: ' . $effective,
    'seoTitle'        => 'Terms of Service — ' . $brand,
    'metaDescription' => 'The agreement between you and ' . $brand . ': what the service covers, your account responsibilities, acceptable use, billing, and how either side can end it.',
    'breadcrumbs'     => [['name' => 'Terms of Service', 'url' => '/terms']],
])

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <p class="lead">These Terms of Service ("Terms") govern your access to and use of <strong>{{ $brand }}</strong> (the "Service"). By creating an account or using the Service, you agree to these Terms. If you're agreeing on behalf of a company, you confirm you're authorised to do so.</p>

            <h2 id="service">1. The Service</h2>
            <p>{{ $brand }} provides AI-powered voice, chat, and messaging agents, a shared inbox, lead capture, and related CRM tools. We may add, change, or remove features over time to improve the Service.</p>

            <h2 id="accounts">2. Your account</h2>
            <ul>
                <li>You must provide accurate information and keep your login credentials secure.</li>
                <li>You're responsible for all activity under your account and for your team members' use.</li>
                <li>You must be old enough to form a binding contract in your country.</li>
            </ul>

            <h2 id="acceptable-use">3. Acceptable use</h2>
            <p>You agree not to use the Service to:</p>
            <ul>
                <li>Break the law, or send spam, scams, or unlawful, hateful, or deceptive content.</li>
                <li>Place calls or messages without the consent required by the rules of your jurisdiction.</li>
                <li>Infringe anyone's intellectual property or privacy rights.</li>
                <li>Attempt to disrupt, reverse-engineer, or gain unauthorised access to the Service.</li>
                <li>Misrepresent an AI agent as a human where the law requires disclosure.</li>
            </ul>
            <p>You are responsible for the content and data you connect, and for ensuring you have the right to use it.</p>

            <h2 id="your-content">4. Your content and data</h2>
            <p>You keep ownership of the data you provide and the conversations your agents generate. You grant us a limited licence to process that data solely to provide the Service. We process it in line with our <a href="{{ url('/privacy') }}">Privacy Policy</a>.</p>

            <h2 id="ai-disclaimer">5. AI output</h2>
            <p>The Service uses AI to generate responses. AI can make mistakes. While the Service answers from the data you connect, you are responsible for reviewing how your agents are configured and for any reliance placed on their output. The Service is a tool to assist your business, not a substitute for professional advice.</p>

            <h2 id="third-party">6. Third-party services</h2>
            <p>The Service connects to third parties you choose (telephony, messaging platforms, AI providers, payment processors). Your use of those is subject to their terms, and we're not responsible for them.</p>

            <h2 id="billing">7. Plans, billing, and trials</h2>
            <ul>
                <li>Paid plans are billed in advance on a recurring basis until cancelled.</li>
                <li>Usage-based charges (such as calls or messages) are billed as incurred.</li>
                <li>You can cancel anytime; cancellation stops future renewals.</li>
                <li>Refunds, where they apply, are governed by our <a href="{{ url('/refund-policy') }}">Refund Policy</a>.</li>
                <li>Fees exclude taxes unless stated; you're responsible for applicable taxes.</li>
            </ul>

            <h2 id="termination">8. Suspension and termination</h2>
            <p>You may close your account at any time. We may suspend or terminate access if you breach these Terms, fail to pay, or use the Service in a way that risks harm to others or to the platform. On termination, you can export your data for a reasonable period before it is removed.</p>

            <h2 id="warranty">9. Disclaimers</h2>
            <p>The Service is provided "as is" and "as available". To the fullest extent permitted by law, we disclaim implied warranties of merchantability, fitness for a particular purpose, and non-infringement. We don't guarantee the Service will be uninterrupted, error-free, or that AI output will be accurate.</p>

            <h2 id="liability">10. Limitation of liability</h2>
            <p>To the fullest extent permitted by law, {{ $brand }} will not be liable for indirect, incidental, special, or consequential damages, or for lost profits or data. Our total liability for any claim is limited to the amount you paid us for the Service in the 12 months before the claim.</p>

            <h2 id="indemnity">11. Indemnity</h2>
            <p>You agree to defend and indemnify us against claims arising from your content, your use of the Service, or your breach of these Terms or the law.</p>

            <h2 id="changes">12. Changes to these Terms</h2>
            <p>We may update these Terms from time to time. If we make material changes, we'll notify you through the Service. Continuing to use the Service after changes take effect means you accept them.</p>

            <h2 id="governing-law">13. Governing law</h2>
            <p>These Terms are governed by the laws of the jurisdiction in which {{ $brand }} is established, without regard to conflict-of-law rules. Courts located there will have exclusive jurisdiction, unless mandatory local law provides otherwise.</p>

            <h2 id="contact">14. Contact</h2>
            <p>Questions about these Terms? Email <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>

            <div class="note">This document is a general template provided for convenience and is not legal advice. Please have it reviewed by a qualified professional and tailored to your jurisdiction before relying on it.</div>
        </div>
    </div>
</section>
@endsection
