<?php

namespace App\Console\Commands;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Services\Billing\Gateways\PaddleGateway;
use Illuminate\Console\Command;

/**
 * Prove the Paddle integration works before a customer meets it.
 *
 * Exists for the same reason the Safepay one does. Four things in that
 * integration were written from documentation rather than from a working call,
 * and every one of them was wrong — including a signature scheme that would
 * have rejected every genuine delivery while logging it as an attack. One real
 * call catches what no amount of reading does.
 *
 * What it checks, in the order the things actually break:
 *
 *   1. Both credentials present, and not swapped — an API key in the client
 *      token field would be rendered into every checkout page.
 *   2. The API answers, and the key is for the environment we think it is.
 *   3. The signature scheme, verified against a delivery signed here — the
 *      timestamp tolerance included, because Paddle's own five-second
 *      suggestion rejects every retry.
 *   4. Which sellable prices have no Paddle price, which is the failure a
 *      customer meets as "this plan cannot be bought".
 *
 * Deliberately a command rather than a test: it talks to a third party over the
 * network, so it belongs where someone runs it on purpose.
 */
class PaddleDoctor extends Command
{
    protected $signature = 'paddle:doctor';

    protected $description = 'Check Paddle credentials, connectivity, signatures and the price catalogue';

    public function handle(PaddleGateway $paddle): int
    {
        $sandbox = (bool) config('billing.paddle.sandbox');
        $ok      = true;

        $this->line('');
        $this->line('<options=bold>Paddle configuration</>');

        $this->table(['Setting', 'Value'], [
            ['Environment', $sandbox ? 'sandbox' : '<fg=red;options=bold>PRODUCTION</>'],
            ['API base', $paddle->baseUrl()],
            ['API key (secret)', $this->hint(config('billing.paddle.api_key'))],
            ['Client token (public)', $this->hint(config('billing.paddle.client_token'))],
            ['Webhook secret', $this->hint(config('billing.paddle.webhook_secret'))],
            ['Signature tolerance', config('billing.paddle.signature_tolerance') . 's'],
            ['Tax category', config('billing.paddle.tax_category')],
            ['Webhook URL', url('/billing/paddle/webhook')],
            ['Default payment link', route('paddle.pay')],
        ]);

        if (! $paddle->isConfigured()) {
            $this->error('Not configured. Set PADDLE_API_KEY and PADDLE_CLIENT_TOKEN.');

            return self::FAILURE;
        }

        // ── The credentials, before anything is sent ─────────────────
        $this->line('');
        $this->line('<options=bold>Credentials</>');

        $apiKey = (string) config('billing.paddle.api_key');
        $token  = (string) config('billing.paddle.client_token');

        // Paddle prefixes them differently, so a swap is detectable — and a
        // swap is the mistake that puts a secret key in a public page.
        $ok = $this->result(
            'The API key looks like a server key, not a client token',
            ! str_starts_with($token, 'apikey_') && ! str_starts_with($apiKey, 'live_') && ! str_starts_with($apiKey, 'test_'),
            'The two values look swapped. The client token is the PUBLIC one and is rendered into the page.',
        ) && $ok;

        $ok = $this->result(
            'Key environment matches PADDLE_SANDBOX',
            $sandbox === (str_contains($apiKey, 'sdbx') || str_contains($token, 'test_')),
            $sandbox
                ? 'PADDLE_SANDBOX is true but these look like live credentials.'
                : 'PADDLE_SANDBOX is false but these look like sandbox credentials.',
        ) && $ok;

        // ── The signature scheme, which needs no network ─────────────
        $this->line('');
        $this->line('<options=bold>Webhook signatures</>');

        $secret = (string) config('billing.paddle.webhook_secret');

        if ($secret === '') {
            $this->line('  <fg=yellow>SKIP</> PADDLE_WEBHOOK_SECRET is not set — every delivery would be refused.');
            $ok = false;
        } else {
            $body = '{"event_type":"transaction.completed","data":{"id":"txn_doctor"}}';
            $ts   = time();
            $good = 'ts=' . $ts . ';h1=' . hash_hmac('sha256', $ts . ':' . $body, $secret);

            $ok = $this->result('A correct signature is accepted', $paddle->webhookValid($body, $good)) && $ok;
            $ok = $this->result('A wrong secret is rejected',
                ! $paddle->webhookValid($body, 'ts=' . $ts . ';h1=' . hash_hmac('sha256', $ts . ':' . $body, 'wrong'))) && $ok;
            $ok = $this->result('A signature for a different body is rejected',
                ! $paddle->webhookValid('{"tampered":true}', $good)) && $ok;
            $ok = $this->result('An unsigned delivery is rejected', ! $paddle->webhookValid($body, '')) && $ok;

            // The trap: Paddle's docs suggest five seconds, and a retry carries
            // the ORIGINAL timestamp — so a tight window rejects exactly the
            // delivery that matters most.
            $oldTs  = time() - 3600;
            $oldSig = 'ts=' . $oldTs . ';h1=' . hash_hmac('sha256', $oldTs . ':' . $body, $secret);

            $this->result(
                'An hour-old retry is still accepted',
                $paddle->webhookValid($body, $oldSig),
                'PADDLE_SIGNATURE_TOLERANCE is too tight — Paddle retries with the original timestamp, '
                . 'so this rejects every retry.',
            );
        }

        // ── The live call ────────────────────────────────────────────
        $this->line('');
        $this->line('<options=bold>API connectivity</>');

        try {
            $response = $paddle->request('GET', '/event-types');
            $count    = count((array) data_get($response, 'data', []));

            $this->result("Paddle answered — {$count} event types available", $count > 0);
        } catch (\Throwable $e) {
            $this->line('  <fg=red>FAIL</> ' . $e->getMessage());
            $this->line('');
            $this->comment('  Most likely one of:');
            $this->line('    • the API key is for the other environment');
            $this->line('    • PADDLE_SANDBOX does not match the key');
            $this->line('    • the key was revoked or has no read permission');

            return self::FAILURE;
        }

        // ── The catalogue ────────────────────────────────────────────
        $this->line('');
        $this->line('<options=bold>Price catalogue</>');

        $currency = strtoupper((string) config('billing.currency', 'usd'));
        $missing  = [];
        $rows     = [];

        Plan::where('is_active', true)->whereIn('type', ['standard', 'custom', 'addon'])
            ->with('prices')->ordered()->get()
            ->each(function (Plan $plan) use ($currency, &$missing, &$rows) {
                $plan->prices->where('is_active', true)
                    ->filter(fn (PlanPrice $p) => strtoupper((string) $p->currency) === $currency)
                    ->each(function (PlanPrice $price) use ($plan, &$missing, &$rows) {
                        $has = $price->isSyncedToPaddle();

                        $rows[] = [
                            $plan->slug,
                            $price->interval,
                            $price->formatted(),
                            $has ? $price->paddle_price_id : '<fg=red>missing</>',
                        ];

                        if (! $has) {
                            $missing[] = "{$plan->slug} {$price->interval}";
                        }
                    });
            });

        if ($rows === []) {
            $this->warn('  No sellable ' . $currency . ' prices found at all.');

            return self::FAILURE;
        }

        $this->table(['Plan', 'Interval', 'Price', 'Paddle price id'], $rows);

        if ($missing !== []) {
            $this->line('  <fg=red>' . count($missing) . ' price(s) cannot be sold through Paddle:</> ' . implode(', ', $missing));
            $this->line('  Run <options=bold>php artisan paddle:sync</> to create them.');
            $ok = false;
        } else {
            $this->line('  <fg=green>PASS</> every sellable price has a Paddle price');
        }

        // ── The checkout itself ──────────────────────────────────────
        //
        // Everything above can pass while the thing a customer actually does
        // still fails. Paddle refuses to create ANY transaction until a default
        // payment link is set on the account — an account-level setting nothing
        // else reveals, whose only symptom is a Pay button that does nothing.
        // So the last check is the real one: open a checkout.
        $this->line('');
        $this->line('<options=bold>Checkout</>');

        $sellable = \App\Models\Billing\PlanPrice::query()
            ->whereNotNull('paddle_price_id')
            ->whereHas('plan', fn ($q) => $q->where('type', 'standard'))
            ->first();

        if (! $sellable) {
            $this->line('  <fg=yellow>SKIP</> no synced price to try a checkout with.');
        } else {
            try {
                $handoff = $paddle->startCheckout(
                    \App\Models\Client::first() ?? new \App\Models\Client(['name' => 'Doctor']),
                    $sellable,
                    ['basket_id' => 'DOCTOR-' . now()->timestamp],
                );

                $this->result('A checkout can be opened — ' . $handoff->reference, true);

                // Draft, unpaid, and harmless. Worth one line so nobody
                // wonders where it came from.
                $this->line('       <fg=gray>(a draft transaction, never paid — safe to ignore or delete)</>');
            } catch (\Throwable $e) {
                $ok = false;
                $this->line('  <fg=red>FAIL</> ' . $e->getMessage());

                if (str_contains($e->getMessage(), 'default payment link')) {
                    $this->line('');
                    $this->line('       <fg=yellow>Paddle Dashboard → Checkout → Checkout settings →</>');
                    $this->line('       <fg=yellow>Default payment link. Set it to exactly:</>');
                    $this->line('       <fg=cyan;options=bold>' . route('paddle.pay') . '</>');
                    $this->line('       <fg=yellow>That page loads Paddle.js and opens the checkout from</>');
                    $this->line('       <fg=yellow>the ?_ptxn Paddle appends. Paddle will not create ANY</>');
                    $this->line('       <fg=yellow>transaction until it is set.</>');
                }
            }
        }

        $this->line('');

        if ($ok) {
            $this->info('  Paddle looks ready.');
            $this->line('');
            $this->comment('  Last step, and it is not optional — register the webhook in Paddle:');
            $this->comment('    ' . url('/billing/paddle/webhook'));
            $this->comment('  Without it a customer can pay and never be given the plan.');
        } else {
            $this->warn('  Fix the failures above before selling through Paddle.');
        }

        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function result(string $label, bool $passed, ?string $hint = null): bool
    {
        $this->line(sprintf('  %s %s', $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $label));

        if (! $passed && $hint) {
            $this->line('       <fg=yellow>' . $hint . '</>');
        }

        return $passed;
    }

    /** Never print a secret — only enough to tell one from another. */
    private function hint(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '<fg=red>not set</>';
        }

        return mb_substr($value, 0, 10) . '…' . mb_substr($value, -4) . '  (' . mb_strlen($value) . ' chars)';
    }
}
