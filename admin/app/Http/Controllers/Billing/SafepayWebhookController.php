<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Billing\Gateways\SafepayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safepay's two ways of telling us a payment happened.
 *
 * THE WEBHOOK IS THE SOURCE OF TRUTH. The redirect is the customer's browser
 * coming back, and a customer who closes the tab mid-payment never sends it —
 * their money moved and our record would not know. The webhook arrives either
 * way, so anything that must happen on payment happens there, and the redirect
 * only decides what the customer is shown next.
 *
 * Both are signed, differently and not interchangeably: the webhook carries
 * X-SFPY-SIGNATURE over the raw body under the webhook secret; the redirect
 * carries `sig` over the tracker under the v1 secret. Neither body is believed
 * before its own signature checks out — without that these are an
 * unauthenticated "make me a subscriber" endpoint.
 */
class SafepayWebhookController extends Controller
{
    public function __construct(private readonly SafepayGateway $safepay)
    {
    }

    /**
     * Server-to-server notification.
     *
     * Returns 200 for anything already handled, because a webhook that returns
     * an error is retried — and retrying something that already worked is how a
     * duplicate charge record appears.
     */
    public function webhook(Request $request): JsonResponse
    {
        // The RAW body, not the parsed array. The MAC is over the bytes that
        // arrived, and re-encoding a decoded payload changes key order and
        // whitespace enough to break it.
        $raw       = $request->getContent();
        $signature = (string) $request->header('X-SFPY-SIGNATURE', '');

        if (! $this->safepay->webhookValid($raw, $signature)) {
            Log::warning('safepay.webhook.bad_signature', [
                'ip'         => $request->ip(),
                'has_header' => $signature !== '',
            ]);

            // 400, not 401: this is a malformed or forged delivery, and Safepay
            // should not keep retrying it.
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $payload = json_decode($raw, true) ?: [];

        $tracker = (string) (data_get($payload, 'data.tracker')
            ?? data_get($payload, 'tracker')
            ?? '');
        $orderId = (string) (data_get($payload, 'data.order_id')
            ?? data_get($payload, 'order_id')
            ?? '');

        Log::info('safepay.webhook', [
            'event'   => data_get($payload, 'type') ?? data_get($payload, 'event'),
            'tracker' => $tracker,
            'order'   => $orderId,
        ]);

        $charge = $this->findCharge($orderId, $tracker);

        if (! $charge) {
            // Nothing of ours matches. Accepted rather than errored: a retry
            // would not find it either, and a 4xx would have Safepay redeliver
            // for days over a payment we have no record of starting.
            Log::warning('safepay.webhook.unknown_charge', ['order' => $orderId, 'tracker' => $tracker]);

            return response()->json(['ok' => true, 'note' => 'no matching charge']);
        }

        if ($charge->status === 'paid') {
            return response()->json(['ok' => true, 'note' => 'already recorded']);
        }

        $this->markPaid($charge, $tracker, $payload);

        return response()->json(['ok' => true]);
    }

    /**
     * The customer coming back from Safepay's page.
     *
     * Records the payment too — belt and braces, since whichever of this and
     * the webhook lands first wins and the other becomes a no-op — but its real
     * job is deciding what the person now looking at their browser is told.
     */
    public function returned(Request $request)
    {
        $tracker   = (string) $request->input('tracker', '');
        $signature = (string) $request->input('sig', $request->input('signature', ''));
        $orderId   = (string) $request->input('order_id', '');

        $charge = $this->findCharge($orderId, $tracker);
        $client = $charge ? Client::find($charge->client_id) : null;

        $result = $this->safepay->verifyPayment($orderId ?: $tracker, [
            'tracker' => $tracker,
            'sig'     => $signature,
        ]);

        if ($result->paid && $charge && $charge->status !== 'paid') {
            $this->markPaid($charge, $tracker, $request->all());
        }

        $billing = $client
            ? route('billing.index', ['client' => $client->slug])
            : url('/');

        if ($result->paid) {
            return redirect($billing)->with('success', 'Payment received — thank you. Your plan is active.');
        }

        // Not proven paid. Deliberately NOT phrased as a failure: the webhook
        // may well confirm it moments from now, and telling someone their
        // payment failed when it succeeded is worse than asking them to wait.
        return redirect($billing)->with(
            'info',
            'Thanks — we are confirming your payment with Safepay. This page will show your plan '
            . 'as soon as it clears, usually within a minute.'
        );
    }

    /**
     * Our charge row, by our own order id first and their tracker second.
     *
     * Order id is ours and generated before the payment existed, so it is the
     * reliable key; the tracker only exists once Safepay has seen it, and is the
     * fallback for a delivery that omits the order id.
     */
    private function findCharge(string $orderId, string $tracker): ?object
    {
        $query = DB::table('gateway_charges')->where('gateway', 'safepay');

        if ($orderId !== '') {
            $found = (clone $query)->where('reference', $orderId)->first();

            if ($found) {
                return $found;
            }
        }

        return $tracker !== ''
            ? $query->where('gateway_ref', $tracker)->first()
            : null;
    }

    /**
     * Record the payment and extend the subscription.
     *
     * Guarded by a conditional update rather than a read-then-write: the webhook
     * and the redirect can arrive at the same moment, and `where status =
     * pending` means exactly one of them wins at the database. Two winners would
     * extend the period twice for one payment.
     */
    private function markPaid(object $charge, string $tracker, array $payload): void
    {
        $claimed = DB::table('gateway_charges')
            ->where('id', $charge->id)
            ->where('status', 'pending')
            ->update([
                'status'      => 'paid',
                'gateway_ref' => $tracker ?: $charge->gateway_ref,
                'raw'         => json_encode($payload),
                'paid_at'     => now(),
                'updated_at'  => now(),
            ]);

        if (! $claimed) {
            return;   // the other path got there first
        }

        if (! $charge->subscription_id || ! $charge->period_end) {
            return;
        }

        // Extend from the period the charge was raised for, not from now: a
        // customer who pays two days early keeps those two days rather than
        // losing them, and a customer who pays two days late is not silently
        // granted a longer month.
        DB::table('subscriptions')->where('id', $charge->subscription_id)->update([
            'status'               => 'active',
            'current_period_start' => $charge->period_start,
            'current_period_end'   => $charge->period_end,
            'past_due_since'       => null,
            'read_only_since'      => null,
            'purge_after'          => null,
            'updated_at'           => now(),
        ]);

        Log::info('safepay.charge.paid', [
            'charge'       => $charge->id,
            'subscription' => $charge->subscription_id,
            'until'        => $charge->period_end,
        ]);
    }
}
