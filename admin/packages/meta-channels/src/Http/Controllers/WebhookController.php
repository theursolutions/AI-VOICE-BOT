<?php

namespace Msd\MetaChannels\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Contracts\HandlesInboundCall;
use Msd\MetaChannels\Jobs\ProcessInboundMessage;
use Msd\MetaChannels\Jobs\ProcessMessageStatus;
use Msd\MetaChannels\MetaManager;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\GraphClient;
use Msd\MetaChannels\Support\InboundCall;
use Msd\MetaChannels\Support\InboundMessage;

/**
 * Single Meta webhook for WhatsApp. Handles BOTH messaging (value.messages)
 * and calling (value.calls) — Meta delivers both on the same callback URL.
 */
class WebhookController
{
    public function __construct(private MetaManager $meta) {}

    /** Verification handshake (Meta calls this once when you save the URL). */
    public function verify(Request $request): Response
    {
        $expected  = $this->meta->verifyToken();
        // PHP rewrites dotted query keys: hub.mode → hub_mode.
        $mode      = (string) $request->input('hub_mode');
        $token     = (string) $request->input('hub_verify_token');
        $challenge = (string) $request->input('hub_challenge');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }
        Log::warning('MetaChannels webhook verify failed', ['mode' => $mode]);
        return response('Forbidden', 403);
    }

    public function webhook(Request $request): JsonResponse
    {
        if (!$this->meta->signatureValid($request->getContent(), (string) $request->header('X-Hub-Signature-256', ''))) {
            // Name the object and the secret count. A bare "invalid signature"
            // cannot distinguish a forged request from a genuine delivery signed
            // by an app whose secret we do not hold — which is what happens when
            // Instagram Login is configured and INSTAGRAM_APP_SECRET is missing
            // or stale. The two need opposite responses, and the object tells
            // you which app should have signed it.
            Log::warning('MetaChannels webhook: invalid signature', [
                'object'        => (string) data_get($request->json()->all(), 'object'),
                'secrets_tried' => count(\Msd\MetaChannels\Support\SignedRequest::secrets()),
                'has_header'    => $request->header('X-Hub-Signature-256') !== null,
            ]);
            return response()->json(['error' => 'invalid signature'], 403);
        }
        if (config('meta.whatsapp.app_secret') === null || config('meta.whatsapp.app_secret') === '') {
            Log::notice('MetaChannels webhook: no app_secret — signature check skipped.');
        }

        $payload = $request->json()->all();
        $object  = (string) data_get($payload, 'object');

        // Log every accepted delivery, not just failures.
        //
        // This controller previously logged only when something went wrong,
        // which made the most common support question — "is Meta actually
        // calling us?" — unanswerable from the logs: a silent log looked
        // identical whether a message had been processed perfectly or the
        // webhook had never been subscribed. One line at info level makes
        // that distinction visible.
        Log::info('MetaChannels webhook received', [
            'object'  => $object,
            'entries' => count((array) data_get($payload, 'entry', [])),
        ]);

        foreach (data_get($payload, 'entry', []) as $entry) {
            if ($object === 'whatsapp_business_account') {
                $this->handleWhatsappEntry($entry);
            } elseif ($object === 'instagram') {
                $this->handleMessengerEntry($entry, ChannelConnection::PROVIDER_INSTAGRAM);
            } elseif ($object === 'page') {
                $this->handleMessengerEntry($entry, ChannelConnection::PROVIDER_FACEBOOK_PAGE);
            }
        }

        return response()->json(['received' => true]);
    }

    /** WhatsApp Cloud API: entry[].changes[].value.{messages|calls}. */
    private function handleWhatsappEntry(array $entry): void
    {
        foreach (data_get($entry, 'changes', []) as $change) {
            $value = $change['value'] ?? [];
            $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id');
            if ($phoneNumberId === '') {
                continue;
            }
            $conn = $this->meta->resolveWhatsappConnection($phoneNumberId);
            if (!$conn) {
                Log::warning('MetaChannels: inbound for unknown/disabled number', ['phone_number_id' => $phoneNumberId]);
                continue;
            }
            if (!empty($value['calls'])) {
                $this->handleCalls($value['calls'], $phoneNumberId, $conn);
            }
            if (!empty($value['messages'])) {
                $this->handleMessages($value, $phoneNumberId, $conn);
            }
            // Delivery receipts. Meta sends these on the same webhook as
            // messages but in their own array, which we were dropping
            // entirely — hence no sent/delivered/read ticks anywhere.
            if (!empty($value['statuses'])) {
                $this->handleStatuses($value['statuses'], $conn);
            }
        }
    }

    /**
     * Messenger Platform (Facebook Page + Instagram): entry[].messaging[].
     * Each event: {sender:{id PSID/IGSID}, recipient:{id page/ig}, message:{mid, text, is_echo?}}.
     */
    private function handleMessengerEntry(array $entry, string $provider): void
    {
        foreach (data_get($entry, 'messaging', []) as $event) {
            // Skip echoes of our own sends, delivery/read receipts, reactions.
            if (data_get($event, 'message.is_echo')) {
                continue;
            }
            $text = (string) data_get($event, 'message.text', '');
            $attachments = [];
            foreach (data_get($event, 'message.attachments', []) as $att) {
                $url = (string) data_get($att, 'payload.url', '');
                if ($url === '') {
                    continue;
                }
                $t = (string) ($att['type'] ?? 'file');
                $attachments[] = [
                    'type' => $t === 'file' ? 'document' : $t,  // image|audio|video|document
                    'url'  => $url,
                ];
            }
            if (trim($text) === '' && empty($attachments)) {
                continue; // delivery/read receipt, reaction, etc.
            }

            $mid = (string) data_get($event, 'message.mid', '');
            if ($mid !== '' && !Cache::add('meta:msg:' . $mid, 1, now()->addHours(6))) {
                continue;
            }

            $psid  = (string) data_get($event, 'sender.id', '');
            $bizId = (string) (data_get($event, 'recipient.id') ?: ($entry['id'] ?? ''));
            if ($psid === '' || $bizId === '') {
                continue;
            }

            $conn = $this->meta->resolveConnection($provider, $bizId);
            if (!$conn) {
                Log::warning('MetaChannels: inbound for unknown/disabled channel', [
                    'provider' => $provider, 'biz_id' => $bizId,
                ]);
                continue;
            }

            // WhatsApp puts the sender's name in the payload; Messenger and
            // Instagram send only an opaque PSID/IGSID, so without this the
            // inbox shows a 16-digit number and whoever is answering has no
            // idea who they are talking to.
            //
            // Cached for a day and keyed on the id: profiles change rarely,
            // and a Graph call on every inbound message would add latency to
            // the reply for no benefit.
            $profile = $this->senderProfile($conn, $psid, $provider);

            ProcessInboundMessage::dispatch(new InboundMessage(
                projectId:         (int) $conn->project_id,
                provider:          $provider,
                channelExternalId: $bizId,
                from:              $psid,
                senderName:        $profile['name'] ?? null,
                text:              $text,
                messageId:         $mid ?: null,
                accessToken:       $conn->access_token ?: null,
                profilePic:        $profile['profile_pic'] ?? null,
                attachments:       $attachments,
                graphBase:         GraphClient::baseFor($conn->metadata),
                // Messenger and Instagram put the quoted message id here.
                replyToExternalId: (string) data_get($event, 'message.reply_to.mid') ?: null,
            ));
        }
    }

    /**
     * The sender's display name and photo.
     *
     * WhatsApp puts the name in the payload; Messenger and Instagram send
     * only an opaque PSID/IGSID, so without this the inbox shows a 16-digit
     * number and whoever is answering has no idea who they are talking to.
     *
     * Success is cached for a day — profiles change rarely, and a Graph call
     * per inbound message would add latency to every reply.
     *
     * FAILURE IS CACHED FOR TEN MINUTES, and the difference matters. Caching
     * a failed lookup for a day means that the moment App Review grants
     * pages_messaging — or the customer's rate limit clears — the inbox
     * still shows numbers for another 24 hours, and the operator has no way
     * to tell a permissions problem from a stale cache. The short window lets
     * it heal on its own.
     *
     * @return array{name:?string, profile_pic:?string}
     */
    private function senderProfile(ChannelConnection $conn, string $psid, string $provider): array
    {
        $key = 'meta:profile:' . $provider . ':' . $psid;

        if (($hit = Cache::get($key)) !== null) {
            return $hit;
        }

        try {
            $profile = GraphClient::forConnection($conn)->messengerProfile($psid, $provider);
        } catch (\Throwable $e) {
            $profile = ['name' => null, 'profile_pic' => null];
            Log::info('MetaChannels: profile lookup threw', [
                'provider' => $provider, 'error' => $e->getMessage(),
            ]);
        }

        $resolved = ! empty($profile['name']) || ! empty($profile['profile_pic']);

        if (! $resolved) {
            // Named explicitly, because the usual cause is a permission the
            // app does not hold yet rather than anything wrong in our code —
            // and the symptom ("no customer names") points nowhere useful.
            Log::info('MetaChannels: no profile returned for sender', [
                'provider' => $provider,
                'hint'     => $provider === ChannelConnection::PROVIDER_INSTAGRAM
                    ? 'needs instagram_business_manage_messages + the customer must have messaged this account'
                    : 'needs pages_messaging (Advanced Access) on the Page token, and the customer must have messaged this Page',
            ]);
        }

        Cache::put($key, $profile, $resolved ? now()->addDay() : now()->addMinutes(10));

        return $profile;
    }

    /**
     * Delivery receipts: sent → delivered → read (or failed).
     *
     * Each entry carries the wamid of a message WE sent, so this is how the
     * ticks in the inbox get filled in. Handed to the host app through the
     * same contract as messages, because resolving a wamid to a stored
     * message means touching the tenant database, which this package
     * deliberately knows nothing about.
     *
     * Statuses arrive out of order and repeat — `delivered` can land after
     * `read` on a slow connection — so the handler ranks them rather than
     * overwriting blindly.
     */
    private function handleStatuses(array $statuses, ChannelConnection $conn): void
    {
        foreach ($statuses as $s) {
            $wamid  = (string) ($s['id'] ?? '');
            $status = (string) ($s['status'] ?? '');

            if ($wamid === '' || $status === '') {
                continue;
            }

            ProcessMessageStatus::dispatch(
                projectId: (int) $conn->project_id,
                wamid:     $wamid,
                status:    $status,
                timestamp: (int) ($s['timestamp'] ?? time()),
                // Present only on `failed`. Worth carrying: "message
                // undeliverable" with no reason is unactionable, and the
                // reason is usually something the operator can fix.
                error:     (string) (data_get($s, 'errors.0.title') ?: ''),
            );
        }
    }

    private function handleMessages(array $value, string $phoneNumberId, ChannelConnection $conn): void
    {
        $names = [];
        foreach (data_get($value, 'contacts', []) as $c) {
            $names[(string) data_get($c, 'wa_id')] = data_get($c, 'profile.name');
        }

        foreach ($value['messages'] as $msg) {
            $waId = (string) ($msg['id'] ?? '');
            if ($waId !== '' && !Cache::add('meta:msg:' . $waId, 1, now()->addHours(6))) {
                continue; // dedup re-deliveries
            }
            $from = (string) ($msg['from'] ?? '');
            if ($from === '') {
                continue;
            }

            [$text, $attachments] = $this->parseWhatsAppContent((string) ($msg['type'] ?? ''), $msg);
            if (trim($text) === '' && empty($attachments)) {
                continue; // unsupported type (location, reaction, etc.)
            }

            ProcessInboundMessage::dispatch(new InboundMessage(
                projectId:         (int) $conn->project_id,
                provider:          ChannelConnection::PROVIDER_WHATSAPP,
                channelExternalId: $phoneNumberId,
                from:              $from,
                senderName:        $names[$from] ?? null,
                text:              $text,
                messageId:         $waId ?: null,
                accessToken:       $conn->access_token ?: null,
                attachments:       $attachments,
                // The wamid of the message this one quotes. Present whenever
                // the customer used WhatsApp's reply-swipe; dropping it meant
                // a reply to "which size?" arrived as a bare "large" with no
                // way to tell what it answered.
                replyToExternalId: (string) data_get($msg, 'context.id') ?: null,
            ));
        }
    }

    /**
     * Split a WhatsApp message into [text, attachments]. Media messages
     * carry a media id we resolve+download later; their caption (if any)
     * becomes the text.
     *
     * @return array{0:string, 1:array}
     */
    private function parseWhatsAppContent(string $type, array $msg): array
    {
        if ($type === 'text') {
            return [(string) data_get($msg, 'text.body', ''), []];
        }
        $media = ['image', 'audio', 'voice', 'video', 'document', 'sticker'];
        if (in_array($type, $media, true)) {
            $node = $msg[$type] ?? [];
            return [
                (string) ($node['caption'] ?? ''),
                [[
                    'type'     => $type === 'voice' ? 'audio' : $type,
                    'media_id' => $node['id'] ?? null,
                    'mime'     => $node['mime_type'] ?? null,
                    'filename' => $node['filename'] ?? null,
                    'caption'  => $node['caption'] ?? null,
                ]],
            ];
        }

        // Replies to interactive messages we sent: button taps, list picks,
        // and WhatsApp Flow form submissions. Surfacing these as text means
        // the bot/agent (and the order tool) actually see the customer's
        // choice / captured data.
        if ($type === 'interactive') {
            $i  = $msg['interactive'] ?? [];
            $it = $i['type'] ?? '';
            if ($it === 'button_reply') {
                return [(string) data_get($i, 'button_reply.title', ''), []];
            }
            if ($it === 'list_reply') {
                return [trim((string) data_get($i, 'list_reply.title', '') . ' ' . (string) data_get($i, 'list_reply.description', '')), []];
            }
            if ($it === 'nfm_reply') {   // Flow form submission
                $raw = data_get($i, 'nfm_reply.response_json');
                $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
                $text = 'Form submitted';
                if (is_array($decoded)) {
                    $pairs = [];
                    foreach ($decoded as $k => $v) {
                        if ($k === 'flow_token') continue;
                        $pairs[] = $k . ': ' . (is_scalar($v) ? $v : json_encode($v));
                    }
                    if ($pairs) {
                        $text = 'Form submitted — ' . implode(', ', $pairs);
                    }
                }
                return [$text, [['type' => 'flow_reply', 'data' => $decoded]]];
            }
        }
        return ['', []];
    }

    private function handleCalls(array $calls, string $phoneNumberId, ChannelConnection $conn): void
    {
        $handler = app(HandlesInboundCall::class);
        $graph   = new GraphClient($conn->access_token ?: null);

        foreach ($calls as $call) {
            $callId = (string) ($call['id'] ?? '');
            $event  = (string) ($call['event'] ?? '');
            if ($callId === '') {
                continue;
            }

            if ($event === 'terminate') {
                $handler->onTerminate($callId);
                continue;
            }

            if ($event !== 'connect') {
                continue;
            }

            $sdpOffer = (string) data_get($call, 'session.sdp', '');
            if ($sdpOffer === '') {
                Log::warning('MetaChannels: call connect without SDP', ['call_id' => $callId]);
                continue;
            }

            $answer = $handler->answer(new InboundCall(
                projectId:         (int) $conn->project_id,
                callId:            $callId,
                channelExternalId: $phoneNumberId,
                from:              (string) ($call['from'] ?? ''),
                sdpOffer:          $sdpOffer,
                accessToken:       $conn->access_token ?: null,
            ));

            if ($answer === null || trim($answer) === '') {
                $graph->rejectCall($phoneNumberId, $callId);
                continue;
            }

            // pre_accept warms the media path; accept finalises. Both carry
            // the answer. pre_accept failure is non-fatal.
            $graph->answerCall($phoneNumberId, $callId, 'pre_accept', $answer);
            $graph->answerCall($phoneNumberId, $callId, 'accept', $answer);
        }
    }
}
