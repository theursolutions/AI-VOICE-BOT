@php
    $brand = tva_setting('content.brand_name', 'serveAI');

    $page = [
        'path' => '/whatsapp-ai-chatbot',

        'intro' => [
            'For many businesses — in Pakistan especially — WhatsApp is where customers actually get in touch. They message at night, at weekends and in the middle of your busiest hour, and they expect a reply in minutes. A WhatsApp AI chatbot answers those messages instantly, from your own information, and passes the conversations that need a person to your team.',
            $brand . ' connects to WhatsApp through Meta’s official WhatsApp Business Platform (the Cloud API), not an unofficial workaround. You connect your number through Meta’s own signup from the dashboard — or scan a QR code to finish on your phone — and the same AI agent that runs your website chat starts replying on WhatsApp, with templates, media and the 24-hour messaging window handled for you.',
            'Every WhatsApp conversation lands in the shared inbox alongside your other channels, so your team can step in, reply as themselves and hand the chat back to the AI.',
        ],

        'stepsTitle' => 'Setting up a WhatsApp AI chatbot',
        'steps' => [
            ['Connect through Meta', 'Start Meta’s embedded signup from the Channels page, or scan the QR code to finish it on the phone that holds your WhatsApp.'],
            ['Point it at your information', 'The agent answers WhatsApp messages from the same website, documents and data sources as your other channels.'],
            ['Prepare your templates', 'Create and manage WhatsApp message templates for the conversations you start or continue after the 24-hour window.'],
            ['Go live with your team behind it', 'Customers get instant replies; your team sees every chat in the inbox and can take over at any point.'],
        ],

        'featuresTitle' => 'Built on the official WhatsApp Business Platform',
        'features' => [
            ['Official Cloud API', 'Messages go through Meta’s WhatsApp Business Platform — the sanctioned route for business messaging, not a scraped web session.'],
            ['The 24-hour window, handled', 'The agent replies freely inside WhatsApp’s 24-hour customer service window; once it closes, the inbox offers an approved template to reopen the conversation.'],
            ['Templates you manage', 'Create message templates, submit them for Meta’s approval and see which are available, without leaving the dashboard.'],
            ['Media, buttons and flows', 'Send and receive images, documents and audio, use interactive reply buttons, WhatsApp Flows, and product messages from your catalog.'],
            ['Your team in the same chat', 'Pause the AI and reply as a person from the shared inbox, then hand the conversation back when you are done.'],
            ['Leads from every chat', 'Names, numbers and intent from WhatsApp conversations are captured into the CRM and scored like every other channel.'],
        ],

        'casesTitle' => 'What businesses use it for',
        'cases' => [
            ['Order and delivery questions', '“Has my order shipped?” answered on the spot — from your data, or from your order system through a skill.'],
            ['Bookings and appointments', 'Collect the customer’s details and preferred time, or book directly when your scheduling system is connected.'],
            ['Enquiries from your ads', 'When an ad or a link opens a WhatsApp chat, the first reply is instant instead of whenever someone checks the phone.'],
            ['Evenings and weekends', 'Customers who message after hours get an answer, and your team starts the day with the details already collected.'],
        ],

        'fit' => [
            'title' => 'The rules WhatsApp sets — and how to work within them',
            'html'  => '<p>WhatsApp is strict about business messaging, and any tool that tells you otherwise is putting your number at risk. Inside the 24-hour window after a customer messages you, replies are free-form. Outside it, you can only send a template that Meta has approved. Customers need to have agreed to hear from you, and Meta charges for certain messages directly, separately from your ' . $brand . ' plan.</p>'
                     . '<p>Working within those rules is what keeps a number healthy. Use the AI for fast, useful replies to people who wrote to you first, keep templates for genuine updates, and never use it for unsolicited bulk messages.</p>',
        ],

        'faqs' => [
            ['Is this the official WhatsApp Business API?', 'Yes. ' . $brand . ' uses Meta’s WhatsApp Business Platform through the Cloud API, and numbers are connected through Meta’s own embedded signup.'],
            ['Will using an AI chatbot get my WhatsApp number banned?', 'The official API is Meta’s approved route for business messaging. Numbers get restricted for breaking WhatsApp’s policies — such as messaging people who did not opt in or sending spam — not for replying with AI. Follow the policies and the channel is safe to use.'],
            ['What is the WhatsApp 24-hour window?', 'After a customer messages you, you have 24 hours to reply with any message. After that, you can only start or continue the conversation with a template message approved by Meta. ' . $brand . ' prompts you to use a template once the window has closed.'],
            ['Can I use my existing WhatsApp number?', 'You register the number during Meta’s signup. If the number is already in use on WhatsApp, Meta’s signup explains the options for moving it to the Business Platform before you confirm.'],
            ['Does Meta charge for WhatsApp messages?', 'Meta charges for some WhatsApp Business messages, such as templates, and bills that directly under its own pricing. That is separate from your ' . $brand . ' subscription.'],
            ['Can my team reply on WhatsApp too?', 'Yes. WhatsApp chats appear in the shared inbox, where anyone on your team can pause the AI, reply, and hand the conversation back.'],
        ],

        'related'  => ['/omnichannel-customer-support', '/ai-customer-support', '/ai-chatbot'],
        'ctaTitle' => 'Answer your WhatsApp in seconds, not hours',
        'ctaBody'  => 'Set up your agent on your own data first, then connect WhatsApp through Meta when you are ready.',
    ];
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'WhatsApp AI chatbot',
    'pageTitle'       => 'A WhatsApp AI chatbot on the <span class="accent">official Business API.</span>',
    'pageSubtitle'    => 'Reply to WhatsApp customers instantly from your own information — with templates, media, the 24-hour window and your team built in.',
    'seoTitle'        => 'WhatsApp AI Chatbot on the Official Business API | ' . $brand,
    'metaDescription' => 'Answer WhatsApp customers instantly with an AI chatbot on the official WhatsApp Business Platform, with templates, media, the 24-hour window and human handoff.',
    'breadcrumbs'     => [['name' => 'WhatsApp AI chatbot', 'url' => '/whatsapp-ai-chatbot']],
    'jsonLd'          => [\App\Support\Schema::software(), \App\Support\Schema::faqPage('/whatsapp-ai-chatbot', $page['faqs'])],
])

@section('content')
    @include('landing._body')
@endsection
