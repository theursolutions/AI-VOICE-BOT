@php
    $brand = tva_setting('content.brand_name', 'serveAI');

    $page = [
        'path' => '/ai-agents',

        'intro' => [
            'An AI agent is software that decides what to do next rather than following a fixed script. A chatbot matches a question to an answer. An agent reads the conversation, works out what the customer needs, and can take a step to get it done — look something up, ask a qualifying question, book a slot, or bring in a person.',
            'In ' . $brand . ', an agent is a customer-facing persona you configure: its name and instructions, its voice, the channels it answers on and the skills it is allowed to use. You can run several side by side — one for sales, one for support, one for billing — and route each phone number, skill or flow to the right one.',
            'AI agents for business are worth it when conversations lead somewhere: a qualified lead, a booking, an order, a ticket. If every enquiry ends with “here is the answer”, a simpler setup will do, and we will tell you so.',
        ],

        'stepsTitle' => 'Building an agent',
        'steps' => [
            ['Define who it is', 'Give the agent a persona and instructions, pick a voice for calls, and choose the channels it should answer on.'],
            ['Give it skills', 'Attach tools from the skills library — customer lookup, order status, booking, ticket creation — each calling an endpoint in your own system.'],
            ['Map the conversation', 'Describe the conversation you want and the AI drafts a flow for you, or build it yourself on a drag-and-drop canvas. Test it before it goes live.'],
            ['Route and review', 'Send a phone number, a skill or a flow to the agent that should handle it, then read transcripts to see where it succeeds and where it hands off.'],
        ],

        'featuresTitle' => 'What makes it an agent',
        'features' => [
            ['Several agents, one workspace', 'Run separate sales, support and billing personas, each with its own voice, instructions, skills and channels.'],
            ['A skills library', 'Ten ready-made templates: customer lookup, order status, create order, book appointment, check availability, create support ticket, create or update a CRM contact, send a notification, and generic GET and POST requests.'],
            ['AI-drafted flows', 'Write a brief in plain language and get a working conversation flow you can edit visually, rather than starting from a blank canvas.'],
            ['Lead capture and scoring', 'Names, emails, phone numbers and intent are extracted during the conversation, and each contact gets a 0–100 score labelled hot, warm or cold.'],
            ['Your CRM, connected', 'Leads land in the built-in CRM. HubSpot, Salesforce, Pipedrive and Zoho can be connected as data sources, and a webhook skill can push contacts back to your own system.'],
            ['Your choice of model', 'Higher plans can choose the AI provider behind the agent — including OpenAI, Anthropic, Google Gemini, Groq and DeepSeek — or run a private model through Ollama.'],
        ],

        'casesTitle' => 'Jobs agents do well',
        'cases' => [
            ['Lead qualification', 'Ask the questions that separate a serious buyer from a browser, score the answers, and route hot leads to sales while they are still interested.'],
            ['Appointment booking', 'Check availability and book through your scheduling system, or collect preferred times and hand over when the calendar is not connected.'],
            ['Order handling', 'Tell customers where their order is, or take a new order, by calling your store or order system through a webhook.'],
            ['Support triage', 'Answer what it can, and turn what it cannot into a well-described support ticket instead of an angry follow-up.'],
        ],

        'fit' => [
            'title' => 'When you need an agent — and when a chatbot is enough',
            'html'  => '<p>Agents are more capable and more work. Every skill that calls your system needs an endpoint on your side, and every flow needs testing. That effort pays off when conversations lead to an outcome you care about — a qualified lead, a booking, an order.</p>'
                     . '<p>If your customers mostly want information, start with a <a href="' . url('/ai-chatbot') . '">website AI chatbot</a> that answers from your content and add skills later. For the full breakdown of chatbots, assistants and agents, read <a href="' . url('/blog/ai-agents-vs-chatbots-vs-assistants') . '">what is actually different</a>; for designing qualification that filters instead of adding work, see <a href="' . url('/blog/ai-lead-qualification-workflow') . '">building a lead qualification workflow</a>.</p>',
        ],

        'faqs' => [
            ['What is an AI agent for business?', 'An AI agent is a customer-facing AI that understands a conversation, decides what to do next and can take actions — such as looking up an order, booking an appointment or qualifying a lead — rather than only returning pre-written answers.'],
            ['How is an AI agent different from a chatbot?', 'A chatbot answers questions. An agent can also act: it calls tools in your systems, follows multi-step flows and decides when to involve a person. Many products described as agents are chatbots with a new label, so ask what actions it can take.'],
            ['Can the agents connect to my own systems?', 'Yes. Skills call HTTP endpoints you provide, so anything that can expose an API — a store, a booking system, a CRM, a custom database — can be connected. Someone on your side needs to set up that endpoint.'],
            ['Can I run more than one agent?', 'Yes. You can create separate agents for sales, support and billing, each with its own voice, instructions, skills and channels, and route phone numbers, skills or flows to each. How many you can run depends on your plan.'],
            ['Which AI models power the agents?', 'By default ' . $brand . ' manages the model for you. On higher plans you can choose a provider such as OpenAI, Anthropic, Google Gemini, Groq or DeepSeek, or run a private model through Ollama.'],
            ['Do I need a developer to build an agent?', 'Not for the agent itself: personas, voices, flows and channels are set up in the dashboard. You only need technical help to expose an endpoint when you want a skill to act inside one of your own systems.'],
        ],

        'related'  => ['/ai-customer-support', '/ai-voice-agent', '/whatsapp-ai-chatbot'],
        'ctaTitle' => 'Build your first agent',
        'ctaBody'  => 'Start with one agent answering from your website, then give it skills as you go. Free to start, no card.',
    ];
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'AI agents',
    'pageTitle'       => 'AI agents that <span class="accent">do the work</span>, not just the talking.',
    'pageSubtitle'    => 'Customer-facing AI agents that qualify leads, run guided flows, call your systems and hand off to your team — on voice, chat and messaging.',
    'seoTitle'        => 'AI Agents for Business That Take Action | ' . $brand,
    'metaDescription' => 'Build AI agents for your business that qualify leads, run guided flows, call your systems through webhooks and update your CRM — on voice, chat and WhatsApp.',
    'breadcrumbs'     => [['name' => 'AI agents', 'url' => '/ai-agents']],
    'jsonLd'          => [\App\Support\Schema::software(), \App\Support\Schema::faqPage('/ai-agents', $page['faqs'])],
])

@section('content')
    @include('landing._body')
@endsection
