<?php

namespace App\Console\Commands;

use App\Services\Billing\Gateways\SafepayGateway;
use Illuminate\Console\Command;

/**
 * Prove the Safepay integration works before a customer meets it.
 *
 * Exists because three things in this integration were written from
 * documentation rather than from a working call: the endpoint paths, the
 * response envelope the tracker arrives in, and — most dangerously — whether
 * amounts are quoted in rupees or paisa. Getting the last one wrong charges a
 * hundred times the price, and no amount of code review catches it. One sandbox
 * call does.
 *
 * Deliberately a command rather than a test: it talks to a third party over the
 * network, so it belongs where someone runs it on purpose, not in a suite that
 * should pass on a train.
 */
class SafepayDoctor extends Command
{
    protected $signature = 'safepay:doctor {--amount=7500 : Price in minor units (7500 = Rs 75)}';

    protected $description = 'Check Safepay credentials, endpoints and the amount unit against the sandbox';

    public function handle(SafepayGateway $safepay): int
    {
        $this->line('');
        $this->line('<options=bold>Safepay configuration</>');

        $sandbox = (bool) config('billing.safepay.sandbox');

        $this->table(['Setting', 'Value'], [
            ['Environment', $sandbox ? 'sandbox' : '<fg=red;options=bold>PRODUCTION</>'],
            ['Base URL', $safepay->baseUrl()],
            ['API key', $this->hint(config('billing.safepay.api_key'))],
            ['v1 secret', $this->hint(config('billing.safepay.v1_secret'))],
            ['Webhook secret', $this->hint(config('billing.safepay.webhook_secret'))],
            ['Amount unit', config('billing.safepay.amount_unit')],
            ['Session path', config('billing.safepay.paths.session')],
            ['Checkout path', config('billing.safepay.paths.checkout')],
        ]);

        if (! $safepay->isConfigured()) {
            $this->error('Not configured. Set SAFEPAY_API_KEY and SAFEPAY_V1_SECRET.');

            return self::FAILURE;
        }

        if (! $sandbox && ! $this->confirm('This is PRODUCTION. Really create a live payment session?', false)) {
            return self::SUCCESS;
        }

        // ── The signature scheme, which needs no network ─────────────────
        $this->line('');
        $this->line('<options=bold>Signature verification</>');

        $tracker = 'doctor-' . bin2hex(random_bytes(6));
        $good    = hash_hmac('sha256', $tracker, (string) config('billing.safepay.v1_secret'));

        $this->result('A correct signature is accepted', $safepay->signatureValid($tracker, $good));
        $this->result('A wrong signature is rejected', ! $safepay->signatureValid($tracker, $good . 'x'));
        $this->result('An empty signature is rejected', ! $safepay->signatureValid($tracker, ''));

        // ── The live call ────────────────────────────────────────────────
        $minor  = (int) $this->option('amount');
        $rupees = (int) round($minor / 100);

        $this->line('');
        $this->line('<options=bold>Payment session</>');
        $this->line(sprintf(
            '  Asking for a plan priced at <options=bold>Rs %s</> (%d in minor units).',
            number_format($rupees), $minor
        ));
        $this->line('  Sending <options=bold>' . (config('billing.safepay.amount_unit') === 'paisa' ? $minor : $rupees)
            . '</> as the amount, because amount_unit is ' . config('billing.safepay.amount_unit') . '.');

        try {
            $price = new \App\Models\Billing\PlanPrice([
                'unit_amount' => $minor,
                'currency'    => 'pkr',
                'interval'    => 'monthly',
            ]);

            $handoff = $safepay->startCheckout(
                new \App\Models\Client(['name' => 'Doctor', 'billing_email' => 'doctor@example.com']),
                $price,
                [
                    'basket_id'   => 'DOCTOR-' . now()->timestamp,
                    'success_url' => url('/billing/safepay/return'),
                    'cancel_url'  => url('/billing'),
                ],
            );
        } catch (\Throwable $e) {
            $this->line('');
            $this->error('Could not create a session: ' . $e->getMessage());
            $this->line('');
            $this->comment('Most likely one of:');
            $this->line('  • SAFEPAY_SESSION_PATH is wrong — check their current API reference');
            $this->line('  • the API key is for the other environment');
            $this->line('  • the response envelope changed; the raw body is in the message above');

            return self::FAILURE;
        }

        $this->result('Session created and a tracker came back', true);
        $this->line('');
        $this->line('  Open this and confirm the amount reads <options=bold>Rs ' . number_format($rupees) . '</>:');
        $this->line('  <fg=cyan>' . $handoff->url . '</>');
        $this->line('');
        $this->warn('  If that page shows Rs ' . number_format($rupees * 100) . ' instead, set SAFEPAY_AMOUNT_UNIT=paisa.');
        $this->warn('  Do not go live until this figure is right — the error is a factor of 100.');
        $this->line('');
        $this->comment('  Then pay it with a sandbox instrument and check that the redirect back');
        $this->comment('  carries `tracker` and `sig`, and that the sig verifies.');

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
