@php
    $brand = tva_setting('content.brand_name', 'serveAI');

    $page = [
        'path' => '/ai-chatbot',

        'intro' => [
            'An AI chatbot for business sits on your website and answers visitors in plain language. The older kind worked from decision trees — pick an option, get a canned reply — and broke the moment someone typed a real question. An AI chatbot reads the question as written and answers it from your own content.',
            $brand . '’s chat widget goes on any website with one snippet. It answers from the pages, documents and data you connect, captures names and contact details as the conversation happens, and lets visitors talk to it with their voice as well as type. When a visitor needs a person, your team takes over the same conversation from the shared inbox.',
            'It is the fastest way to start with ' . $brand . ': the free plan covers web chat, so you can test it on your own site with no card before adding phone or messaging channels.',
        ],

        'stepsTitle' => 'Adding an AI chatbot to your website',
        'steps' => [
            ['Give it your content', 'Crawl your website, upload documents, or connect a data source. This is what the chatbot answers from.'],
            ['Make it look like yours', 'Set the colours, bot name, welcome message, position, opening hours and an optional FAQ tab.'],
            ['Paste one snippet', 'Add the embed code to your site — any platform that lets you add a script works — and restrict it to your own domains.'],
            ['Watch the conversations', 'See every chat in the inbox, step in when you want to, and review transcripts to fill the gaps.'],
        ],

        'featuresTitle' => 'More than a chat box',
        'features' => [
            ['Answers from your content', 'Replies are drawn from the information you connect, so visitors get your prices and policies, not generic internet answers.'],
            ['Talks and listens', 'Visitors can press the microphone and speak, and choose to hear replies read aloud in the agent’s voice.'],
            ['A language picker', 'Visitors can choose English, Arabic, Urdu, Hindi, Spanish, French or Chinese, and the chatbot mirrors whichever language they write in.'],
            ['Leads without a form', 'Names, emails, phone numbers and intent mentioned in the chat are captured into the CRM and scored.'],
            ['Your brand, your rules', 'Colours, name, logo, greetings, opening hours and a busy message are all yours; higher plans can remove the “Powered by” line.'],
            ['A person when it matters', 'Your team can take over any conversation live from the shared inbox and hand it back to the AI afterwards.'],
        ],

        'casesTitle' => 'What website visitors use it for',
        'cases' => [
            ['Questions before buying', 'Delivery times, sizes, compatibility and prices answered on the page where the visitor is deciding.'],
            ['Fewer repeat support emails', 'Common “how do I…” questions answered instantly, so the inbox holds the problems that need a person.'],
            ['Enquiries for service businesses', 'Collect what the job is, where and when, then pass a complete enquiry to your team.'],
            ['Multilingual visitors', 'Serve visitors in their own language without maintaining a translated copy of every page.'],
        ],

        'fit' => [
            'title' => 'What a website chatbot cannot fix',
            'html'  => '<p>A chatbot is only as good as what you give it. If your website says one thing and your price list says another, it will find both. Before launch, read the pages it will read and remove what is out of date — that single step does more for answer quality than any setting.</p>'
                     . '<p>Also be clear about what you want from it. If you need it to book appointments, check orders or qualify leads against criteria, you want an <a href="' . url('/ai-agents') . '">AI agent with skills</a> rather than a chatbot that only answers. Our guide to <a href="' . url('/blog/ai-agents-vs-chatbots-vs-assistants') . '">chatbots vs assistants vs agents</a> explains the difference.</p>',
        ],

        'faqs' => [
            ['How do I add the AI chatbot to my website?', 'Copy the embed snippet from the dashboard and paste it into your site’s HTML. It works on any platform that lets you add a script, including WordPress and hosted store themes, and you can restrict it to your own domains.'],
            ['Can visitors talk to the chatbot instead of typing?', 'Yes. When voice is enabled, visitors can press the microphone and speak, and can choose to have replies read aloud.'],
            ['Can I try it for free?', 'Yes. The free plan includes web chat for 7 days with no credit card, so you can test it on your own website before choosing a paid plan.'],
            ['What is the difference between an AI chatbot and an AI agent?', 'An AI chatbot answers questions from your content. An AI agent can also take actions — check an order, book a slot, open a ticket — through skills that call your systems. ' . $brand . ' starts as the first and grows into the second.'],
            ['Can a person take over the chat?', 'Yes. Every conversation appears in the shared inbox, where your team can pause the AI, reply directly, and hand the conversation back when they are done.'],
            ['Which languages does the chatbot support?', 'The chatbot replies in the language the visitor writes in. The widget’s language picker offers English, Arabic, Urdu, Hindi, Spanish, French and Chinese.'],
        ],

        'related'  => ['/ai-customer-support', '/ai-agents', '/whatsapp-ai-chatbot'],
        'ctaTitle' => 'Put it on your website today',
        'ctaBody'  => 'Crawl your site, paste one snippet, and ask it the questions your customers ask. Free for 7 days, no card.',
    ];
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'AI chatbot',
    'pageTitle'       => 'An AI chatbot for your website that <span class="accent">knows your business.</span>',
    'pageSubtitle'    => 'One snippet puts an AI chat widget on your site that answers from your own content, listens as well as types, and captures leads as it goes.',
    'seoTitle'        => 'AI Chatbot for Your Website & Business | ' . $brand,
    'metaDescription' => 'Add an AI chatbot to your website with one snippet. It answers from your own content, talks as well as types, captures leads and hands off to your team.',
    'breadcrumbs'     => [['name' => 'AI chatbot', 'url' => '/ai-chatbot']],
    'jsonLd'          => [\App\Support\Schema::software(), \App\Support\Schema::faqPage('/ai-chatbot', $page['faqs'])],
])

@section('content')
    @include('landing._body')
@endsection
