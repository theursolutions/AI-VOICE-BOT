<?php

namespace Msd\MetaChannels;

use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Public entry point / helpers for the channels engine. The host app and
 * the package's own controllers go through this rather than poking at
 * config or the model directly.
 */
class MetaManager
{
    /** Meta's customer-service window: free-form replies allowed for 24h
     *  after the customer's last message. After that you must use an
     *  approved template. */
    public const SERVICE_WINDOW_SECONDS = 86400;

    /** Is the 24h free-form reply window still open for this conversation? */
    public function serviceWindowOpen(?int $lastInboundAt, ?int $now = null): bool
    {
        if (!$lastInboundAt) {
            return false;
        }
        return (($now ?? time()) - $lastInboundAt) < self::SERVICE_WINDOW_SECONDS;
    }

    /** Unix time the window closes, or null if there's been no inbound. */
    public function serviceWindowExpiresAt(?int $lastInboundAt): ?int
    {
        return $lastInboundAt ? $lastInboundAt + self::SERVICE_WINDOW_SECONDS : null;
    }

    /**
     * Resolve the enabled WhatsApp connection for a business number, with a
     * single-number config fallback so the engine works before the Channels
     * page is populated. Returns null when nothing matches.
     */
    public function resolveWhatsappConnection(string $phoneNumberId): ?ChannelConnection
    {
        $conn = ChannelConnection::query()
            ->where('provider', ChannelConnection::PROVIDER_WHATSAPP)
            ->where('external_id', $phoneNumberId)
            ->enabled()
            ->first();

        if ($conn) {
            return $conn;
        }

        $fallback = config('meta.whatsapp.project_id');
        if ($fallback) {
            $synthetic = new ChannelConnection([
                'provider'    => ChannelConnection::PROVIDER_WHATSAPP,
                'external_id' => $phoneNumberId,
                'status'      => ChannelConnection::STATUS_ENABLED,
            ]);
            $synthetic->project_id = (int) $fallback;
            return $synthetic;
        }

        return null;
    }

    /**
     * Resolve an enabled connection by provider + external id (page id /
     * IG account id). Used for Messenger/Instagram, which — unlike
     * WhatsApp — have no single-number config fallback.
     */
    public function resolveConnection(string $provider, string $externalId): ?ChannelConnection
    {
        if ($externalId === '') {
            return null;
        }
        return ChannelConnection::query()
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->enabled()
            ->first();
    }

    /**
     * Verify the X-Hub-Signature-256 HMAC against EVERY app secret we hold.
     *
     * Meta signs each delivery with the secret of the app that owns the
     * subscription, and one installation can have two: WhatsApp and Page
     * webhooks are signed by the channel-onboarding app (META_APP_SECRET),
     * while Instagram-Login deliveries are signed by the separate Instagram
     * app (INSTAGRAM_APP_SECRET). Nothing in the request says which.
     *
     * Checking only one secret therefore rejected every Instagram DM with a
     * 403 while WhatsApp worked perfectly — and the symptom was "Instagram
     * onboarded fine but no messages arrive", with `meta:subscribe` reporting
     * everything correctly subscribed, because it WAS. SignedRequest::secrets()
     * has always tried all three for signed_request callbacks; this path was
     * simply never brought in line with it.
     *
     * Returns true when nothing is configured at all — see the caller, which
     * logs a notice. That only happens pre-setup, when no genuine delivery can
     * arrive anyway.
     */
    public function signatureValid(string $rawBody, string $header): bool
    {
        $secrets = Support\SignedRequest::secrets();

        if (! $secrets) {
            return true; // not configured yet (pre-Meta-app); webhook logs a notice
        }

        if ($header === '') {
            return false;
        }

        foreach ($secrets as $secret) {
            // hash_equals, not ===, so a wrong secret cannot be recovered a
            // byte at a time from response timing.
            if (hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), $header)) {
                return true;
            }
        }

        return false;
    }

    public function verifyToken(): string
    {
        return (string) config('meta.whatsapp.verify_token');
    }
}
