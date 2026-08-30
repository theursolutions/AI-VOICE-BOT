<?php

namespace Msd\MailChannel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Msd\MailChannel\Models\EmailAccount;
use Msd\MailChannel\Services\ImapClient;

/**
 * One mailbox, one sweep: fetch whatever's new since the last poll and hand
 * each message off to its own ProcessInboundEmail job. Kept as a separate
 * job (rather than processing inline) so a slow AI reply or a flaky SMTP
 * server on ONE message can retry independently, without re-reading the
 * whole mailbox.
 */
class PollInboundEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(private readonly EmailAccount $account) {}

    public function handle(): void
    {
        try {
            $messages = ImapClient::forAccount($this->account)
                ->fetchNew((int) config('mail_channel.max_fetch_per_poll', 25));

            foreach ($messages as $message) {
                ProcessInboundEmail::dispatch($message);
            }

            $this->account->forceFill([
                'last_polled_at' => now(),
                'last_error'     => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('mail-channel: inbound poll failed', [
                'account' => $this->account->id,
                'error'   => $e->getMessage(),
            ]);

            // Never let one broken mailbox interrupt the sweep across every
            // other account — the failure is recorded for the Channels page
            // to surface, not rethrown.
            $this->account->forceFill([
                'last_polled_at' => now(),
                'last_error'     => $e->getMessage(),
            ])->save();
        }
    }
}
