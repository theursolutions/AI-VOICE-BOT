<?php

namespace App\Mail;

use App\Models\Billing\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your plan needs renewing" — for customers whose gateway cannot charge them
 * automatically.
 *
 * Never sent to a Stripe customer. Their renewal happens on its own, and a
 * warning about a charge they already authorised is noise that teaches people
 * to ignore billing email — which is expensive the one time it matters.
 *
 * The tone shifts with how close the date is, because three identical messages
 * are one message sent three times. Seven days out is information; the last day
 * is the only one that says the service will stop.
 */
class RenewalDueMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $daysBefore,
    ) {
    }

    public function envelope(): Envelope
    {
        $plan = $this->subscription->plan?->name ?? 'your plan';

        return new Envelope(
            subject: match (true) {
                $this->daysBefore <= 1 => "Last day: renew {$plan} to keep your AI running",
                $this->daysBefore <= 3 => "{$plan} renews in {$this->daysBefore} days",
                default                => "Your {$plan} plan renews soon",
            },
        );
    }

    public function content(): Content
    {
        $client = $this->subscription->client;
        $price  = $this->subscription->planPrice;

        return new Content(
            markdown: 'emails.billing.renewal-due',
            with: [
                'client'     => $client,
                'planName'   => $this->subscription->plan?->name ?? 'your plan',
                'days'       => $this->daysBefore,
                'endsAt'     => $this->subscription->current_period_end,
                // Formatted here, not in the template: amounts are integer
                // cents everywhere and must not meet a float in a Blade file.
                'amount'     => $price
                    ? number_format($price->unit_amount / 100, 0)
                    : null,
                'currency'   => strtoupper((string) ($price->currency ?? 'PKR')),
                'interval'   => $price?->interval === 'annually' ? 'year' : 'month',
                'renewUrl'   => $client
                    ? route('billing.index', ['client' => $client->slug])
                    : url('/'),
            ],
        );
    }
}
