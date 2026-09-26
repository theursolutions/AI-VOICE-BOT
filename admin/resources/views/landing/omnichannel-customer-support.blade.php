@php
    $brand = tva_setting('content.brand_name', 'serveAI');

    $page = [
        'path' => '/omnichannel-customer-support',

        'intro' => [
            'Multichannel support means being reachable in several places. Omnichannel customer support means those places behave like one: the same answers, the same rules and the same team, whichever channel the customer picked. The difference shows up the moment a customer who asked on Instagram calls to follow up.',
            $brand . ' brings phone calls, website chat, WhatsApp, Instagram DMs, Facebook Messenger and email into one shared inbox. A single AI agent answers first on every channel, from the same knowledge, and your team works every conversation from the same screen — claiming, transferring, replying and resolving without switching apps.',
            'It is built for small teams covering more channels than they have people, and for businesses whose customers do not care which channel they used last time — they just expect you to know.',
        ],

        'stepsTitle' => 'How it fits together',
        'steps' => [
            ['Connect your channels', 'Add a phone number, the website widget, WhatsApp, Instagram, Facebook Messenger and a mailbox — whichever your customers use.'],
            ['One agent answers first', 'The same AI agent replies on every channel with the same knowledge, in the customer’s own language.'],
            ['Your team works one inbox', 'Claim a conversation, transfer it to a colleague, reply as yourself, and mark it resolved — whatever channel it came from.'],
            ['Every conversation is kept', 'Transcripts, captured contact details and lead scores build up on the contact record, so the next conversation starts with context.'],
        ],

        'featuresTitle' => 'One inbox, every channel',
        'features' => [
            ['Six channels in one place', 'Phone calls, web chat, WhatsApp, Instagram DMs, Facebook Messenger and email, all in the same shared inbox.'],
            ['Claim, transfer, resolve', 'See who is online, claim a conversation so two people never reply at once, transfer it to the right person, and resolve it when done.'],
            ['Pause the AI per conversation', 'Take over one chat without switching off the AI everywhere, then hand it back when you are finished.'],
            ['Contact records with scores', 'Details captured in conversation are kept on the contact, with a 0–100 score marking who is hot, warm or cold.'],
            ['Roles and permissions', 'Custom roles decide who can work the inbox, who can change the agent, and who can see billing, project by project.'],
            ['Consistent answers everywhere', 'Every channel draws on the same connected knowledge, so a customer never gets one price on WhatsApp and another on the phone.'],
        ],

        'casesTitle' => 'Who needs omnichannel support',
        'cases' => [
            ['Small teams, many channels', 'Two people cannot watch six apps. One inbox with the AI answering first means nothing is left unread.'],
            ['Businesses running social ads', 'Ads drive messages to Instagram, Facebook and WhatsApp at once; every one gets an instant first reply.'],
            ['Several brands or locations', 'Separate projects keep each brand’s channels, knowledge and team apart under one account.'],
            ['Round-the-clock coverage', 'The AI covers nights and weekends across every channel; your team picks up the conversations that need them in the morning.'],
        ],

        'fit' => [
            'title' => 'What “omnichannel” should mean when you buy it',
            'html'  => '<p>Plenty of tools call a list of integrations “omnichannel”. The test is simpler: does your team answer every channel from one place, and does the customer get the same answer whichever channel they use? If agents still switch between apps, or each channel runs its own bot with its own knowledge, it is multichannel with a new label.</p>'
                     . '<p>To be precise about what ' . $brand . ' covers: Instagram and Facebook support is for direct messages and Messenger conversations — replying to public comments is not included. For the channel customers in Pakistan use most, see the <a href="' . url('/whatsapp-ai-chatbot') . '">WhatsApp AI chatbot</a> page; for phone calls, the <a href="' . url('/ai-voice-agent') . '">AI voice agent</a>.</p>',
        ],

        'faqs' => [
            ['What is omnichannel customer support?', 'Omnichannel customer support connects every channel a customer might use — phone, chat, messaging, social and email — so the business answers them consistently from one place, instead of running each channel separately.'],
            ['Which channels does ' . $brand . ' include?', 'Phone calls through Twilio, the website chat widget, WhatsApp through Meta’s Business Platform, Instagram direct messages, Facebook Messenger and email. How many social channels you can connect depends on your plan.'],
            ['Can my team reply from the same inbox?', 'Yes. Every conversation from every channel appears in one shared inbox, where your team can claim, reply, transfer and resolve it, and pause or resume the AI for that conversation.'],
            ['Does the AI answer the same way on every channel?', 'Yes. One agent draws on the same connected knowledge for every channel, so answers stay consistent. You can still run separate agents for different purposes if you want to.'],
            ['Can it reply to Instagram or Facebook comments?', 'No. Instagram and Facebook support covers direct messages and Messenger conversations. Replies to public comments are not included.'],
            ['What is the difference between omnichannel and multichannel support?', 'Multichannel means you are available on several channels. Omnichannel means those channels share one inbox, one set of answers and one view of the customer, so switching channel does not mean starting again.'],
        ],

        'related'  => ['/ai-customer-support', '/whatsapp-ai-chatbot', '/ai-voice-agent'],
        'ctaTitle' => 'Bring every channel into one inbox',
        'ctaBody'  => 'Start with web chat, add channels as you go, and see every conversation in one place. Free to start, no card.',
    ];
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Omnichannel support',
    'pageTitle'       => 'Omnichannel customer support in <span class="accent">one inbox.</span>',
    'pageSubtitle'    => 'Calls, web chat, WhatsApp, Instagram, Messenger and email — answered first by one AI agent, and worked by your team from one screen.',
    'seoTitle'        => 'Omnichannel Customer Support Software with AI | ' . $brand,
    'metaDescription' => 'One inbox for phone calls, web chat, WhatsApp, Instagram, Messenger and email — with an AI agent answering first and your team taking over in one click.',
    'breadcrumbs'     => [['name' => 'Omnichannel customer support', 'url' => '/omnichannel-customer-support']],
    'jsonLd'          => [\App\Support\Schema::software(), \App\Support\Schema::faqPage('/omnichannel-customer-support', $page['faqs'])],
])

@section('content')
    @include('landing._body')
@endsection
