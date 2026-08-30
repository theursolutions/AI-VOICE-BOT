<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deliver a correctly-signed Safepay webhook to this application, locally.
 *
 * The webhook is the one part of the integration that cannot be tested from a
 * laptop: it is server-to-server, and Safepay's servers cannot reach
 * `localhost`. The usual answer is a tunnel, which works but means a real
 * sandbox payment for every attempt and a URL that changes each time you
 * restart it.
 *
 * This is the other half. Because the signature is an HMAC under a secret we
 * hold, a delivery indistinguishable from Safepay's own can be produced here —
 * so the controller, the signature check, the charge record and the period
 * extension are all exercised for real, against real rows, with no network at
 * all.
 *
 * What it does NOT prove is that Safepay sends the fields this assumes. Only a
 * tunnelled sandbox payment shows that, and it is worth doing once; after that
 * this is the faster loop.
 */
class SafepaySimulate extends Command
{
    protected $signature = 'safepay:simulate
                            {--charge= : An existing gateway_charges.reference to pay}
                            {--client= : Workspace id or slug, when creating a test charge}
                            {--amount=7500 : Amount in minor units for a created charge}
                            {--tamper : Send a deliberately wrong signature, to prove it is refused}';

    protected $description = 'Post a signed Safepay webhook at this app, without touching the network';

    public function handle(): int
    {
        $secret = (string) config('billing.safepay.webhook_secret');

        if ($secret === '') {
            $this->error('SAFEPAY_WEBHOOK_SECRET is not set — there is nothing to sign with.');

            return self::FAILURE;
        }

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
        $this->line('  Period to  ' . ($charge->period_end ?? '(none — will not extend a subscription)'));

        $data = [
            'tracker'  => 'trk_sim_' . bin2hex(random_bytes(4)),
            'order_id' => $charge->reference,
            'state'    => 'TRACKER_ENDED',
        ];

        $raw = json_encode(['type' => 'payment.succeeded', 'data' => $data]);

        // Signed the way Safepay signs: SHA-512 over the `data` object with
        // unescaped slashes. Using the redirect's SHA-256-over-raw-body scheme
        // here would make this command "pass" against an implementation that
        // rejects every real delivery.
        $canonical = json_encode($data, JSON_UNESCAPED_SLASHES);

        // The tamper path signs different bytes — what a forgery looks like — so
        // the refusal is observed rather than assumed.
        $signature = $this->option('tamper')
            ? hash_hmac('sha512', $canonical . 'tampered', $secret)
            : hash_hmac('sha512', $canonical, $secret);

        $this->line('');
        $this->line($this->option('tamper')
            ? '  <fg=yellow>Sending a DELIBERATELY WRONG signature.</>'
            : '  Sending a correctly signed delivery.');

        // Dispatched through the HTTP kernel rather than curl: no web server
        // needs to be running, and the route, middleware and controller are the
        // real ones.
        $request = \Illuminate\Http\Request::create(
            '/billing/safepay/webhook',
            'POST',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SFPY_SIGNATURE' => $signature],
            $raw,
        );

        $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        $this->line('');
        $this->line('  HTTP ' . $response->getStatusCode() . '  ' . $response->getContent());

        $after = DB::table('gateway_charges')->where('id', $charge->id)->first();

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

        if ($after->subscription_id) {
            $sub = DB::table('subscriptions')->find($after->subscription_id);
            $this->line('  Subscription ' . $sub->id . ' is ' . $sub->status
                . ', paid until ' . $sub->current_period_end);
        }

        return self::SUCCESS;
    }

    /**
     * A pending charge to pay, for when there is not already one lying around.
     *
     * Deliberately mirrors what the real checkout will write, so simulating a
     * payment exercises the same shape the live path produces.
     */
    private function createCharge(): ?object
    {
        $client = $this->option('client')
            ? \App\Models\Client::where('slug', $this->option('client'))
                ->orWhere('id', $this->option('client'))->first()
            : \App\Models\Client::first();

        if (! $client) {
            return null;
        }

        $subscription = $client->currentSubscription();
        $amount       = (int) $this->option('amount');

        $reference = 'SIM-' . strtoupper(bin2hex(random_bytes(5)));

        $id = DB::table('gateway_charges')->insertGetId([
            'client_id'       => $client->id,
            'subscription_id' => $subscription?->id,
            'plan_price_id'   => $subscription?->plan_price_id,
            'gateway'         => 'safepay',
            'reference'       => $reference,
            'amount_cents'    => $amount,
            'currency'        => 'PKR',
            'status'          => 'pending',
            'interval'        => $subscription?->interval ?? 'monthly',
            'period_start'    => now()->startOfMinute(),
            'period_end'      => now()->addMonth()->startOfMinute(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->info("Created a pending charge {$reference} for {$client->name}.");

        return DB::table('gateway_charges')->find($id);
    }
}
