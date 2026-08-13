<?php

namespace App\Http\Controllers;

use App\Jobs\PurgeMetaUserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Msd\MetaChannels\Models\DataDeletionRequest;
use Msd\MetaChannels\Support\SignedRequest;

/**
 * Data deletion — required by Meta for any app holding messaging
 * permissions, and a blocker on App Review until it answers correctly.
 *
 * Three endpoints, and it is worth being clear which is which because they
 * are easy to conflate:
 *
 *   GET  /data-deletion                    the human-readable instructions
 *                                          page. This is the "Data Deletion
 *                                          Instructions URL" field.
 *   POST /meta/data-deletion               the machine callback. Meta POSTs a
 *                                          signed_request here and expects
 *                                          JSON back. This is the "Data
 *                                          Deletion Request URL" field.
 *   GET  /meta/data-deletion/status/{code} where the URL we return points.
 *
 * The callback must reply with {url, confirmation_code} and it must reply
 * fast: Meta treats a slow or malformed answer as a failure and retries,
 * which is why the actual erasure is queued rather than done inline.
 */
class DataDeletionController extends Controller
{
    /** Public instructions page — no signed request, no identifiers. */
    public function instructions(): View
    {
        return view('legal.data-deletion');
    }

    /**
     * Meta's callback.
     *
     * Verification is mandatory: this endpoint is unauthenticated by design
     * and it destroys conversation history. Without the HMAC check, anyone
     * who learned a customer's IGSID could wipe their inbox with one curl.
     */
    public function callback(Request $request): JsonResponse
    {
        $signed = (string) $request->input('signed_request', '');
        $data   = $signed === '' ? null : SignedRequest::parse($signed, SignedRequest::secrets());

        if ($data === null) {
            Log::warning('Data deletion callback: missing or invalid signed_request', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        $userId = (string) ($data['user_id'] ?? '');
        if ($userId === '') {
            return response()->json(['error' => 'no user_id in signed_request'], 400);
        }

        // An existing pending request for the same person is reused rather
        // than duplicated. People click twice, and Meta retries on timeout —
        // both would otherwise produce a second confirmation code for work
        // already queued.
        $existing = DataDeletionRequest::where('external_user_id', $userId)
            ->where('status', DataDeletionRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        $deletion = $existing ?: DataDeletionRequest::open(
            $this->providerFor($data),
            $userId,
        );

        if (! $existing) {
            PurgeMetaUserData::dispatch($deletion->id);
        }

        Log::info('Data deletion requested', [
            'request'  => $deletion->id,
            'provider' => $deletion->provider,
            'reused'   => (bool) $existing,
        ]);

        // Exactly the two keys Meta expects. Extra keys are ignored, but a
        // missing `url` fails App Review.
        return response()->json([
            'url'               => route('data-deletion.status', ['code' => $deletion->confirmation_code]),
            'confirmation_code' => $deletion->confirmation_code,
        ]);
    }

    /** Where the URL we handed back points. Public, opaque code, no PII. */
    public function status(string $code): View
    {
        $deletion = DataDeletionRequest::where('confirmation_code', $code)->first();

        abort_unless($deletion, 404);

        return view('legal.data-deletion-status', ['deletion' => $deletion]);
    }

    /**
     * A label for the request, not a routing decision.
     *
     * Meta's signed_request does not name the product it came from, so this
     * cannot be known for certain. PurgeMetaUserData deliberately does not
     * trust it — it searches every Meta channel — so a wrong guess here costs
     * nothing beyond a slightly misleading row in the admin list.
     */
    private function providerFor(array $data): string
    {
        return config('meta.instagram.app_id') ? 'instagram' : 'facebook_page';
    }
}
