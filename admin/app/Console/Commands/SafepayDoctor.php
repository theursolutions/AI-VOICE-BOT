<?php

namespace App\Console\Commands;

use App\Services\Billing\Gateways\SafepayGateway;
use Illuminate\Console\Command;

/**
 * Prove the Safepay integration works before a customer meets it.
 *
 * Exists because the things most likely to break are the ones no code review
 * catches: credentials for the wrong environment, an endpoint that has moved,
 * and — most dangerously — the unit amounts are quoted in. That unit changed
 * between v1 (rupees) and Payments 2.0 (paisa), and getting it wrong is a
 * factor of a hundred that Safepay's API will happily echo back. One sandbox
 * checkout, read by a human, settles it.
 *
 * Deliberately a command rather than a test: it talks to a third party over the
 * network, so it belongs where someone runs it on purpose, not in a suite that
 * should pass on a train.
 */
class SafepayDoctor extends Command
{
    protected $signature = 'safepay:doctor {--amount=7500 : Price in minor units (7500 = Rs 75)}';

    protected $description = 'Check Safepay 2.0 credentials, endpoints and the amount unit against the sandbox';

    public function handle(SafepayGateway $safepay): int
    {
        $this->line('');
        $this->line('<options=bold>Safepay configuration (Payments 2.0)</>');

        $sandbox = (bool) config('billing.safepay.sandbox');

        $this->table(['Setting', 'Value'], [
            ['Environment', $sandbox ? 'sandbox' : '<fg=red;options=bold>PRODUCTION</>'],
            ['API base', $safepay->baseUrl()],
            ['Checkout page', $safepay->checkoutHost() . '/embedded'],
            ['API key (public)', $this->hint(config('billing.safepay.api_key'))],
            ['Secret key', $this->hint(config('billing.safepay.secret_key'))],
            ['Webhook secret', $this->hint(config('billing.safepay.webhook_secret'))],
            ['Webhook URL', route('safepay.webhook')],
        ]);

        if (! $safepay->isConfigured()) {
            $this->error('Not configured. Set SAFEPAY_API_KEY and SAFEPAY_SECRET_KEY.');

            return self::FAILURE;
        }

        if (! $sandbox && ! $this->confirm('This is PRODUCTION. Really open a live payment session?', false)) {
            return self::SUCCESS;
        }

        // ── Webhook signatures, which need no network ────────────────────
        $this->line('');
        $this->line('<options=bold>Webhook signature verification</>');

        if ((string) config('billing.safepay.webhook_secret') === '') {
            $this->warn('  SAFEPAY_WEBHOOK_SECRET is not set — every delivery will be refused.');
        } else {
            $secret = (string) config('billing.safepay.webhook_secret');
            $body   = json_encode([
                'token'   => 'evt_doctor',
                'version' => '2.0.0',
                'type'    => 'payment.succeeded',
                'data'    => ['tracker' => 'track_doctor', 'amount' => 7500, 'currency' => 'PKR', 'metadata' => (object) []],
            ], JSON_UNESCAPED_SLASHES);

            $this->result(
                'A 2.0 event signed over the whole payload is accepted',
                $safepay->webhookScheme($body, hash_hmac('sha512', $body, $secret)) === SafepayGateway::SIGNED_EVENT,
            );
            $this->result(
                'A tampered payload is refused',
                $safepay->webhookScheme(str_replace('7500', '100', $body), hash_hmac('sha512', $body, $secret)) === null,
            );
            $this->result(
                'A signature under the wrong secret is refused',
                $safepay->webhookScheme($body, hash_hmac('sha512', $body, $secret . 'x')) === null,
            );
            $this->result('An unsigned delivery is refused', $safepay->webhookScheme($body, '') === null);
        }

        // ── The live calls ───────────────────────────────────────────────
        $minor = (int) $this->option('amount');

        $this->line('');
        $this->line('<options=bold>Payment session</>');
        $this->line(sprintf(
            '  Opening a session for a plan priced at <options=bold>Rs %s</> — sent as <options=bold>%d</> (paisa).',
            number_format($minor / 100, 2), $minor
        ));

        try {
            $handoff = $safepay->startCheckout(
                new \App\Models\Client(['name' => 'Doctor', 'billing_email' => 'doctor@example.com']),
                new \App\Models\Billing\PlanPrice([
                    'unit_amount' => $minor,
                    'currency'    => 'pkr',
                    'interval'    => 'monthly',
                ]),
                [
                    'basket_id'   => 'DOCTOR-' . now()->timestamp,
                    'success_url' => route('safepay.return'),
                    'cancel_url'  => url('/billing'),
                ],
            );
        } catch (\Throwable $e) {
            $this->line('');
            $this->error('Could not open a session: ' . mb_substr($e->getMessage(), 0, 400));
            $this->line('');
            $this->comment('Most likely one of:');
            $this->line('  • the keys are for the other environment — sandbox and live issue different ones');
            $this->line('  • the secret key was rotated in the dashboard (old keys stop working at once)');
            $this->line('  • SAFEPAY_SANDBOX does not match the dashboard the keys came from');

            return self::FAILURE;
        }

        $this->result('Session opened, order attached and a checkout token issued', true);

        $lookup = $safepay->lookup((string) $handoff->gatewayRef);
        $held   = (int) data_get($lookup, 'purchase_totals.quote_amount.amount', -1);

        $this->result('The reporter API can read the session back', $lookup !== null);
        $this->result(
            "Safepay holds the amount that was sent ({$held})",
            $held === $minor,
        );
        $this->result(
            'Our order reference is recorded on the session',
            SafepayGateway::orderIdFrom($lookup['metadata'] ?? []) === $handoff->reference,
        );

        $this->line('');
        $this->line('  Open this and read the amount Safepay shows:');
        $this->line('  <fg=cyan>' . $handoff->url . '</>');
        $this->line('');
        $this->line('  <options=bold>Rs ' . number_format($minor / 100, 2) . '</>  correct.');
        $this->line('  <fg=red;options=bold>Rs ' . number_format($minor, 2) . '</>  WRONG — Safepay is reading paisa as rupees. Do not go live.');
        $this->line('');
        $this->comment('  Then pay it with a sandbox test card and check that:');
        $this->comment('   • you land back on /billing/safepay/return/' . $handoff->reference);
        $this->comment('   • the log shows safepay.webhook with scheme "event" — if it says "data", the');
        $this->comment('     endpoint is subscribed to 1.0.0 events; switch it to the 2.0.0 ones.');

        return self::SUCCESS;
    }

    private function result(string $label, bool $ok): void
    {
        $this->line(sprintf('  %s %s', $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $label));
    }

    /** Never print a secret — only enough to tell one from another. */
    private function hint(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '<fg=red>not set</>';
        }

        return mb_substr($value, 0, 8) . '…' . mb_substr($value, -4) . '  (' . mb_strlen($value) . ' chars)';
    }
}
