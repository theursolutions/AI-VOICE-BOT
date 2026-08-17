# Serve AI — Bot knowledge: Frequently asked questions

Upload this file under Data Sources so the agent can answer questions about
Serve AI itself. Bot Strategy only chooses which strategies are active; the
knowledge itself lives in Data Sources. Run Sync after uploading.

The first six entries are the FAQ already published on the marketing site, kept
word-for-word so the bot and the page can never contradict each other. Everything
after that covers questions the site does not answer.

---

## Getting started

**How long does setup actually take?**
Most teams are live in under five minutes. Connect a data source, pick a voice,
drop the chat widget on your site — and your agent starts answering. Connecting
a phone number or WhatsApp takes a few minutes more.

**Do I need any technical skills or developers?**
No. Everything — data sources, voices, flows, channels and team access — is
configured from a point-and-click dashboard. If you can fill in a form, you can
launch an agent.

**What are the actual steps to go live?**
Four. Drop your data (paste a URL, upload a CSV, or connect your database).
Pick a voice. Connect a number, or skip it if you only want chat. Then embed the
chat widget on your site and watch the dashboard fill with leads.

**How do I add the chat widget to my website?**
Copy the snippet from Widget Settings and paste it just before the closing
`</body>` tag on your site. It is one `<script>` tag carrying your project key,
and it works on any platform that lets you edit HTML — WordPress, Shopify,
Webflow, or a hand-built site.

**Can I try it before paying?**
Yes. Start free, with no credit card. You get a free window to evaluate it
properly, and you can upgrade whenever you are ready.

---

## Data and answers

**Where does the agent get its answers?**
Only from the data you give it: your website, your documents, and your
databases. It does not make things up about your business — and you control
exactly which data it can see.

**What kinds of data can I connect?**
A website URL to crawl, uploaded documents and spreadsheets, and direct database
connections. Uploaded tables become queryable, so the agent can answer questions
about the contents rather than only quoting text.

**What happens when I update my website or documents?**
Re-run the sync from Data Sources and the agent picks up the new content. It
answers from the most recent version it has indexed, so a stale answer usually
means a sync is due.

**What if the agent does not know something?**
It says so rather than guessing. You can then add the missing information as a
data source, or write a flow that hands the conversation to a human.

**Can I control which data it is allowed to read?**
Yes. Access is set per data source, and for databases you choose which tables
and columns are visible. Anything you do not grant, the agent cannot see.

---

## Channels

**Which channels can the agent handle?**
Website chat, WhatsApp, Instagram, Facebook Messenger, and inbound phone calls.
The same agent — your data, your voice, your rules — answers on all of them, and
every conversation lands in one shared inbox.

**Do I need a separate setup for each channel?**
No. You connect each channel once, and the agent's knowledge and personality
carry across all of them. You can route different channels to different agents
if you want them to behave differently.

**How does WhatsApp work?**
Through the official WhatsApp Cloud API, so it is a real business number with
templates, media, and flows — not an unofficial workaround. WhatsApp allows free
replies for 24 hours after a customer's message; outside that window you reply
with an approved template.

**Can I use my existing phone number?**
Yes. Bring a Twilio number you already own, or get one through us. Each number
can be routed to a specific agent, skill, or conversation flow.

**Can a human take over a conversation?**
Yes. Any conversation can be paused for the AI and handled by a person from the
inbox, then handed back. You can also transfer conversations between team
members and set a status so nothing is dropped.

---

## Voice

**Can it really sound like me?**
A 10-second sample is enough to clone your voice, or you can pick from 30+
ready-made voices in 13 languages. Different agents can use different voices for
sales, support, or billing.

**Can it speak more than one language?**
Yes. It replies in the language the customer writes or speaks in, and voices are
available across 13 languages.

**Can customers send voice notes?**
Yes, on the messaging channels that support them. Voice notes are transcribed so
the conversation stays searchable, and you can choose whether replies come back
as text or audio.

---

## Leads and CRM

**What happens to the leads it collects?**
They are captured during the conversation and appear in your dashboard
immediately — no export step, no copy-paste. Each lead keeps a link back to the
conversation it came from.

**How do I work through my leads?**
The leads page has a pipeline board and a table view. The board groups leads by
stage so you can drag them from new through qualified to converted; the table is
there when you want to filter, search, or export.

**Does it recognise a returning customer?**
Yes. Contacts are matched across channels by phone number and email, so the same
person messaging on WhatsApp and later on the website is recognised as one
contact with a single history.

**Can I export my data?**
Yes. Every table in the product has an export button that downloads a CSV of
what you are looking at, including your current filters.

---

## Team and access

**Can I add my team?**
Yes. Invite teammates and give each a role. Roles control which modules a member
can open, so a support agent can be given the inbox without billing or settings.

**Can different agents handle different things?**
Yes. You can run several agents with different knowledge, voices and
personalities — for example one for sales and one for support — and route
channels or phone numbers to whichever should answer.

**What is a flow, and do I need one?**
A flow is a guided conversation: qualify, route, book, collect. You do not need
one to start — the agent answers from your data out of the box. Add a flow when
you want a specific path followed every time. You can describe the flow you want
and have it drafted for you, or build it yourself on a drag-and-drop canvas.

---

## Privacy, security and billing

**Is my customer data safe?**
Yes. Every workspace is isolated in its own database, you choose which tables
and columns the AI may read, and every conversation is logged and exportable.
You can also bring your own AI keys or run models locally.

**Who can see my conversations?**
Only people you invite to your workspace, and only the parts their role allows.
Workspaces are separated at the database level, so one customer's data is never
in the same place as another's.

**Can I delete my data?**
Yes. You can request deletion of your data at any time, and there is a documented
process for the messaging channels that require one.

**What does it cost, and can I cancel?**
Start free — no credit card required. Upgrade when you're ready, cancel anytime,
and take your data with you. No long-term contracts, no lock-in.

---

## Deciding whether it fits

**How is this different from a normal chatbot?**
A scripted chatbot only knows the buttons someone built for it. This answers
from your own content in your own words, on every channel, and hands over to a
person when it should. It also captures the lead while it is talking, rather
than leaving you a transcript to read later.

**Will it work for my industry?**
It is not tied to one. The agent knows whatever you connect — a property list, a
menu, a price sheet, a policy document — so the question is whether your answers
exist somewhere written down, not what sector you are in.

**How many conversations can it handle at once?**
Chat and messaging scale with demand, so a busy hour does not queue customers
behind each other. Phone calls are more resource-intensive; if you expect many
simultaneous calls, tell us your expected peak and we will confirm what your
plan supports.

**Can I test it before customers see it?**
Yes. Open the live preview in Widget Settings and talk to your agent exactly as
a visitor would. Nothing is public until you paste the script tag on your site.

**Can I run it on more than one website or brand?**
Yes. A workspace can hold several projects, each with its own data, agents,
voices, channels and widget. They stay separate, so one brand's content never
leaks into another's answers.

---

## Controlling how it behaves

**Can I change what it says and how it sounds?**
Yes. You set the bot's name, its welcome title and message, the input
placeholder, and the personality it answers with. Tone is part of the agent's
configuration, so a support agent can be warm and a billing agent brisk.

**Can I stop it discussing certain topics?**
Yes. The agent answers from the data you give it, so the simplest control is
what you connect. Beyond that, its instructions can tell it what to refuse and
what to hand to a person instead.

**What if it gives a wrong answer?**
Correct the source it came from and re-sync. Because answers trace back to your
data rather than to a model's general knowledge, a wrong answer is nearly always
a wrong or missing document — which makes it fixable rather than mysterious.

**Can I control what the widget shows?**
Yes. Voice input, emoji, attachments, the light/dark switch, the expand button,
the history tab and the "powered by" line can each be turned on or off, and you
choose which corner it sits in.

**Can I match it to my brand?**
Yes. Set the primary and accent colours, upload your logo, and choose an avatar.
You can also restrict which domains are allowed to embed the widget, so nobody
else can drop your agent on their site.

**Can I tell it my opening hours?**
Yes. Opening hours are part of the widget's settings, so it can answer honestly
about when a human is around instead of implying someone is always there.

---

## Handover, volume and etiquette

**What if a customer just wants a human?**
Asking for one is enough — phrases like "talk to a human" are recognised in
English, Urdu and roman Urdu, and the conversation is flagged for a person. The
agent also steps back on its own if a conversation goes several turns without
getting anywhere, rather than looping.

**Will it keep messaging someone who wants to be left alone?**
No. Opt-out words such as "stop" or "unsubscribe" are recognised and the agent
stops. Opting back in works the same way. This matters on WhatsApp in
particular, where being reported hurts your number's standing.

**Does the customer know they are talking to AI?**
That is your choice, and we would encourage being upfront. The bot's name and
welcome message are yours to write, and the "powered by" line can stay or go.

**Will my WhatsApp number get blocked?**
Not from normal use. Risk comes from messaging people who did not ask to hear
from you and from being reported. The opt-out handling, the 24-hour reply
window, and template rules all exist to keep you on the right side of that.

---

## Reporting

**What can I see about how it is performing?**
The dashboard shows conversations, total messages, leads captured, conversion
rate and voice replies. Every conversation is readable in full, and every list —
leads, conversations, contacts — can be filtered and exported to CSV.

**Can I see where a lead came from?**
Yes. Each lead links back to the conversation that produced it, including the
channel, so you can see exactly what was said before someone became a lead.

---

## Troubleshooting

**The widget is not appearing on my site.**
Check the script tag is present just before `</body>` and that the project key
matches the one in Widget Settings. If your site is HTTPS, the widget must be
loaded over HTTPS too.

**The agent is answering with general knowledge instead of my information.**
Its data sources are probably empty or out of date. Open Data Sources and run a
sync, then ask again.

**It answered something out of date.**
Re-sync the data source. The agent answers from the last indexed version, so
content changed on your site since the last crawl will not be reflected yet.

**A conversation needs a person.**
Open it in the inbox, pause the AI, and reply yourself. The customer sees one
continuous conversation.

**The widget looks wrong on my site.**
It renders in an isolated frame, so your site's CSS cannot affect it and it
cannot affect yours. If the colours look off, check the primary and accent
colours in Widget Settings — those, not your stylesheet, are what it follows.

**It is answering in the wrong language.**
It replies in the language the customer used. If that is not happening, the
customer's opening message was probably ambiguous — a longer first message
usually settles it.

**I replied on WhatsApp and the customer never got it.**
WhatsApp only allows free-form replies within 24 hours of the customer's last
message. Outside that window the reply must use an approved template; the inbox
will tell you when you are past it.

**Nothing has come through from a channel I connected.**
Re-check the connection in Channels. A channel can look connected while its
subscription to incoming messages has lapsed, and reconnecting restores it.
