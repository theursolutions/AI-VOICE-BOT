<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Billing\PaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Saved cards: add, change, set default, remove.
 *
 * FIELD NAMING: the payment method arrives as `payment_method`, never
 * `payment_method_id`. App\Http\Middleware\DecodeHashids rewrites any request
 * key matching `*_id` through the hashid decoder, which would corrupt a
 * `pm_1A2b3C…` reference. See SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §5 C1.
 *
 * Every write is owner-only and re-verified server-side against the
 * workspace's own Stripe customer — a `pm_…` id from the browser is untrusted
 * input like any other.
 */
class PaymentMethodController extends Controller
{
    public function __construct(private readonly PaymentMethodService $cards)
    {
    }

    /**
     * A SetupIntent client secret so the browser can save a card without
     * charging it. Confirming it runs any 3-D Secure challenge now, while the
     * customer is present, rather than failing at the first invoice.
     */
    public function intent(Request $request, Client $client): JsonResponse
    {
        $this->authorizeOwner($request, $client);

        try {
            return response()->json([
                'client_secret' => $this->cards->createSetupIntent($client),
            ]);
        } catch (\Throwable $e) {
            Log::error('billing.setup_intent.failed', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'We couldn’t start card setup. Please try again.',
            ], 422);
        }
    }

    /** Attach a card the browser just tokenised, and make it the default. */
    public function store(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $this->authorizeOwner($request, $client);

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->cards->attach($client, $data['payment_method']);

            AuditLog::record('billing.card.added', ['payload' => ['client_id' => $client->id]]);

            return $this->ok($request, $client, 'Card saved.');
        } catch (\Throwable $e) {
            Log::error('billing.card.attach_failed', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->fail($request, 'We couldn’t save that card. Please try again.');
        }
    }

    public function makeDefault(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $this->authorizeOwner($request, $client);

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->cards->setDefault($client, $data['payment_method']);

            AuditLog::record('billing.card.default_changed', ['payload' => ['client_id' => $client->id]]);

            return $this->ok($request, $client, 'Default card updated. Future invoices will use it.');
        } catch (\RuntimeException $e) {
            return $this->fail($request, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('billing.card.default_failed', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            return $this->fail($request, 'We couldn’t update your default card.');
        }
    }

    public function destroy(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $this->authorizeOwner($request, $client);

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->cards->detach($client, $data['payment_method']);

            AuditLog::record('billing.card.removed', ['payload' => ['client_id' => $client->id]]);

            return $this->ok($request, $client, 'Card removed.');
        } catch (\RuntimeException $e) {
            // e.g. "this is the only card on an active subscription"
            return $this->fail($request, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('billing.card.detach_failed', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            return $this->fail($request, 'We couldn’t remove that card.');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function ok(Request $request, Client $client, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'cards' => $this->cards->all($client->fresh())])
            : back()->with('success', $message);
    }

    private function fail(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->with('error', $message);
    }

    private function authorizeOwner(Request $request, Client $client): void
    {
        abort_unless(
            $request->user()?->isOwnerOf($client->id),
            403,
            'Only the workspace owner can manage payment methods.'
        );
    }
}
