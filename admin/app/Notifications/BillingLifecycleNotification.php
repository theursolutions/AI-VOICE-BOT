<?php

namespace App\Notifications;

use App\Models\Billing\Subscription;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every customer-facing billing email, in one class.
 *
 * WHY ONE CLASS: the cadence matters more than the individual message —
 * "3 days left", "your free week ended", "we couldn't take payment", "your
 * data is about to be deleted" have to read as one coherent sequence and
 * never contradict each other. Five separate notification classes drift.
 * The `stage` decides subject, heading and copy.
 *
 * Tone is deliberate: these are the emails most likely to make someone feel
 * cornered, so none of them threaten, and every one names what is still safe
 * and what the single next action is.
 */
class BillingLifecycleNotification extends Notification
{
    use Queueable;

    public const FREE_ENDING       = 'free_ending';
    public const FREE_ENDED        = 'free_ended';
    public const PAYMENT_FAILED    = 'payment_failed';
    public const PURGE_WARNING     = 'purge_warning';
    public const TRIAL_ENDING      = 'trial_ending';

    public function __construct(
        public readonly string $stage,
        public readonly Client $client,
        public readonly ?Subscription $subscription = null,
        public readonly array $context = [],
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $brand   = tva_setting('content.brand_name', 'Serve AI');
        $name    = trim((string) ($notifiable->name ?? ''));
        $first   = $name !== '' ? explode(' ', $name)[0] : '';
        $billing = route('billing.index', ['client' => $this->client->slug]);

        $copy = $this->copy($brand);

        return (new MailMessage)
            ->subject($copy['subject'])
            ->view('emails.billing.lifecycle', [
                'heading'     => $copy['heading'],
                'preheader'   => $copy['preheader'],
                'lines'       => $copy['lines'],
                'ctaLabel'    => $copy['cta'],
                'ctaUrl'      => $billing,
                'reassurance' => $copy['reassurance'] ?? null,
                'name'        => $first,
                'brand'       => $brand,
                'workspace'   => $this->client->name,
            ]);
    }

    /** @return array{subject:string,heading:string,preheader:string,lines:array<string>,cta:string,reassurance?:string} */
    private function copy(string $brand): array
    {
        $days      = (int) ($this->context['days'] ?? 0);
        $endsAt    = $this->subscription?->free_ends_at?->format('j F');
        $purgeAt   = $this->subscription?->purge_after?->format('j F Y');
        $workspace = $this->client->name;

        return match ($this->stage) {
            self::FREE_ENDING => [
                'subject'   => $days === 1
                    ? "Your {$brand} free access ends tomorrow"
                    : "{$days} days left of your {$brand} free access",
                'heading'   => $days === 1 ? 'Your free access ends tomorrow' : "{$days} days left",
                'preheader' => "Pick a plan to keep your agent answering. Nothing is deleted.",
                'lines'     => [
                    "Your free {$brand} access for <strong>{$workspace}</strong> ends"
                        . ($endsAt ? " on <strong>{$endsAt}</strong>" : ' shortly') . '.',
                    'Choose a plan before then and nothing changes — your agent keeps answering calls, chats and messages without a pause.',
                    'If you don’t, your workspace switches to read-only: you keep your login, your leads, your transcripts and your export, and your agent stops replying to new customers until you pick a plan.',
                ],
                'cta'         => 'Choose a plan',
                'reassurance' => 'Plans start at $19/month, charged in USD. Cancel any time.',
            ],

            self::FREE_ENDED => [
                'subject'   => "Your {$brand} free access has ended",
                'heading'   => 'Your agent is paused',
                'preheader' => 'Everything is saved. Pick a plan to switch it back on.',
                'lines'     => [
                    "The free period for <strong>{$workspace}</strong> has ended, so your agent has stopped replying to new customers.",
                    '<strong>Nothing has been deleted.</strong> Your leads, conversations, agents, data sources and settings are exactly as you left them, and you can still sign in and export everything.',
                    'Pick a plan and your agent is answering again within seconds — no setup to redo.',
                ],
                'cta'         => 'Reactivate my agent',
                'reassurance' => $purgeAt
                    ? "We keep your data until {$purgeAt}. We’ll remind you well before then."
                    : null,
            ],

            self::PAYMENT_FAILED => [
                'subject'   => "We couldn’t take your {$brand} payment",
                'heading'   => 'Your payment didn’t go through',
                'preheader' => 'Update your card to keep your agent answering.',
                'lines'     => [
                    "Your bank declined the last payment for <strong>{$workspace}</strong>. It happens — an expired card is the usual reason.",
                    'Your agent is <strong>still running</strong> for now. We’ll retry automatically over the next few days, and you can fix it instantly by updating your card.',
                ],
                'cta'         => 'Update payment method',
                'reassurance' => 'Card details are handled entirely by Stripe — we never see or store them.',
            ],

            self::PURGE_WARNING => [
                'subject'   => $days <= 1
                    ? "Last chance: your {$brand} data is deleted tomorrow"
                    : "Your {$brand} data will be deleted in {$days} days",
                'heading'   => 'Your data is scheduled for deletion',
                'preheader' => 'Reactivate or export before ' . ($purgeAt ?: 'the deadline') . '.',
                'lines'     => [
                    "<strong>{$workspace}</strong> has been inactive since its free period ended, so its data is scheduled for permanent deletion"
                        . ($purgeAt ? " on <strong>{$purgeAt}</strong>" : ' shortly') . '.',
                    'Two ways to keep it: pick a plan to reactivate everything, or sign in and export your leads and conversations. Either takes a couple of minutes.',
                    'After that date the data cannot be recovered.',
                ],
                'cta'         => 'Keep my data',
            ],

            self::TRIAL_ENDING => [
                'subject'   => "Your {$brand} trial ends in {$days} days",
                'heading'   => "Your trial ends in {$days} days",
                'preheader' => 'Your subscription will start automatically.',
                'lines'     => [
                    "Your trial for <strong>{$workspace}</strong> ends in {$days} days, and your subscription will start automatically — nothing for you to do.",
                    'If it isn’t for you, cancel before then and you won’t be charged.',
                ],
                'cta'         => 'View billing',
            ],

            default => [
                'subject'   => "{$brand} billing update",
                'heading'   => 'Billing update',
                'preheader' => 'There’s an update on your subscription.',
                'lines'     => ['There’s an update on your subscription.'],
                'cta'       => 'View billing',
            ],
        };
    }
}
