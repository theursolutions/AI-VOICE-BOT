<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Billing\Gateways\PaymentResult;
use App\Services\Billing\Gateways\SafepayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safepay's two ways of telling us a payment happened (Payments 2.0).
 *
 * THE WEBHOOK IS THE SOURCE OF TRUTH. The redirect is the customer's browser
 * coming back, and a customer who closes the tab mid-payment never sends it —
 * their money moved and our record would not know. The webhook arrives either
 * way, so anything that must happen on payment happens there, and the redirect
 * only decides what the customer is shown next.
 *
 * Neither is believed on its own say-so:
 *
 *   • the webhook carries X-SFPY-SIGNATURE, HMAC-SHA512 over the whole event
 *     under the webhook secret, and nothing in the body is read until it
 *     verifies;
 *   • the redirect carries no signature at all in 2.0, so it is only a prompt
 *     to ask Safepay's reporter API about the session stored on the charge
 *     when checkout began.
 *
 * Without those checks these are an unauthenticated "make me a subscriber"
 * endpoint. With them, the worst a forged request can do is make us look up a
 * payment.
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
        // The RAW body, not the parsed array. Re-encoding a decoded payload
        // changes key order, escaping and {} vs [], and the MAC is over what
        // Safepay sent.
        $raw       = $request->getContent();
        $signature = (string) $request->header('X-SFPY-SIGNATURE', '');
        $scheme    = $this->safepay->webhookScheme($raw, $signature);
        $event     = json_decode($raw, true) ?: [];

        if ($scheme === null) {
            Log::warning('safepay.webhook.bad_signature', [
                'ip'         => $request->ip(),
                'has_header' => $signature !== '',
                // Unauthenticated, and logged only so a scheme change on
                // Safepay's side can be told apart from an attack.
                'claimed'    => ['type' => $event['type'] ?? null, 'version' => $event['version'] ?? null],
                'bytes'      => strlen($raw),
            ]);

            // 400, not 401: this is a malformed or forged delivery, and Safepay
            // should not keep retrying it.
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $data    = (array) ($event['data'] ?? []);
        $type    = (string) ($event['type'] ?? '');
        $tracker = (string) ($data['tracker'] ?? '');
        // 2.0 carries our reference in the metadata attached at checkout; v1
        // carried it as a top-level order_id.
        $orderId = SafepayGateway::orderIdFrom($data['metadata'] ?? [])
            ?? (string) ($data['order_id'] ?? '');

        Log::info('safepay.webhook', [
            'event'   => $type,
            'version' => $event['version'] ?? null,
            'scheme'  => $scheme,
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

        if ($scheme === SafepayGateway::SIGNED_EVENT && $type === 'payment.failed') {
            $this->noteFailedAttempt($charge, $data);

            return response()->json(['ok' => true, 'note' => 'attempt failed']);
        }

        // A 2.0 event is signed whole, so it is read directly. An event signed
        // over `data` alone — the v1 format, still sent to an endpoint that is
        // subscribed to 1.0.0 events — could have had its type rewritten, so
        // for those Safepay is asked instead.
        $result = $scheme === SafepayGateway::SIGNED_EVENT
            ? $this->safepay->resultFromEvent($charge, $event)
            : $this->confirm($charge);

        if ($result->paid) {
            $this->markPaid($charge, $event);

            return response()->json(['ok' => true]);
        }

        if ($result->raw['unreachable'] ?? false) {
            // Safepay's own API is down. A 503 puts the delivery back in their
            // retry queue, which is exactly what should happen to it.
            return response()->json(['error' => 'could not confirm yet'], 503);
        }

        return response()->json(['ok' => true, 'note' => $result->failureReason]);
    }

    /**
     * The customer coming back from Safepay's page.
     *
     * Records the payment too — belt and braces, since whichever of this and
     * the webhook lands first wins and the other becomes a no-op — but its real
     * job is deciding what the person now looking at their browser is told.
     *
     * The reference arrives in the path, put there at checkout. A return from
     * before 2.0 has none and carries order_id instead — it finds its charge,
     * and then waits for a person, because a v1 session cannot be confirmed.
     */
    public function returned(Request $request, ?string $reference = null)
    {
        $charge = $this->findCharge(
            ($reference !== null && $reference !== '') ? $reference : (string) $request->input('order_id', ''),
            '',
        );

        // What Safepay appended, by name only. 2.0 does not document it, and
        // this is where anyone debugging a return will look first.
        Log::info('safepay.return', [
            'reference' => $reference,
            'charge'    => $charge?->id,
            'params'    => array_keys($request->all()),
        ]);

        $paid = $charge && $charge->status === 'paid';

        if ($charge && ! $paid) {
            $result = $this->confirm($charge);

            if ($result->paid) {
                $this->markPaid($charge, $result->raw);
                $paid = true;
            }
        }

        $client  = $charge ? Client::find($charge->client_id) : null;
        $billing = $client
            ? route('billing.index', ['client' => $client->slug])
            : url('/');

        if ($paid) {
            // The reference travels so the page can show a receipt for THIS
            // payment — amount, plan and period — rather than a bare "thanks".
            return redirect($billing . '?paid=' . urlencode($charge->reference))
                ->with('success', 'Payment received — thank you. Your plan is active.');
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
     * Ask Safepay whether this charge's session was paid.
     *
     * Only ever the session stored when checkout began — never a tracker
     * named by the caller, which could belong to a cheaper payment.
     *
     * A charge with no stored session was opened by the v1 integration, and is
     * left for a person. The reporter API answers v1 sessions with a 500, and
     * v1 is not safe to take on trust: its sessions open with the PUBLIC key,
     * and its order id rode in the browser's URL, so a customer could open a
     * Rs 1 session labelled with their own pending order and come back with a
     * perfectly genuine signature. The only charges this affects are those in
     * flight when 2.0 was deployed.
     */
    private function confirm(object $charge): PaymentResult
    {
        if (! $charge->gateway_ref) {
            Log::warning('safepay.v1_charge_unconfirmable', [
                'charge'    => $charge->id,
                'reference' => $charge->reference,
            ]);

            return PaymentResult::pending(
                $charge->reference,
                'Opened before Payments 2.0 — confirm it in the Safepay dashboard.',
            );
        }

        return $this->safepay->verifyPayment($charge->reference, [
            'tracker'      => (string) $charge->gateway_ref,
            'amount_cents' => (int) $charge->amount_cents,
            'currency'     => $charge->currency ?: 'PKR',
        ]);
    }

    /**
     * Our charge row, by our own reference first and their tracker second.
     *
     * The reference is ours and generated before the payment existed, so it is
     * the reliable key; the tracker is the fallback for a delivery that carries
     * no order id — stored at checkout since 2.0, so it now matches pending
     * charges too.
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
     * A declined attempt, recorded without giving up on the charge.
     *
     * The status stays PENDING on purpose. Safepay lets the customer try another
     * card on the same session, and marking this failed would make the success
     * that follows unrecordable — markPaid() only claims a pending row. The
     * reason is kept so support can see why a charge is still waiting.
     */
    private function noteFailedAttempt(object $charge, array $data): void
    {
        $reason = trim((string) ($data['message'] ?? 'Payment attempt failed'))
            . (isset($data['category']) ? ' (' . $data['category'] . ')' : '');

        DB::table('gateway_charges')
            ->where('id', $charge->id)
            ->where('status', 'pending')
            ->update([
                'failure_reason' => mb_substr($reason, 0, 500),
                'updated_at'     => now(),
            ]);

        Log::info('safepay.attempt_failed', ['charge' => $charge->id, 'reason' => $reason]);
    }

    /**
     * Record the payment and extend the subscription.
     *
     * Guarded by a conditional update rather than a read-then-write: the webhook
     * and the redirect can arrive at the same moment, and `where status =
     * pending` means exactly one of them wins at the database. Two winners would
     * extend the period twice for one payment.
     */
    private function markPaid(object $charge, array $payload): void
    {
        $claimed = DB::table('gateway_charges')
            ->where('id', $charge->id)
            ->where('status', 'pending')
            ->update([
                'status'         => 'paid',
                // A declined attempt before this success is history now, and a
                // paid charge showing a red "Failure" line would mislead support.
                'failure_reason' => null,
                'raw'            => json_encode($payload),
                'paid_at'        => now(),
                'updated_at'     => now(),
            ]);

        if (! $claimed) {
            return;   // the other path got there first
        }

        if (! $charge->period_end) {
            Log::warning('safepay.charge.no_period', ['charge' => $charge->id]);

            return;
        }

        // Puts them ON THE PLAN, not merely forward in time. Extending dates
        // alone would leave someone who paid for Growth sitting on Starter —
        // billed correctly and limited wrongly. Also creates the subscription
        // when there wasn't one, which is every first purchase.
        //
        // The period comes from the charge, decided when checkout began: a
        // customer who pays two days early keeps those two days, and one who
        // pays two days late is not silently granted a longer month.
        $subscription = app(\App\Services\Billing\GatewayCheckoutService::class)
            ->applyPaidCharge($charge);

        if ($subscription && ! $charge->subscription_id) {
            // First purchase — the charge was raised before the subscription
            // existed. Linking it back keeps the payment history joinable.
            DB::table('gateway_charges')->where('id', $charge->id)->update([
                'subscription_id' => $subscription->id,
                'updated_at'      => now(),
            ]);
        }

        Log::info('safepay.charge.paid', [
            'charge'       => $charge->id,
            'subscription' => $subscription?->id,
            'until'        => $charge->period_end,
        ]);
    }
}
