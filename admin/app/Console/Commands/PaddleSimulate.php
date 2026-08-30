<?php

namespace App\Console\Commands;

use App\Models\Billing\Subscription;
use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deliver a correctly-signed Paddle webhook to this application, locally.
 *
 * The webhook is the one part of the integration that cannot be tested from a
 * laptop: it is server-to-server, and Paddle's servers cannot reach
 * `localhost`. The usual answer is a tunnel, which works but means a real
 * sandbox payment for every attempt and a URL that changes each restart.
 *
 * This is the other half. The signature is an HMAC under a secret we hold, so a
 * delivery indistinguishable from Paddle's own can be produced here — which
 * exercises the route, the signature check, the charge record, the plan
 * application and the entitlement flush, against real rows, with no network.
 *
 * What it does NOT prove is that Paddle sends the fields this assumes. Only a
 * tunnelled sandbox payment shows that, and it is worth doing once; after that
 * this is the faster loop.
 */
class PaddleSimulate extends Command
{
    protected $signature = 'paddle:simulate
                            {--charge= : An existing gateway_charges.reference to pay}
                            {--client= : Workspace id or slug, when creating a test charge}
                            {--renewal : Send a renewal — no reference of ours, only a subscription id}
                            {--tamper : Send a deliberately wrong signature, to prove it is refused}';

    protected $description = 'Post a signed Paddle webhook at this app, without touching the network';

    public function handle(): int
    {
        $secret = (string) config('billing.paddle.webhook_secret');

        if ($secret === '') {
            $this->error('PADDLE_WEBHOOK_SECRET is not set — there is nothing to sign with.');

            return self::FAILURE;
        }

        return $this->option('renewal')
            ? $this->simulateRenewal($secret)
            : $this->simulateFirstPayment($secret);
    }

    /**
     * The first payment: our reference travels in `custom_data` and the charge
     * row is already waiting.
     */
    private function simulateFirstPayment(string $secret): int
    {
        $charge = $this->option('charge')
            ? DB::table('gateway_charges')->where('reference', $this->option('charge'))->first()
            : $this->createCharge();

        if (! $charge) {
            $this->error('No such charge. Pass --charge=<reference>, or --client to create one.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  Charge     <options=bold>{$charge->reference}</>");
        $this->line("  Status     {$charge->status}");
        $this->line('  Amount     ' . number_format($charge->amount_cents / 100, 2) . ' ' . $charge->currency);
        $this->line('  Period to  ' . ($charge->period_end ?? '(none)'));

        $transactionId  = 'txn_sim_' . bin2hex(random_bytes(6));
        $subscriptionId = 'sub_sim_' . bin2hex(random_bytes(6));

        $payload = [
            'event_type' => 'transaction.completed',
            'data' => [
                'id'              => $transactionId,
                'status'          => 'completed',
                'subscription_id' => $subscriptionId,
                'customer_id'     => 'ctm_sim_' . bin2hex(random_bytes(4)),
                'currency_code'   => $charge->currency,
                'custom_data'     => ['reference' => $charge->reference],
                'details'         => ['totals' => ['grand_total' => (int) $charge->amount_cents]],
            ],
        ];

        $response = $this->deliver($payload, $secret);

        $after = DB::table('gateway_charges')->find($charge->id);

        $this->line('');
        $this->line('  Charge is now <options=bold>' . $after->status . '</>'
            . ($after->paid_at ? ' (paid at ' . $after->paid_at . ')' : ''));

        if ($this->option('tamper')) {
            $ok = $response->getStatusCode() === 400 && $after->status === 'pending';

            $this->line('');
            $this->line($ok
                ? '  <fg=green>PASS</> the forged delivery was refused and nothing changed'
                : '  <fg=red>FAIL</> a forged delivery was accepted — stop and fix this before going live');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->reportSubscription($after->subscription_id);

        return self::SUCCESS;
    }

    /**
     * A renewal, which is the delivery most likely to be wrong.
     *
     * Paddle bills its own subscriptions, so a year after the purchase a
     * transaction arrives carrying NO reference of ours — only Paddle's
     * subscription id. If that cannot be mapped back to a workspace, the
     * customer is charged and quietly loses access, which is the worst outcome
     * this integration can produce.
     */
    private function simulateRenewal(string $secret): int
    {
        $subscription = Subscription::whereNotNull('paddle_subscription_id')->first();

        if (! $subscription) {
            $this->error('No subscription carries a Paddle id yet. Run this without --renewal first.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  Renewing subscription <options=bold>' . $subscription->id . '</>');
        $this->line('  Paddle id  ' . $subscription->paddle_subscription_id);
        $this->line('  Paid until ' . $subscription->current_period_end);

        // Deliberately a DISTINCT date, not simply "now + a month". Two runs a
        // few seconds apart both round to the same minute, and a check that the
        // period "moved" then reports a working renewal as broken — which is
        // exactly what it did the first time this ran.
        $end = now()
            ->addMonths($subscription->interval === 'annually' ? 12 : 1)
            ->addDays(3)
            ->startOfMinute();

        $payload = [
            'event_type' => 'transaction.completed',
            'data' => [
                'id'              => 'txn_sim_' . bin2hex(random_bytes(6)),
                'status'          => 'completed',
                'subscription_id' => $subscription->paddle_subscription_id,
                'currency_code'   => strtoupper((string) $subscription->currency),
                // No custom_data at all — exactly as a real renewal arrives.
                'billing_period'  => [
                    'starts_at' => now()->startOfMinute()->toIso8601String(),
                    'ends_at'   => $end->toIso8601String(),
                ],
                'details' => ['totals' => ['grand_total' => (int) $subscription->unit_amount]],
            ],
        ];

        $transactionId = $payload['data']['id'];

        $this->deliver($payload, $secret);

        $fresh = $subscription->fresh();

        // Checked against the charge row rather than against "the date changed".
        // A renewal that lands in the same minute as the last one produces an
        // identical date and would look like a failure; what actually matters is
        // that a paid charge exists for this transaction and the subscription
        // now runs to the period Paddle billed for.
        $charge = DB::table('gateway_charges')
            ->where('gateway_ref', $transactionId)
            ->first();

        $this->line('');
        $this->line('  Paid until is now <options=bold>' . $fresh->current_period_end . '</>');
        $this->line('  Charge recorded: ' . ($charge ? $charge->reference . ' (' . $charge->status . ')' : '<fg=red>none</>'));

        $matched = $charge
            && $charge->status === 'paid'
            && (int) $charge->subscription_id === (int) $subscription->id
            && $fresh->current_period_end?->toDateTimeString() === $end->toDateTimeString();

        $this->line('');
        $this->line($matched
            ? '  <fg=green>PASS</> the renewal was matched by its Paddle subscription id, recorded, and applied'
            : '  <fg=red>FAIL</> the renewal was not matched — a real one would charge the customer '
              . 'and leave them cut off');

        return $matched ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Sign the way Paddle signs and dispatch through the HTTP kernel.
     *
     * Through the kernel rather than curl: no web server needs to be running,
     * and the route, middleware and controller are the real ones.
     */
    private function deliver(array $payload, string $secret)
    {
        $raw = json_encode($payload);
        $ts  = time();

        // HMAC-SHA256 over "{ts}:{raw body}". The tamper path signs different
        // bytes — what a forgery looks like — so the refusal is observed rather
        // than assumed.
        $signed = $this->option('tamper') ? $ts . ':' . $raw . 'tampered' : $ts . ':' . $raw;

        $this->line('');
        $this->line($this->option('tamper')
            ? '  <fg=yellow>Sending a DELIBERATELY WRONG signature.</>'
            : '  Sending a correctly signed delivery.');

        $request = \Illuminate\Http\Request::create(
            '/billing/paddle/webhook',
            'POST',
            [], [], [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_PADDLE_SIGNATURE' => 'ts=' . $ts . ';h1=' . hash_hmac('sha256', $signed, $secret),
            ],
            $raw,
        );

        $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        $this->line('');
        $this->line('  HTTP ' . $response->getStatusCode() . '  ' . $response->getContent());

        return $response;
    }

    private function reportSubscription($id): void
    {
        if (! $id) {
            return;
        }

        $sub = Subscription::find($id);

        if (! $sub) {
            return;
        }

        $this->line('  Subscription ' . $sub->id . ' is ' . $sub->status
            . ' on ' . ($sub->plan?->slug ?? '?')
            . ', paid until ' . $sub->current_period_end);
        $this->line('  Paddle ids: subscription=' . ($sub->paddle_subscription_id ?: '—')
            . ' customer=' . ($sub->paddle_customer_id ?: '—'));
    }

    /** A pending charge to pay, mirroring what the real checkout writes. */
    private function createCharge(): ?object
    {
        $client = $this->option('client')
            ? Client::where('slug', $this->option('client'))->orWhere('id', $this->option('client'))->first()
            : Client::first();

        if (! $client) {
            return null;
        }

        $currency = strtoupper((string) config('billing.currency', 'usd'));

        $price = \App\Models\Billing\PlanPrice::whereHas('plan', fn ($q) => $q->where('type', 'standard'))
            ->where('currency', strtolower($currency))
            ->where('is_active', true)
            ->where('interval', 'monthly')
            ->first();

        if (! $price) {
            $this->error("No active {$currency} monthly price to sell.");

            return null;
        }

        $reference = 'SIM-' . strtoupper(bin2hex(random_bytes(5)));

        $id = DB::table('gateway_charges')->insertGetId([
            'client_id'       => $client->id,
            'subscription_id' => $client->currentSubscription()?->id,
            'plan_price_id'   => $price->id,
            'gateway'         => 'paddle',
            'reference'       => $reference,
            'amount_cents'    => $price->unit_amount,
            'currency'        => $currency,
            'status'          => 'pending',
            'interval'        => 'monthly',
            'period_start'    => now()->startOfMinute(),
            'period_end'      => now()->addMonth()->startOfMinute(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->info("Created a pending {$price->formatted()} charge {$reference} for {$client->name}.");

        return DB::table('gateway_charges')->find($id);
    }
}
