<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\Subscription;
use App\Services\Billing\GatewayCheckoutService;
use App\Services\Billing\Gateways\PaddleGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paddle's notifications.
 *
 * THIS IS THE ONLY THING THAT GRANTS A PLAN. The overlay fires a
 * `checkout.completed` event in the customer's browser, and that event is a
 * line of JavaScript anyone can run from a console — so it decides what the
 * person is shown next and nothing else. Payment is what Paddle tells this
 * endpoint, over a signed channel, or it did not happen.
 *
 * RENEWALS ARRIVE HERE UNANNOUNCED. Paddle bills recurring subscriptions
 * itself, so a year after the first purchase a transaction appears that we
 * never raised a charge row for. That is why `paddle_subscription_id` is stored
 * on the subscription: it is the only thread back to the workspace when there
 * is no reference of ours in the payload.
 *
 * EVERY ANSWER IS 200 UNLESS THE SIGNATURE IS WRONG. Paddle retries non-2xx
 * responses for days, and retrying something we have already handled — or that
 * no retry could ever fix — turns one bad delivery into a week of noise.
 */
class PaddleWebhookController extends Controller
{
    public function __construct(private readonly PaddleGateway $paddle)
    {
    }

    public function webhook(Request $request): JsonResponse
    {
        // The RAW body. The MAC covers the bytes that arrived, and decoding
        // then re-encoding changes key order and whitespace enough to break it.
        $raw       = $request->getContent();
        $signature = (string) $request->header('Paddle-Signature', '');

        if (! $this->paddle->webhookValid($raw, $signature)) {
            Log::warning('paddle.webhook.bad_signature', [
                'ip'         => $request->ip(),
                'has_header' => $signature !== '',
            ]);

            // 400, not 401: this is a forged or malformed delivery, and Paddle
            // should stop retrying it.
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $payload = json_decode($raw, true) ?: [];
        $event   = (string) ($payload['event_type'] ?? '');
        $data    = (array) ($payload['data'] ?? []);

        Log::info('paddle.webhook', [
            'event' => $event,
            'id'    => $data['id'] ?? null,
        ]);

        match ($event) {
            'transaction.completed', 'transaction.paid' => $this->transactionPaid($data),
            'subscription.canceled'                     => $this->subscriptionCanceled($data),
            'subscription.created', 'subscription.updated' => $this->subscriptionSynced($data),
            default => null,
        };

        return response()->json(['ok' => true]);
    }

    /**
     * The customer coming back from the overlay, confirmed against Paddle.
     *
     * WHY THIS EXISTS ALONGSIDE THE WEBHOOK. The webhook is still the source of
     * truth and still does the granting — but it is a server-to-server call, and
     * it can be late, blocked, or simply unable to reach a machine that has no
     * public address. Meanwhile the customer is sitting on a page having just
     * paid, watching nothing happen.
     *
     * So the browser's "it completed" is treated as a HINT, never as proof: it
     * tells us which transaction to go and ASK Paddle about. Paddle's answer
     * decides. That is the same shape as the Safepay return, and it means a
     * payment settles the moment the customer gets back rather than whenever a
     * webhook happens to land.
     *
     * THE TRANSACTION ID COMES FROM THE BROWSER, so it is attacker-controlled
     * and cannot be trusted on its own — somebody could paste another
     * customer's. Two things close that: the charge is looked up by OUR
     * reference scoped to this workspace, and Paddle's copy of the transaction
     * must carry that same reference in its `custom_data`. A transaction that
     * does not name our charge is not our charge.
     */
    public function confirm(Request $request, \App\Models\Client $client)
    {
        abort_unless($request->user()?->hasMembership($client->id), 403);

        $validated = $request->validate([
            'reference'   => ['required', 'string', 'max:64'],
            'transaction' => ['required', 'string', 'max:64'],
        ]);

        $billing = route('billing.index', ['client' => $client->slug]);

        $charge = DB::table('gateway_charges')
            ->where('reference', $validated['reference'])
            ->where('client_id', $client->id)
            ->first();

        if (! $charge) {
            return redirect($billing);
        }

        // Already settled by the webhook, which got there first. Nothing to do
        // but show the receipt.
        if ($charge->status === 'paid') {
            return redirect($billing . '?paid=' . urlencode($charge->reference));
        }

        try {
            $txn = $this->paddle->request('GET', '/transactions/' . $validated['transaction']);
        } catch (\Throwable $e) {
            Log::warning('paddle.confirm.unreachable', [
                'charge' => $charge->reference,
                'error'  => $e->getMessage(),
            ]);

            return redirect($billing)->with('info',
                'Thanks — we are confirming your payment. Your plan will appear here shortly.');
        }

        // THE CHECK THAT MATTERS. Paddle's own copy of the transaction must name
        // our charge; otherwise this is somebody else's payment being offered as
        // proof of ours.
        $named = (string) data_get($txn, 'data.custom_data.reference', '');

        if ($named !== $charge->reference) {
            Log::warning('paddle.confirm.reference_mismatch', [
                'charge'      => $charge->reference,
                'transaction' => $validated['transaction'],
                'named'       => $named,
            ]);

            return redirect($billing);
        }

        $status = (string) data_get($txn, 'data.status', '');

        if (! in_array($status, ['completed', 'paid'], true)) {
            return redirect($billing)->with('info',
                'Thanks — your payment is still being processed. Your plan will appear here shortly.');
        }

        // Paddle says paid, and it is ours. Applied through exactly the same
        // path the webhook uses, so a later webhook for the same transaction
        // finds the charge already settled and does nothing.
        $this->transactionPaid((array) data_get($txn, 'data', []));

        return redirect($billing . '?paid=' . urlencode($charge->reference));
    }

    // ── Events ───────────────────────────────────────────────────────

    /**
     * Money arrived: put the workspace on the plan.
     *
     * Two shapes reach here and they need different handling. A FIRST purchase
     * carries our own reference in `custom_data`, so the charge row we wrote
     * before the customer left is waiting. A RENEWAL carries no reference of
     * ours at all — only Paddle's subscription id — so the charge row has to be
     * created here, after the fact, from what Paddle says was billed.
     */
    private function transactionPaid(array $data): void
    {
        $reference      = (string) data_get($data, 'custom_data.reference', '');
        $transactionId  = (string) data_get($data, 'id', '');
        $subscriptionId = (string) data_get($data, 'subscription_id', '');

        $charge = $reference !== ''
            ? DB::table('gateway_charges')->where('gateway', 'paddle')->where('reference', $reference)->first()
            : null;

        if (! $charge && $subscriptionId !== '') {
            $charge = $this->chargeForRenewal($data, $subscriptionId, $transactionId);
        }

        if (! $charge) {
            Log::warning('paddle.webhook.unmatched_transaction', [
                'transaction' => $transactionId,
                'reference'   => $reference,
                'subscription'=> $subscriptionId,
            ]);

            return;
        }

        // Conditional update, not read-then-write. Paddle retries, and a retry
        // arriving beside the first delivery would otherwise extend the period
        // twice for one payment.
        $claimed = DB::table('gateway_charges')
            ->where('id', $charge->id)
            ->where('status', 'pending')
            ->update([
                'status'                 => 'paid',
                'gateway_ref'            => $transactionId,
                'paddle_subscription_id' => $subscriptionId ?: null,
                'raw'                    => json_encode($data),
                'paid_at'                => now(),
                'updated_at'             => now(),
            ]);

        if (! $claimed) {
            return;   // already handled
        }

        $subscription = app(GatewayCheckoutService::class)->applyPaidCharge(
            DB::table('gateway_charges')->find($charge->id)
        );

        if (! $subscription) {
            return;
        }

        // Paddle's ids are how every future renewal finds its way home, and how
        // a second purchase reuses the customer's saved cards.
        $subscription->forceFill(array_filter([
            'paddle_subscription_id' => $subscriptionId ?: null,
            'paddle_customer_id'     => (string) data_get($data, 'customer_id', '') ?: null,
        ]))->save();

        if (! $charge->subscription_id) {
            DB::table('gateway_charges')->where('id', $charge->id)->update([
                'subscription_id' => $subscription->id,
                'updated_at'      => now(),
            ]);
        }
    }

    /**
     * A charge row for a renewal nobody here initiated.
     *
     * Written rather than skipped because the payment history is what a billing
     * page shows and a support question is answered from — a renewal that
     * leaves no row makes a year of payments invisible.
     *
     * Returns null when the subscription is not one of ours, which is the
     * normal case for an account shared with another product.
     */
    private function chargeForRenewal(array $data, string $subscriptionId, string $transactionId): ?object
    {
        $subscription = Subscription::where('paddle_subscription_id', $subscriptionId)->first();

        if (! $subscription) {
            return null;
        }

        // Paddle's own billing period is authoritative for a renewal: it
        // decided when this period runs to, and inventing our own dates would
        // drift from the invoice the customer holds.
        $start = data_get($data, 'billing_period.starts_at');
        $end   = data_get($data, 'billing_period.ends_at');

        $id = DB::table('gateway_charges')->insertGetId([
            'client_id'              => $subscription->client_id,
            'subscription_id'        => $subscription->id,
            'plan_price_id'          => $subscription->plan_price_id,
            'gateway'                => 'paddle',
            'reference'              => 'PDL-' . $transactionId,
            'paddle_subscription_id' => $subscriptionId,
            'amount_cents'           => (int) data_get($data, 'details.totals.grand_total', $subscription->unit_amount),
            'currency'               => strtoupper((string) data_get($data, 'currency_code', $subscription->currency ?: 'USD')),
            'status'                 => 'pending',
            'interval'               => $subscription->interval,
            'period_start'           => $start ? \Illuminate\Support\Carbon::parse($start) : now(),
            'period_end'             => $end ? \Illuminate\Support\Carbon::parse($end) : now()->addMonths($subscription->interval === 'annually' ? 12 : 1),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        Log::info('paddle.renewal.charge_created', [
            'subscription' => $subscription->id,
            'transaction'  => $transactionId,
        ]);

        return DB::table('gateway_charges')->find($id);
    }

    /**
     * Keep our record of the period in step with Paddle's.
     *
     * Paddle owns the schedule for its subscriptions, so when it moves a
     * renewal date — a pause, a plan change made in their portal, a dunning
     * retry — ours has to follow or the workspace is cut off while Paddle
     * happily keeps billing.
     */
    private function subscriptionSynced(array $data): void
    {
        $paddleId = (string) data_get($data, 'id', '');

        if ($paddleId === '') {
            return;
        }

        $subscription = Subscription::where('paddle_subscription_id', $paddleId)->first();

        if (! $subscription) {
            // The first `subscription.created` usually arrives BEFORE the
            // transaction that pays for it, so there is nothing to link yet.
            // Not an error: transactionPaid() stores the id a moment later.
            return;
        }

        $end = data_get($data, 'current_billing_period.ends_at');

        $subscription->forceFill(array_filter([
            'current_period_end' => $end ? \Illuminate\Support\Carbon::parse($end) : null,
            'status'             => $this->statusFor((string) data_get($data, 'status', '')),
        ]))->save();
    }

    private function subscriptionCanceled(array $data): void
    {
        $paddleId = (string) data_get($data, 'id', '');

        $subscription = Subscription::where('paddle_subscription_id', $paddleId)->first();

        if (! $subscription) {
            return;
        }

        // Access is NOT withdrawn here. They paid for a period and that period
        // has not ended; cutting them off at cancellation would take away time
        // already bought. The scheduled expiry job retires it when it lapses.
        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'canceled_at'          => now(),
            'ends_at'              => $subscription->current_period_end,
        ])->save();

        Log::info('paddle.subscription.canceled', [
            'subscription' => $subscription->id,
            'until'        => (string) $subscription->current_period_end,
        ]);
    }

    /** Paddle's subscription status in our vocabulary, or null to leave it alone. */
    private function statusFor(string $paddle): ?string
    {
        return match ($paddle) {
            'active'   => Subscription::STATUS_ACTIVE,
            'trialing' => Subscription::STATUS_TRIALING,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'paused'   => Subscription::STATUS_PAUSED,
            'canceled' => Subscription::STATUS_CANCELED,
            default    => null,
        };
    }
}
