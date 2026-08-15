<?php

namespace App\Mail;

use App\Models\Billing\Plan;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The payment receipt, sent once Stripe confirms an invoice is PAID.
 *
 * Everything on it comes from the Stripe invoice rather than from our own
 * subscription mirror. The invoice is the financial record — it already
 * reflects proration, add-on lines, credits and tax exactly as charged, and a
 * receipt that disagreed with the card statement would be worse than none.
 *
 * Money is formatted here, never in the template: cents are integers all the
 * way through and must not meet a float in a Blade file.
 */
class InvoicePaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly array $invoice,
        public readonly ?Plan $plan = null,
        public readonly ?string $recipientName = null,
        public readonly ?string $cardLabel = null,
        public readonly ?Carbon $renewsOn = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $brand  = tva_setting('content.brand_name', 'Serve AI');
        $amount = $this->money($this->invoice['amount_paid'] ?? $this->invoice['total'] ?? 0);
        $what   = $this->plan?->name ?? 'your subscription';

        return new Envelope(
            // Billing mail comes from its own address, not the global
            // no-reply@: a receipt is the one message customers DO reply to
            // ("wrong card", "need a VAT number"), and those replies must
            // land with whoever handles billing.
            from: new Address(
                (string) config('billing.mail.from_address', 'billing@serveai.com.pk'),
                (string) config('billing.mail.from_name', $brand . ' Billing'),
            ),
            replyTo: array_filter([
                ($reply = config('billing.mail.reply_to'))
                    ? new Address((string) $reply, $brand . ' Billing')
                    : null,
            ]),
            subject: "Payment received — {$what} ({$amount})",
        );
    }

    public function content(): Content
    {
        $invoice  = $this->invoice;
        $currency = $invoice['currency'] ?? 'USD';

        $paidAt = $invoice['paid_at'] ?? $invoice['created'] ?? now();

        return new Content(
            view: 'emails.billing.invoice',
            with: [
                'heading'   => 'Payment received — thank you',
                'preheader' => sprintf(
                    'Your %s payment of %s has been received.',
                    $this->plan?->name ?? 'subscription',
                    $this->money($invoice['amount_paid'] ?? $invoice['total'] ?? 0),
                ),
                'name'  => $this->firstName(),
                'intro' => 'Thanks — we’ve received your payment. Here’s your receipt for your records.',

                'total'    => $this->money($invoice['amount_paid'] ?? $invoice['total'] ?? 0),
                'currency' => $currency,
                'paidOn'   => $paidAt instanceof Carbon ? $paidAt->format('j F Y') : (string) $paidAt,

                'number'        => $invoice['number'] ?: substr((string) $invoice['id'], 0, 20),
                'workspace'     => $this->client->name,
                'billedToEmail' => $invoice['customer_email'] ?? null,
                'periodLabel'   => $this->periodLabel(),

                'lines' => $this->lines(),

                'subtotal' => $this->money((int) ($invoice['subtotal'] ?? 0)),
                'tax'      => $this->money((int) ($invoice['tax'] ?? 0)),

                // Per-rate breakdown, with the cents already formatted.
                'taxLines' => array_map(fn (array $line) => [
                    'label'        => $line['label'],
                    'percentage'   => $line['percentage'] !== null ? rtrim(rtrim(number_format($line['percentage'], 2), '0'), '.') : null,
                    'jurisdiction' => $line['jurisdiction'],
                    'inclusive'    => $line['inclusive'],
                    'amount'       => $this->money((int) $line['amount']),
                ], $invoice['tax_lines'] ?? []),

                'taxNote'     => $invoice['tax_note'] ?? null,
                'taxIds'      => $invoice['tax_ids'] ?? [],
                'sellerTaxId' => tva_setting('content.tax_registration', '') ?: null,

                'cardLabel' => $this->cardLabel,

                'planName'      => $this->plan?->name,
                'intervalLabel' => $this->intervalLabel(),
                'renewsOn'      => $this->renewsOn?->format('j F Y'),
                'highlights'    => $this->highlights(),

                'invoiceUrl' => route('billing.invoice', [
                    'client'  => $this->client->slug,
                    'invoice' => $invoice['id'],
                ]),
                'pdfUrl'     => $invoice['pdf'] ?? null,
                'billingUrl' => route('billing.index', ['client' => $this->client->slug]),
            ],
        );
    }

    // ── Formatting ───────────────────────────────────────────────────

    private function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    private function firstName(): string
    {
        $name = trim((string) ($this->recipientName ?? ''));

        return $name !== '' ? explode(' ', $name)[0] : '';
    }

    private function periodLabel(): ?string
    {
        $start = $this->invoice['period_start'] ?? null;
        $end   = $this->invoice['period_end'] ?? null;

        if (! $start instanceof Carbon || ! $end instanceof Carbon) {
            return null;
        }

        return $start->format('j M Y') . ' – ' . $end->format('j M Y');
    }

    /** @return array<int,array{description:string,quantity:int,amount:string,period:?string}> */
    private function lines(): array
    {
        return array_map(function (array $line) {
            $start = $line['period_start'] ?? null;
            $end   = $line['period_end'] ?? null;

            return [
                'description' => $line['description'],
                'quantity'    => (int) $line['quantity'],
                'amount'      => $this->money((int) $line['amount']),
                'period'      => ($start instanceof Carbon && $end instanceof Carbon)
                    ? $start->format('j M') . ' – ' . $end->format('j M Y')
                    : null,
            ];
        }, $this->invoice['lines'] ?? []);
    }

    private function intervalLabel(): string
    {
        return match ($this->client->currentSubscription()?->interval) {
            'annually'  => 'Billed yearly',
            'quarterly' => 'Billed quarterly',
            default     => 'Billed monthly',
        };
    }

    /**
     * The headline entitlements, straight from the plan's feature matrix — so
     * an operator changing a limit in Super Admin changes what the receipt
     * says, with no code edit.
     *
     * @return array<int,string>
     */
    private function highlights(): array
    {
        if (! $this->plan) {
            return [];
        }

        try {
            return collect(app(\App\Services\Billing\PricingPresenter::class)->highlights($this->plan))
                ->pluck('label')
                ->filter()
                ->take(6)
                ->values()
                ->all();
        } catch (\Throwable) {
            // A receipt must never fail to send over a decorative list.
            return [];
        }
    }
}
