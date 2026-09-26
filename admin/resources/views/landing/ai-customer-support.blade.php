@php
    $brand = tva_setting('content.brand_name', 'serveAI');

    // Also the target for "AI customer service", "AI support agent" and
    // "customer support automation" — same intent, one page. See the note on
    // `landing_pages` in config/site.php.
    $page = [
        'path' => '/ai-customer-support',

        'intro' => [
            'AI customer support means letting an AI agent handle the questions your team answers over and over — opening hours, prices, stock, order status, bookings — so that people spend their time on the conversations that need judgement. Some vendors call it AI customer service or a support agent; the job is the same.',
            $brand . ' is AI customer support software built around that split. You connect the information your team already relies on: your website, documents, spreadsheets, a live MySQL database or a CRM. An AI agent then answers customers on phone calls, web chat, WhatsApp, Instagram and Facebook, replying in the language the customer writes or speaks in. When a question needs a person, the conversation moves to your team in a shared inbox with the full history attached, so nobody asks the customer to start again.',
            'It suits businesses that get the same questions every day and cannot staff every channel around the clock: online shops, clinics and salons, service companies, property agencies and B2B teams. If most of your enquiries could be answered from a page you have already written, most of them can be answered by an agent.',
        ],

        'stepsTitle' => 'How AI customer support works in ' . $brand,
        'steps' => [
            ['Connect what your team knows', 'Crawl your website, upload PDFs, Word, Excel or CSV files, or connect a database or CRM. The agent answers from this, not from general internet knowledge.'],
            ['Choose where customers reach you', 'Turn on the web chat widget, a phone number, WhatsApp, Instagram and Facebook Messenger. One agent serves all of them with the same knowledge.'],
            ['Decide when a person takes over', 'Pause the AI on any conversation and reply yourself, assign it to a teammate, or build a flow that routes certain requests straight to a human.'],
            ['Read the transcripts and improve', 'Every call and chat is transcribed. Find the questions it could not answer, add the missing information, and watch the handoff rate fall.'],
        ],

        'featuresTitle' => 'What the agent can do',
        'featuresLead'  => 'Answering questions is the start. The useful part is what happens around the answer.',
        'features' => [
            ['Answers from your own information', 'When relevant reference data is found, the agent is instructed to answer only from it — and to say it does not know rather than guess when the fact is not there.'],
            ['Human handoff that keeps context', 'Conversations move to a shared inbox where your team can claim, transfer and resolve them, then hand them back to the AI when they are done.'],
            ['One agent on every channel', 'Calls, web chat, WhatsApp, Instagram DMs and Facebook Messenger all reach the same agent, and your team sees them in one place.'],
            ['Actions, not just answers', 'Skills let the agent check an order, look up a customer, check availability, book an appointment or open a support ticket in your own system through a webhook.'],
            ['Replies in the customer’s language', 'The agent mirrors the language of the customer’s latest message, including Urdu, so one setup serves a mixed audience.'],
            ['Control over what the AI can see', 'Each project gets its own isolated database, and you choose table by table and column by column what the AI may read.'],
        ],

        'casesTitle' => 'Where it earns its keep',
        'cases' => [
            ['Online shops', '“Is this in stock?”, “how much is delivery?” and “where is my order?” — answered from your product data, or from your order system through an order-status skill.'],
            ['Clinics and salons', 'Opening hours, prices and availability answered at any hour, with booking handled through your own scheduling system or handed to reception.'],
            ['Service businesses', 'After-hours enquiries captured with the job details, so the first call the next morning is to a customer who is already qualified.'],
            ['B2B and software teams', 'First-line product questions answered from your documentation, with anything account-specific turned into a support ticket for the team.'],
        ],

        'fit' => [
            'title' => 'What AI customer support is good at — and what it is not',
            'html'  => '<p>It is very good at high-volume questions with a clear, documented answer. It is not the right tool for complaints that need discretion, refunds that need judgement, or anything where the correct answer depends on information you have not given it.</p>'
                     . '<p>The deployments that work start narrow: pick the three most common, lowest-risk request types, get those right, measure, then widen. The ones that fail tend to switch everything on at once with out-of-date information behind it. We wrote up the common failure patterns, and the checks that catch them, in <a href="' . url('/blog/why-ai-support-projects-fail') . '">why AI support projects fail</a>.</p>'
                     . '<p>If the difference between a simple chatbot and an agent that takes actions matters for your decision, <a href="' . url('/blog/ai-agents-vs-chatbots-vs-assistants') . '">this comparison</a> lays it out without the vendor spin.</p>',
        ],

        'faqs' => [
            ['What is AI customer support?', 'AI customer support uses an AI agent to answer customer questions automatically — on chat, phone, WhatsApp and social messaging — using your own business information, and to pass conversations to a person when they need one.'],
            ['Is AI customer service the same as a chatbot?', 'Not quite. A traditional chatbot follows scripted menus. An AI customer service agent understands free-form questions, answers from your content, and can take actions such as checking an order or opening a ticket. ' . $brand . ' can be set up either way.'],
            ['Will the AI make up answers about my business?', 'When relevant information is found in your connected sources, the agent is instructed to answer only from it and to say it does not have the information otherwise. Keeping those sources current is what keeps the answers correct, and every conversation is transcribed so you can check.'],
            ['How does handing over to a human work?', 'Your team sees every conversation in a shared inbox. Anyone can pause the AI on a conversation and reply directly, assign it to a colleague, and resume the AI afterwards. Flows can also route specific requests straight to a person.'],
            ['Can it look up orders or bookings?', 'Yes, through skills. Ready-made templates cover order status, customer lookup, availability, booking and ticket creation; each one calls an endpoint in your own system, so someone on your side needs to expose that endpoint.'],
            ['How much does AI customer support cost?', 'You can start free with no credit card. Paid plans are priced by conversations, channels and phone minutes — see the pricing page for current figures in your currency.'],
        ],

        'related'   => ['/omnichannel-customer-support', '/ai-agents', '/ai-voice-agent'],
        'ctaTitle'  => 'See it answer your own customers’ questions',
        'ctaBody'   => 'Connect your website, ask it what your customers ask, and judge the answers yourself. Free to start, no card.',
    ];
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'AI customer support',
    'pageTitle'       => 'AI customer support that resolves the routine and <span class="accent">escalates the rest.</span>',
    'pageSubtitle'    => 'Give customers an accurate answer at any hour, on any channel — and give your team the conversations that genuinely need a person.',
    'seoTitle'        => 'AI Customer Support Software for Every Channel | ' . $brand,
    'metaDescription' => 'AI customer support that answers routine questions on calls, chat, WhatsApp, Instagram and Facebook from your own data — and hands the rest to your team.',
    'breadcrumbs'     => [['name' => 'AI customer support', 'url' => '/ai-customer-support']],
    'jsonLd'          => [\App\Support\Schema::software(), \App\Support\Schema::faqPage('/ai-customer-support', $page['faqs'])],
])

@section('content')
    @include('landing._body')
@endsection
