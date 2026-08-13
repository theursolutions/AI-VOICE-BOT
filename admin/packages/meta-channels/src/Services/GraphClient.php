<?php

namespace Msd\MetaChannels\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Meta Graph API client — WhatsApp messaging + Business Calling signaling.
 *
 * An optional per-connection token can be passed; otherwise the app-level
 * token from config('meta.whatsapp.access_token') is used.
 *
 * The host is overridable because Instagram accounts onboarded through
 * Instagram Login are served by graph.instagram.com, and their tokens are
 * rejected outright by graph.facebook.com. Prefer forConnection(), which
 * picks the right host from the connection itself.
 */
class GraphClient
{
    private string $base;
    private string $version;
    private ?string $token;

    public function __construct(?string $token = null, ?string $base = null, ?string $version = null)
    {
        $cfg = config('meta.whatsapp');
        $this->base    = rtrim($base ?: ($cfg['graph_base'] ?? 'https://graph.facebook.com'), '/');
        $this->version = $version ?: ($cfg['graph_version'] ?? 'v21.0');
        $this->token   = $token ?: ($cfg['access_token'] ?? null);
    }

    /**
     * A client pointed at the right Graph host for a connection.
     *
     * Always use this instead of `new GraphClient($conn->access_token)`. The
     * two hosts are not interchangeable: an Instagram-Login token sent to
     * graph.facebook.com fails with a generic OAuth error that says nothing
     * about the host being wrong, which is a genuinely hard afternoon.
     */
    public static function forConnection(?ChannelConnection $conn): self
    {
        return new self($conn?->access_token ?: null, self::baseFor($conn?->metadata));
    }

    /**
     * The Graph host implied by a connection's metadata, or null for the
     * default (graph.facebook.com).
     *
     * Keyed on `metadata.login`, which InstagramLoginService stamps at
     * discovery time — the only durable signal distinguishing the two
     * Instagram onboarding paths, since both produce provider=instagram.
     */
    public static function baseFor(mixed $metadata): ?string
    {
        return data_get($metadata, 'login') === 'instagram'
            ? (string) config('meta.instagram.graph_base', 'https://graph.instagram.com')
            : null;
    }

    // -- Messaging ----------------------------------------------------------

    /**
     * Send a WhatsApp text. Returns the sent message id (wamid) on success,
     * or null on failure. Pass $contextMessageId to reply-with-quote.
     */
    public function sendText(string $phoneNumberId, string $to, string $text, ?string $contextMessageId = null): ?string
    {
        if (trim($text) === '') {
            return null;
        }
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $this->truncate($text)],
        ];
        if ($contextMessageId) {
            $body['context'] = ['message_id' => $contextMessageId];
        }
        $r = $this->post("{$phoneNumberId}/messages", $body);
        return $r ? (data_get($r, 'messages.0.id') ?: 'sent') : null;
    }

    /**
     * Send a text reply over Messenger or Instagram (Messenger Platform).
     *
     * @param string  $fromId       Page id (FB) / IG account id — {id} in /{id}/messages
     * @param string  $recipientId  PSID (FB) / IGSID (IG)
     * @param ?string $tag          a Messenger message tag (e.g. HUMAN_AGENT,
     *                              which extends the reply window to 7 days)
     */
    public function sendMessengerText(string $fromId, string $recipientId, string $text, ?string $tag = null): ?string
    {
        if (trim($text) === '') {
            return null;
        }
        $body = [
            'recipient' => ['id' => $recipientId],
            'message'   => ['text' => $this->truncate($text, 2000)],
        ];
        if ($tag) {
            $body['messaging_type'] = 'MESSAGE_TAG';
            $body['tag'] = $tag;
        } else {
            $body['messaging_type'] = 'RESPONSE';
        }
        $r = $this->post("{$fromId}/messages", $body);
        return $r ? (data_get($r, 'message_id') ?: 'sent') : null;
    }

    /**
     * The public profile of someone who has messaged a Page or IG account.
     *
     * WhatsApp puts the sender's name straight in the webhook payload, but
     * Messenger and Instagram do not — they send only an opaque PSID/IGSID.
     * Without this call the inbox can only show a 16-digit number, which is
     * useless to whoever is answering.
     *
     * Meta only returns a profile for people who have actually messaged the
     * account, so this cannot be used to look up arbitrary users. Failure is
     * normal and non-fatal: a customer can decline profile sharing, and older
     * IG accounts often return nothing at all.
     *
     * @return array{name:?string, profile_pic:?string}
     */
    public function messengerProfile(string $userId, string $provider = 'facebook_page'): array
    {
        // Instagram exposes username rather than a real name.
        $fields = $provider === 'instagram'
            ? 'name,username,profile_pic'
            : 'first_name,last_name,profile_pic';

        $r = $this->get($userId, ['fields' => $fields]) ?? [];

        $name = $r['name']
            ?? trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))
            ?: ($r['username'] ?? null);

        return [
            'name'        => is_string($name) && trim($name) !== '' ? trim($name) : null,
            'profile_pic' => $r['profile_pic'] ?? null,
        ];
    }

    /** Send an attachment (image/audio/video/file) over Messenger/Instagram by URL. */
    public function sendMessengerAttachment(string $fromId, string $recipientId, string $type, string $url, ?string $tag = null): bool
    {
        $body = [
            'recipient' => ['id' => $recipientId],
            'message'   => ['attachment' => [
                'type'    => $type,   // image | audio | video | file
                'payload' => ['url' => $url, 'is_reusable' => false],
            ]],
        ];
        $body['messaging_type'] = $tag ? 'MESSAGE_TAG' : 'RESPONSE';
        if ($tag) {
            $body['tag'] = $tag;
        }
        return $this->post("{$fromId}/messages", $body) !== null;
    }

    // -- WhatsApp media / templates / interactive ---------------------------

    /**
     * Upload media bytes to WhatsApp and return the media id (needed before
     * sending media you host yourself — e.g. an agent's file upload).
     */
    public function uploadWhatsAppMedia(string $phoneNumberId, string $bytes, string $mime, string $filename = 'file'): ?string
    {
        if (!$this->token) {
            return null;
        }
        $url = "{$this->base}/{$this->version}/{$phoneNumberId}/media";
        try {
            $client = new Client(['timeout' => 30, 'http_errors' => false]);
            $resp = $client->post($url, [
                'headers'   => ['Authorization' => 'Bearer ' . $this->token],
                'multipart' => [
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ['name' => 'type', 'contents' => $mime],
                    ['name' => 'file', 'contents' => $bytes, 'filename' => $filename, 'headers' => ['Content-Type' => $mime]],
                ],
            ]);
            if ($resp->getStatusCode() >= 400) {
                Log::warning('MetaChannels: media upload failed', ['code' => $resp->getStatusCode(), 'body' => substr((string) $resp->getBody(), 0, 500)]);
                return null;
            }
            return json_decode((string) $resp->getBody(), true)['id'] ?? null;
        } catch (\Throwable $e) {
            Log::error('MetaChannels: media upload exception: ' . $e->getMessage());
            return null;
        }
    }

    /** Send WhatsApp media by an uploaded media id. $type = image|audio|video|document|sticker. */
    public function sendWhatsAppMediaById(string $phoneNumberId, string $to, string $type, string $mediaId, ?string $caption = null, ?string $filename = null): bool
    {
        $media = ['id' => $mediaId];
        if ($caption && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }
        if ($filename && $type === 'document') {
            $media['filename'] = $filename;
        }
        return $this->post("{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $media,
        ]) !== null;
    }

    /** Send WhatsApp media by a public link (no upload step). */
    public function sendWhatsAppMediaByLink(string $phoneNumberId, string $to, string $type, string $link, ?string $caption = null): bool
    {
        $media = ['link' => $link];
        if ($caption && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }
        return $this->post("{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $media,
        ]) !== null;
    }

    /**
     * Send an approved WhatsApp template — the way to RE-OPEN a chat after
     * the 24h window closed.
     *
     * @param array $components template components (body params, buttons…)
     */
    public function sendTemplate(string $phoneNumberId, string $to, string $template, string $lang = 'en_US', array $components = []): bool
    {
        $payload = ['name' => $template, 'language' => ['code' => $lang]];
        if ($components) {
            $payload['components'] = $components;
        }
        return $this->post("{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => $payload,
        ]) !== null;
    }

    /**
     * Send a WhatsApp interactive reply-buttons message — a lightweight way
     * to capture intent (e.g. "Place order? Yes / No / Change") without
     * full WhatsApp Flows.
     *
     * @param array<int, array{id:string, title:string}> $buttons up to 3
     */
    public function sendInteractiveButtons(string $phoneNumberId, string $to, string $body, array $buttons, ?string $header = null): bool
    {
        $action = ['buttons' => array_map(
            fn ($b) => ['type' => 'reply', 'reply' => ['id' => $b['id'], 'title' => mb_substr($b['title'], 0, 20)]],
            array_slice($buttons, 0, 3),
        )];
        $interactive = ['type' => 'button', 'body' => ['text' => $this->truncate($body, 1024)], 'action' => $action];
        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => mb_substr($header, 0, 60)];
        }
        return $this->post("{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ]) !== null;
    }

    /**
     * List a WABA's message templates (for a real template picker). Returns
     * the raw template objects; callers typically filter status=APPROVED.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listTemplates(string $wabaId, int $limit = 100): array
    {
        $data = $this->get("{$wabaId}/message_templates", ['fields' => 'name,status,language,category,components', 'limit' => $limit]);
        return $data['data'] ?? [];
    }

    /**
     * Send a published WhatsApp Flow (rich in-chat form) to capture data.
     * The flow itself is authored/published in Meta's Flow Builder.
     */
    public function sendFlow(string $phoneNumberId, string $to, string $flowId, string $cta, string $body, string $flowToken, ?string $screen = null, ?string $header = null): bool
    {
        $params = [
            'flow_message_version' => '3',
            'flow_id'              => $flowId,
            'flow_cta'             => mb_substr($cta, 0, 20),
            'flow_token'           => $flowToken,
            'flow_action'          => $screen ? 'navigate' : 'data_exchange',
        ];
        if ($screen) {
            $params['flow_action_payload'] = ['screen' => $screen, 'data' => (object) []];
        }
        $interactive = ['type' => 'flow', 'body' => ['text' => $this->truncate($body, 1024)], 'action' => ['name' => 'flow', 'parameters' => $params]];
        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => mb_substr($header, 0, 60)];
        }
        return $this->post("{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ]) !== null;
    }

    /** Send a single catalog product message. */
    public function sendProduct(string $phoneNumberId, string $to, string $catalogId, string $retailerId, ?string $body = null): bool
    {
        $interactive = ['type' => 'product', 'action' => ['catalog_id' => $catalogId, 'product_retailer_id' => $retailerId]];
        if ($body) {
            $interactive['body'] = ['text' => $this->truncate($body, 1024)];
        }
        return $this->post("{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ]) !== null;
    }

    /**
     * Send a multi-product catalog message.
     *
     * @param array<int, array{title:string, product_items:array<int, array{product_retailer_id:string}>}> $sections
     */
    public function sendProductList(string $phoneNumberId, string $to, string $catalogId, string $header, string $body, array $sections): bool
    {
        return $this->post("{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'product_list',
                'header' => ['type' => 'text', 'text' => mb_substr($header, 0, 60)],
                'body'   => ['text' => $this->truncate($body, 1024)],
                'action' => ['catalog_id' => $catalogId, 'sections' => $sections],
            ],
        ]) !== null;
    }

    /**
     * Upload a reusable Messenger/Instagram attachment, returning its
     * attachment_id (the way to send agent-uploaded media on IG/FB).
     */
    public function uploadMessengerAttachment(string $pageId, string $bytes, string $type, string $filename, string $mime): ?string
    {
        if (!$this->token) {
            return null;
        }
        $url = "{$this->base}/{$this->version}/{$pageId}/message_attachments";
        try {
            $client = new Client(['timeout' => 30, 'http_errors' => false]);
            $resp = $client->post($url, [
                'headers'   => ['Authorization' => 'Bearer ' . $this->token],
                'multipart' => [
                    ['name' => 'message', 'contents' => json_encode(['attachment' => ['type' => $type, 'payload' => ['is_reusable' => true]]])],
                    ['name' => 'filedata', 'contents' => $bytes, 'filename' => $filename, 'headers' => ['Content-Type' => $mime]],
                ],
            ]);
            if ($resp->getStatusCode() >= 400) {
                Log::warning('MetaChannels: messenger attachment upload failed', ['code' => $resp->getStatusCode(), 'body' => substr((string) $resp->getBody(), 0, 500)]);
                return null;
            }
            return json_decode((string) $resp->getBody(), true)['attachment_id'] ?? null;
        } catch (\Throwable $e) {
            Log::error('MetaChannels: messenger attachment upload exception: ' . $e->getMessage());
            return null;
        }
    }

    /** Send a Messenger/IG attachment by a previously-uploaded attachment_id. */
    public function sendMessengerAttachmentById(string $fromId, string $recipientId, string $type, string $attachmentId, ?string $tag = null): bool
    {
        $body = [
            'recipient' => ['id' => $recipientId],
            'message'   => ['attachment' => ['type' => $type, 'payload' => ['attachment_id' => $attachmentId]]],
        ];
        $body['messaging_type'] = $tag ? 'MESSAGE_TAG' : 'RESPONSE';
        if ($tag) {
            $body['tag'] = $tag;
        }
        return $this->post("{$fromId}/messages", $body) !== null;
    }

    public function markRead(string $phoneNumberId, string $messageId): void
    {
        if ($messageId === '') {
            return;
        }
        $this->post("{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $messageId,
        ]);
    }

    // -- Calling ------------------------------------------------------------

    /**
     * Answer an inbound call. Meta expects pre_accept then accept; both may
     * carry the SDP answer. action = 'pre_accept' | 'accept'.
     */
    public function answerCall(string $phoneNumberId, string $callId, string $action, string $sdpAnswer): bool
    {
        return $this->post("{$phoneNumberId}/calls", [
            'messaging_product' => 'whatsapp',
            'call_id'           => $callId,
            'action'            => $action,
            'session'           => ['sdp_type' => 'answer', 'sdp' => $sdpAnswer],
        ]) !== null;
    }

    public function rejectCall(string $phoneNumberId, string $callId): bool
    {
        return $this->post("{$phoneNumberId}/calls", [
            'messaging_product' => 'whatsapp',
            'call_id'           => $callId,
            'action'            => 'reject',
        ]) !== null;
    }

    public function terminateCall(string $phoneNumberId, string $callId): bool
    {
        return $this->post("{$phoneNumberId}/calls", [
            'messaging_product' => 'whatsapp',
            'call_id'           => $callId,
            'action'            => 'terminate',
        ]) !== null;
    }

    // -- Profile enrichment -------------------------------------------------

    /**
     * Fetch a user's public profile. WhatsApp carries the name in the
     * webhook already, so this is for Messenger (PSID) / Instagram (IGSID).
     *
     * @return array{name?:string, profile_pic?:string, username?:string}|null
     */
    public function getUserProfile(string $userId, string $provider): ?array
    {
        $fields = $provider === 'instagram'
            ? 'name,username,profile_pic'
            : 'name,first_name,last_name,profile_pic';

        $data = $this->get($userId, ['fields' => $fields]);
        if (!$data) {
            return null;
        }
        return [
            'name'        => $data['name'] ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: null,
            'username'    => $data['username'] ?? null,
            'profile_pic' => $data['profile_pic'] ?? null,
        ];
    }

    // -- Media download -----------------------------------------------------

    /**
     * Download a WhatsApp media object by id (two-step: resolve URL, then
     * fetch the bytes with the bearer token).
     *
     * @return array{bytes:string, mime:?string}|null
     */
    public function downloadWhatsAppMedia(string $mediaId): ?array
    {
        $meta = $this->get($mediaId, []);
        if (!$meta || empty($meta['url'])) {
            return null;
        }
        return $this->download($meta['url'], $meta['mime_type'] ?? null);
    }

    /**
     * Download a Messenger/Instagram attachment from its (pre-signed) URL.
     *
     * @return array{bytes:string, mime:?string}|null
     */
    public function downloadUrl(string $url, ?string $mime = null): ?array
    {
        return $this->download($url, $mime);
    }

    // -- internals ----------------------------------------------------------

    /** @return array|null  decoded JSON on success */
    private function get(string $path, array $query): ?array
    {
        if (!$this->token) {
            return null;
        }
        $url = "{$this->base}/{$this->version}/{$path}";
        try {
            $client = new Client(['timeout' => 12, 'connect_timeout' => 5, 'http_errors' => false]);
            $resp = $client->get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token],
                'query'   => $query + ['access_token' => $this->token],
            ]);
            if ($resp->getStatusCode() >= 400) {
                Log::warning('MetaChannels GraphClient GET failed', [
                    'path' => $path, 'code' => $resp->getStatusCode(),
                ]);
                return null;
            }
            return json_decode((string) $resp->getBody(), true) ?: null;
        } catch (\Throwable $e) {
            Log::error('MetaChannels GraphClient GET exception on ' . $path . ': ' . $e->getMessage());
            return null;
        }
    }

    /** @return array{bytes:string, mime:?string}|null */
    private function download(string $url, ?string $mime): ?array
    {
        try {
            $client = new Client(['timeout' => 30, 'connect_timeout' => 5, 'http_errors' => false]);
            $resp = $client->get($url, [
                'headers' => $this->token ? ['Authorization' => 'Bearer ' . $this->token] : [],
            ]);
            if ($resp->getStatusCode() >= 400) {
                Log::warning('MetaChannels GraphClient media download failed', ['code' => $resp->getStatusCode()]);
                return null;
            }
            return [
                'bytes' => (string) $resp->getBody(),
                'mime'  => $mime ?: ($resp->getHeaderLine('Content-Type') ?: null),
            ];
        } catch (\Throwable $e) {
            Log::error('MetaChannels GraphClient media download exception: ' . $e->getMessage());
            return null;
        }
    }


    /** @return array|null  decoded body on success, null on failure */
    private function post(string $path, array $json): ?array
    {
        if (!$this->token) {
            Log::warning('MetaChannels GraphClient: no access token — skipping ' . $path);
            return null;
        }
        $url = "{$this->base}/{$this->version}/{$path}";
        try {
            $client = new Client(['timeout' => 15, 'connect_timeout' => 5, 'http_errors' => false]);
            $resp = $client->post($url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'],
                'json'    => $json,
            ]);
            $code = $resp->getStatusCode();
            $body = (string) $resp->getBody();
            if ($code >= 400) {
                Log::warning('MetaChannels GraphClient: request failed', [
                    'path' => $path, 'code' => $code, 'body' => substr($body, 0, 1000),
                ]);
                return null;
            }
            return json_decode($body, true) ?: [];
        } catch (\Throwable $e) {
            Log::error('MetaChannels GraphClient: exception on ' . $path . ': ' . $e->getMessage());
            return null;
        }
    }

    private function truncate(string $text, int $max = 4096): string
    {
        return mb_strlen($text) > $max ? (mb_substr($text, 0, $max - 3) . '...') : $text;
    }
}
