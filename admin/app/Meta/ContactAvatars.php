<?php

namespace App\Meta;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a local copy of a customer's profile photo.
 *
 * Meta hands back a signed CDN URL, not a permanent one. It carries an
 * expiry in the query string and stops resolving within days — so storing
 * the URL and rendering it later produces an inbox full of broken images
 * that worked fine when the conversation started. There is no "permanent
 * URL" option; downloading is the only fix.
 *
 * Paths are deterministic — one file per (provider, platform id) — so a
 * refetched photo overwrites the old one instead of accumulating a new file
 * on every refresh.
 */
class ContactAvatars
{
    /** Only real images, and only small ones. */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** A profile photo above this is not a profile photo. */
    private const MAX_BYTES = 3 * 1024 * 1024;

    /** Re-fetch a stored photo after this long, even if the URL is unchanged. */
    private const STALE_DAYS = 30;

    /**
     * Download and store, returning a URL we control.
     *
     * Returns null on any failure. A missing avatar is cosmetic — it must
     * never be a reason an inbound message fails to record.
     */
    public function store(string $remoteUrl, string $provider, string $externalId): ?string
    {
        if (trim($remoteUrl) === '') {
            return null;
        }

        try {
            $resp = (new Client([
                'timeout'         => 15,
                'connect_timeout' => 5,
                'http_errors'     => false,
            ]))->get($remoteUrl);

            if ($resp->getStatusCode() >= 400) {
                // Very common and not alarming: by the time a backfill runs,
                // the signed URL it was given may already have expired.
                Log::info('Avatar fetch failed', [
                    'provider' => $provider,
                    'code'     => $resp->getStatusCode(),
                ]);

                return null;
            }

            $mime = strtok((string) $resp->getHeaderLine('Content-Type'), ';') ?: '';
            $ext  = self::ALLOWED[$mime] ?? null;

            if (! $ext) {
                Log::info('Avatar rejected: unexpected content type', ['mime' => $mime]);
                return null;
            }

            $bytes = (string) $resp->getBody();
            if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                return null;
            }

            $path = $this->pathFor($provider, $externalId, $ext);

            // Remove any copy stored under a different extension, or a PNG
            // avatar replaced by a JPEG would leave the stale file behind and
            // the two would fight over which is current.
            $this->forget($provider, $externalId, keep: $path);

            Storage::disk('public')->put($path, $bytes);

            return Storage::disk('public')->url($path);
        } catch (\Throwable $e) {
            Log::warning('Avatar store failed: ' . $e->getMessage(), ['provider' => $provider]);

            return null;
        }
    }

    /**
     * Should we fetch again?
     *
     * True when we hold nothing, when Meta has issued a different URL (the
     * customer changed their photo), or when our copy is simply old.
     *
     * @param array $meta the session's `metadata.meta` array
     */
    public function needsRefresh(array $meta, ?string $remoteUrl): bool
    {
        if (! $remoteUrl) {
            return false;                       // nothing to fetch
        }
        if (empty($meta['avatar'])) {
            return true;                        // nothing stored
        }

        // Compare without the signature: Meta re-signs the same photo on
        // every lookup, so comparing full URLs would re-download daily.
        if ($this->identity($remoteUrl) !== $this->identity((string) ($meta['avatar_src'] ?? ''))) {
            return true;
        }

        $at = (int) ($meta['avatar_at'] ?? 0);

        return $at === 0 || $at < now()->subDays(self::STALE_DAYS)->getTimestamp();
    }

    /** Delete every stored copy for a contact (used by data deletion). */
    public function forget(string $provider, string $externalId, ?string $keep = null): void
    {
        foreach (self::ALLOWED as $ext) {
            $path = $this->pathFor($provider, $externalId, $ext);
            if ($path !== $keep && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function pathFor(string $provider, string $externalId, string $ext): string
    {
        // Hashed rather than raw: the platform id would otherwise sit in a
        // publicly-reachable path, and these files are served without auth.
        return 'avatars/' . preg_replace('/[^a-z_]/', '', $provider)
            . '/' . sha1($provider . ':' . $externalId) . '.' . $ext;
    }

    /** The stable part of a Meta CDN URL — path only, signature discarded. */
    private function identity(string $url): string
    {
        return $url === '' ? '' : (string) parse_url($url, PHP_URL_PATH);
    }
}
