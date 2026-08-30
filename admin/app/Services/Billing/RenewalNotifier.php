<?php

namespace App\Services\Billing;

use App\Mail\RenewalDueMail;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Services\Billing\Gateways\GatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a customer their subscription is about to need paying again.
 *
 * ONLY for customers whose gateway cannot bill them itself. On Stripe the
 * renewal simply happens, and warning someone about a charge they have already
 * authorised is noise that trains them to ignore the channel. On a Pakistani
 * gateway with no reusable card token the customer must act, so silence is how
 * a paying subscription lapses.
 *
 * Three notices at widening gaps — 7, 3 and 1 days — on email and WhatsApp
 * together. The escalation is the point: sending three copies on the last day
 * would be one message repeated, not three chances to act.
 *
 * EVERY SEND IS RECORDED BEFORE IT IS ATTEMPTED, against a unique key of
 * subscription, period, offset and channel. The scheduler asks "whose period
 * ends in 7 days", which stays true all day, so without that guard a customer
 * gets the same reminder on every run. Claiming the slot first means a crash
 * mid-send costs one notice rather than producing a loop.
 */
class RenewalNotifier
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
    ) {
    }

    /**
     * Send whatever is due today.
     *
     * @return array{considered:int, sent:int, skipped:int}
     */
    public function run(?\DateTimeInterface $now = null): array
    {
        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();
        $offsets = $this->offsets();

        $considered = $sent = $skipped = 0;

        foreach ($offsets as $days) {
            // The window is a whole day, not an instant: the scheduler runs a
            // few times a day and a period ending at 14:05 must still be caught
            // by a run at 09:00.
            $from = $now->copy()->addDays($days)->startOfDay();
            $to   = $now->copy()->addDays($days)->endOfDay();

            $due = Subscription::query()
                ->with(['client', 'plan'])
                ->whereNotNull('current_period_end')
                ->whereBetween('current_period_end', [$from, $to])
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
                ->get();

            foreach ($due as $subscription) {
                $considered++;

                if (! $this->needsReminding($subscription)) {
                    $skipped++;
                    continue;
                }

                $sent += $this->notify($subscription, $days);
            }
        }

        return compact('considered', 'sent', 'skipped');
    }

    /**
     * Does this subscription need a human to do something?
     *
     * No for anything the gateway renews itself, and no for a complimentary
     * plan — telling someone their free plan is about to expire when it is not
     * going to expire would be alarming and untrue.
     */
    public function needsReminding(Subscription $subscription): bool
    {
        $client = $subscription->client;

        if (! $client) {
            return false;
        }

        if (data_get($subscription->metadata, 'assigned_by_super_admin')) {
            return false;
        }

        if ($subscription->cancel_at_period_end) {
            // They have already told us they are leaving. Asking them to renew
            // is arguing with a decision they made.
            return false;
        }

        return ! $this->gateways->billsRecurringItself($client);
    }

    /** @return int how many channels actually went out */
    private function notify(Subscription $subscription, int $days): int
    {
        $client = $subscription->client;
        $sent   = 0;

        if ($this->enabled('email')) {
            $sent += $this->send($subscription, $days, 'email', $this->emailFor($client), function () use ($subscription, $days, $client) {
                Mail::to($this->emailFor($client))->send(new RenewalDueMail($subscription, $days));
            });
        }

        if ($this->enabled('whatsapp')) {
            $sent += $this->send($subscription, $days, 'whatsapp', $this->phoneFor($client), function () use ($subscription, $days, $client) {
                $this->sendWhatsApp($client, $subscription, $days);
            });
        }

        return $sent;
    }

    /**
     * Claim the slot, then send.
     *
     * The insert is the lock. A unique index on (subscription, period, offset,
     * channel) means a second attempt fails at the database rather than
     * duplicating a message, and doing it BEFORE the send means an exception
     * mid-flight loses one notice instead of retrying forever.
     */
    private function send(Subscription $subscription, int $days, string $channel, ?string $destination, callable $deliver): int
    {
        if (! $destination) {
            return 0;
        }

        try {
            $id = DB::table('renewal_notices')->insertGetId([
                'client_id'       => $subscription->client_id,
                'subscription_id' => $subscription->id,
                'period_end'      => $subscription->current_period_end,
                'days_before'     => $days,
                'channel'         => $channel,
                'destination'     => mb_substr($destination, 0, 190),
                'delivered'       => false,
                'sent_at'         => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Already claimed — this notice has gone out. Not an error.
            return 0;
        }

        try {
            $deliver();

            DB::table('renewal_notices')->where('id', $id)->update(['delivered' => true, 'updated_at' => now()]);

            return 1;
        } catch (\Throwable $e) {
            DB::table('renewal_notices')->where('id', $id)
                ->update(['error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);

            Log::warning('billing.renewal_notice_failed', [
                'subscription' => $subscription->id,
                'channel'      => $channel,
                'days'         => $days,
                'error'        => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * WhatsApp, as an approved template.
     *
     * A renewal notice is always outside Meta's 24-hour service window — the
     * customer has not messaged us — so a plain text send would be rejected.
     * Only a template Meta has approved can open a conversation, which is why
     * this is configuration rather than a string in the code.
     */
    private function sendWhatsApp(Client $client, Subscription $subscription, int $days): void
    {
        $template = (string) config('billing.renewals.whatsapp.template');

        if ($template === '') {
            throw new \RuntimeException(
                'No approved WhatsApp template configured for renewal notices — '
                . 'set BILLING_RENEWAL_TEMPLATE. Email was still sent.'
            );
        }

        $connection = $this->whatsAppConnection($client);

        if (! $connection) {
            throw new \RuntimeException('This workspace has no connected WhatsApp number.');
        }

        app(\Msd\MetaChannels\Services\GraphClient::class)->sendTemplate(
            $connection->external_id,
            $this->phoneFor($client),
            $template,
            (string) config('billing.renewals.whatsapp.language', 'en'),
            [[
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $client->name],
                    ['type' => 'text', 'text' => (string) ($subscription->plan?->name ?? 'your plan')],
                    ['type' => 'text', 'text' => (string) $days],
                ],
            ]],
        );
    }

    private function whatsAppConnection(Client $client)
    {
        // `enabled()` rather than a boolean column — channel_connections tracks
        // a status, and the scope is what the rest of the package filters on.
        return \Msd\MetaChannels\Models\ChannelConnection::query()
            ->whereIn('project_id', \App\Models\Project::where('client_id', $client->id)->pluck('id'))
            ->where('provider', 'whatsapp')
            ->enabled()
            ->first();
    }

    private function emailFor(Client $client): ?string
    {
        return $client->billing_email ?: $client->users()->first()?->email;
    }

    /**
     * Where to send a WhatsApp notice.
     *
     * The workspace's own billing phone, which they set — NOT the number their
     * WhatsApp channel receives on. That number is the business's inbound line;
     * sending a renewal notice to it would message the customer's own support
     * desk rather than the person who pays the bill.
     */
    private function phoneFor(Client $client): ?string
    {
        $phone = (string) data_get($client->json_data, 'billing_phone', '');

        return $phone !== '' ? $phone : null;
    }

    private function enabled(string $channel): bool
    {
        return (bool) config("billing.renewals.{$channel}.enabled", true);
    }

    /** @return array<int, int> descending, so the earliest notice is sent first */
    private function offsets(): array
    {
        $days = (array) config('billing.renewals.days_before', [7, 3, 1]);

        $days = array_values(array_unique(array_filter(
            array_map('intval', $days),
            fn (int $d) => $d > 0,
        )));

        rsort($days);

        return $days ?: [7, 3, 1];
    }
}
