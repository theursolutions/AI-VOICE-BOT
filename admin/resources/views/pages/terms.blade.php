@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $email = tva_setting('content.contact_email', 'info@serveai.com.pk');
    $phone = tva_setting('content.contact_phone', '+92 349 149 4383');
    $addr  = tva_setting('content.contact_address', '');

    // The registered business name is the legal entity behind the brand, and
    // it is not decoration: Safepay (and card-scheme rules generally) require
    // the merchant's registered name to appear on the terms page and to match
    // their record exactly. Edit it in /admin/content → Legal & Business
    // Identity, never here.
    $legal  = tva_setting('content.legal_entity', 'The UR Solutions');
    // Registered office and trading address are usually the same one office;
    // both fall back to the contact address so that stays a single edit.
    $office = tva_setting('content.legal_office', '') ?: $addr;
    $place  = tva_setting('content.legal_place', '') ?: $addr;
    $courts = tva_setting('content.legal_jurisdiction', 'Lahore');

    $tel = $phone ? preg_replace('/[^\d+]/', '', $phone) : '';

    $effective = 'September 11, 2026';
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Legal',
    'pageTitle'       => 'Terms &amp; Conditions',
    'pageSubtitle'    => 'The agreement between you and ' . $legal . ', the company behind ' . $brand . ' — written to be read, not skipped.',
    'pageMeta'        => 'Last updated: ' . $effective,
    'seoTitle'        => 'Terms & Conditions — ' . $brand,
    'metaDescription' => 'The agreement between you and ' . $legal . ', operator of ' . $brand . ': what the service covers, your account responsibilities, acceptable use, billing, liability, and the law that governs it.',
    'breadcrumbs'     => [['name' => 'Terms & Conditions', 'url' => '/terms']],
])

{{-- The identity block is a record, not a data table: the label column is
     fixed so the values line up, and it sits on the panel tint so a payment
     reviewer can find the registered name without reading the prose. --}}
@push('head')
<style>
    .prose .legal-id { margin: 18px 0 22px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel-2); overflow: hidden; }
    .prose .legal-id th, .prose .legal-id td { padding: 11px 16px; border-bottom: 1px solid var(--line); }
    .prose .legal-id tr:last-child th, .prose .legal-id tr:last-child td { border-bottom: none; }
    .prose .legal-id th { width: 34%; font-weight: 600; color: var(--text-dim2); font-size: 13px; }
    .prose .legal-id td { color: var(--text); }
    @media (max-width: 560px) {
        .prose .legal-id, .prose .legal-id tbody, .prose .legal-id tr, .prose .legal-id th, .prose .legal-id td { display: block; width: auto; }
        .prose .legal-id th { border-bottom: none; padding-bottom: 0; }
        .prose .legal-id td { padding-top: 4px; }
    }
</style>
@endpush

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <p class="lead">These Terms &amp; Conditions ("Terms") govern your access to and use of <strong>{{ $brand }}</strong> (the "Service"), which is owned and operated by <strong>{{ $legal }}</strong> ("we", "us", "our"). By creating an account, placing an order, or using the Service, you agree to these Terms. If you're agreeing on behalf of a company, you confirm you're authorised to do so.</p>

            <h2 id="who-we-are">1. Who you are contracting with</h2>
            <p>{{ $brand }} is a trading name of <strong>{{ $legal }}</strong>, the registered business that provides the Service, issues your invoices, and receives your payments.</p>
            <table class="legal-id">
                <tbody>
                    <tr>
                        <th scope="row">Registered business name</th>
                        <td><strong>{{ $legal }}</strong></td>
                    </tr>
                    <tr>
                        <th scope="row">Trading as</th>
                        <td>{{ $brand }}</td>
                    </tr>
                    {{-- One office is the usual case, and printing the same
                         address under two labels reads like an unfilled
                         template. Both facts are still stated — just once. --}}
                    @if ($office && $office === $place)
                        <tr>
                            <th scope="row">Registered office and principal place of business</th>
                            <td>{{ $office }}</td>
                        </tr>
                    @else
                        @if ($office)
                            <tr>
                                <th scope="row">Registered office</th>
                                <td>{{ $office }}</td>
                            </tr>
                        @endif
                        @if ($place)
                            <tr>
                                <th scope="row">Principal place of business</th>
                                <td>{{ $place }}</td>
                            </tr>
                        @endif
                    @endif
                    <tr>
                        <th scope="row">Email</th>
                        <td><a href="mailto:{{ $email }}">{{ $email }}</a></td>
                    </tr>
                    @if ($phone)
                        <tr>
                            <th scope="row">Phone</th>
                            <td><a href="tel:{{ $tel }}">{{ $phone }}</a></td>
                        </tr>
                    @endif
                </tbody>
            </table>
            <p>If you have any problem placing an order on our website, or you need support after placing one, call us on the number above or email <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>

            <h2 id="acceptance">2. Acceptance and updates</h2>
            <p>By visiting our site or purchasing from us, you engage in our Service and agree to be bound by these Terms, including any additional terms and policies referenced here or linked from this page. They apply to every user of the site — browsers, customers, vendors, and contributors of content alike.</p>
            <p>You represent that you are of legal age to form a binding contract and that you are not barred from receiving our products and services under the laws of Pakistan or any other jurisdiction that applies to you.</p>
            <p>We may update these Terms from time to time. Each time you place an order you agree to the version then published, and the "last updated" date above tells you when it changed. If we make material changes, we'll notify you through the Service.</p>

            <h2 id="service">3. The Service</h2>
            <p>{{ $brand }} provides AI-powered voice, chat, and messaging agents, a shared inbox, lead capture, and related CRM tools. We may add, change, or remove features over time to improve the Service.</p>

            <h2 id="accounts">4. Your account</h2>
            <ul>
                <li>You must provide accurate information and keep your login credentials secure.</li>
                <li>You're responsible for all activity under your account and for your team members' use.</li>
                <li>You must be old enough to form a binding contract in your country.</li>
            </ul>

            <h2 id="acceptable-use">5. Acceptable use</h2>
            <p>You are prohibited from using this website or the Service:</p>
            <ul>
                <li>For any unlawful, obscene, or immoral purpose, or to solicit others to take part in unlawful acts.</li>
                <li>To violate any international, federal, provincial, or state laws, regulations, and rules.</li>
                <li>To send spam, scams, or deceptive content, or to place calls or messages without the consent required where you operate.</li>
                <li>To harass, abuse, insult, harm, defame, slander, disparage, intimidate, or discriminate on the basis of gender, sexual orientation, religion, ethnicity, race, age, national origin, or disability.</li>
                <li>To submit false or misleading information.</li>
                <li>To infringe our intellectual property or privacy rights, or those of anyone else.</li>
                <li>To upload or transmit viruses or any malicious code that could affect the operation of the Service, or interfere with or circumvent its security features or those of any related website.</li>
                <li>To collect or track other people's personal information, or to spam, phish, pharm, pretext, spider, crawl, or scrape.</li>
                <li>To disrupt, reverse-engineer, or gain unauthorised access to the Service.</li>
                <li>To misrepresent an AI agent as a human where the law requires disclosure.</li>
            </ul>
            <p>We reserve the right to terminate your use of the Service or any related website for breaching any of these prohibited uses. You are responsible for the content and data you connect, and for ensuring you have the right to use it.</p>

            <h2 id="your-content">6. Your content and data</h2>
            <p>You keep ownership of the data you provide and the conversations your agents generate. You grant us a limited licence to process that data solely to provide the Service. We process it in line with our <a href="{{ url('/privacy') }}">Privacy Policy</a>.</p>

            <h2 id="ai-disclaimer">7. AI output</h2>
            <p>The Service uses AI to generate responses. AI can make mistakes. While the Service answers from the data you connect, you are responsible for reviewing how your agents are configured and for any reliance placed on their output. The Service is a tool to assist your business, not a substitute for professional advice.</p>

            <h2 id="third-party">8. Third-party services</h2>
            <p>The Service connects to third parties you choose (telephony, messaging platforms, AI providers, payment processors). Your use of those is subject to their terms, and we're not responsible for them.</p>

            <h2 id="ip">9. Intellectual property</h2>
            <p>This website and the Service, together with their related software and content (including images and designs), are the intellectual property of and are exclusively owned by <strong>{{ $legal }}</strong>. The structure, organisation, and code of the website and its related software contain valuable trade secrets and confidential information of {{ $legal }}. Except as expressly stated in these Terms, nothing here grants you any intellectual property rights in the website or its related software, and all rights are reserved by {{ $legal }}.</p>

            <h2 id="billing">10. Plans, billing, and trials</h2>
            <ul>
                <li>Paid plans are billed in advance on a recurring basis until cancelled.</li>
                <li>Usage-based charges (such as calls or messages) are billed as incurred.</li>
                <li>You can cancel anytime; cancellation stops future renewals.</li>
                <li>Refunds, where they apply, are governed by our <a href="{{ url('/refund-policy') }}">Refund Policy</a>.</li>
                <li>Fees exclude taxes unless stated; you're responsible for applicable taxes.</li>
            </ul>
            <p>Payments are handled by our payment partners, and we never store your full card number.</p>

            <h2 id="orders">11. Orders we may not process</h2>
            <p>We reserve the right not to process an order you place on our website. This is usually because:</p>
            <ul>
                <li>The service you ordered is no longer available, or we no longer offer it on the plan you chose.</li>
                <li>We're unable to provide the service in your location — for example, a phone number or channel we can't supply there.</li>
                <li>The order fails a payment, fraud, or sanctions check.</li>
                <li>Any reason outside of our control.</li>
            </ul>
            <p>If we don't process an order, we won't charge you for it, and we'll return anything already taken for it.</p>

            <h2 id="termination">12. Suspension and termination</h2>
            <p>You may close your account at any time. We may change or terminate your access to the Service, this website, or any membership with us, with or without notice and without liability to you or any third party, if you have: (1) given us false or misleading registration information; (2) interfered with other users or with the administration of the Service; (3) been the subject of a request from law enforcement or another governmental authority; (4) failed to pay; or (5) otherwise breached these Terms. On termination, you can export your data for a reasonable period before it is removed.</p>

            <h2 id="warranty">13. Disclaimers</h2>
            <p>The Service is provided "as is" and "as available". Neither we nor any third party gives any warranty or guarantee as to the accuracy, timeliness, performance, completeness, or suitability of the information and materials found or offered on this website for any particular purpose. You acknowledge that such information and materials may contain inaccuracies or errors, and we exclude liability for them to the fullest extent permitted by law.</p>
            <p>Your use of any information or materials on this website is entirely at your own risk, and it is your responsibility to ensure that any services or information available through it meet your requirements. To the extent permitted by law, we also disclaim all warranties, express or implied, including the implied warranties of merchantability, fitness for a particular purpose, title, and non-infringement. We don't guarantee that the Service will be uninterrupted or error-free, or that AI output will be accurate.</p>

            <h2 id="liability">14. Limitation of liability</h2>
            <p>To the fullest extent permitted by law, {{ $legal }} will not be liable for indirect, incidental, special, or consequential damages, or for lost profits or data. Our total liability for any claim is limited to the amount you paid us for the Service in the 12 months before the claim.</p>

            <h2 id="indemnity">15. Indemnity</h2>
            <p>You agree to indemnify, defend, and hold harmless {{ $legal }} and our parent, subsidiaries, affiliates, partners, officers, directors, agents, contractors, licensors, service providers, subcontractors, suppliers, interns, and employees from any claim or demand, including reasonable attorneys' fees, made by a third party arising out of your content, your use of the Service, your breach of these Terms or the documents they incorporate by reference, or your violation of any law or the rights of a third party.</p>

            <h2 id="severability">16. Severability and waiver</h2>
            <p>If any portion of these Terms is found unenforceable, that portion will be amended to the minimum extent necessary to make it enforceable; if it can't be made enforceable, it will be severed and the rest will remain in full force and effect. If we fail to enforce any of these Terms, that is not a waiver. Any amendment to or waiver of these Terms must be made in writing and signed by us.</p>

            <h2 id="governing-law">17. Governing law</h2>
            <p>These Terms are governed by the laws of the Islamic Republic of Pakistan, and you agree that the courts of {{ $courts }} (including any consumer court) will have exclusive jurisdiction over any dispute you have with us, unless mandatory local law provides otherwise.</p>

            <h2 id="contact">18. Contact</h2>
            <p>Questions about these Terms? Email <a href="mailto:{{ $email }}">{{ $email }}</a>@if ($phone) or call <a href="tel:{{ $tel }}">{{ $phone }}</a>@endif.</p>

            <div class="note">This document is a general template provided for convenience and is not legal advice. Please have it reviewed by a qualified professional and tailored to your jurisdiction before relying on it.</div>
        </div>
    </div>
</section>
@endsection
