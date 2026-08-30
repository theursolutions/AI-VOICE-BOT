<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Msd\MailChannel\Jobs\PollInboundEmail;
use Msd\MailChannel\Models\EmailAccount;

/**
 * Dispatches one IMAP sweep per connected, enabled mailbox.
 *
 * Kept as a thin dispatcher rather than doing the IMAP work inline: each
 * account becomes its own queued PollInboundEmail job, so a slow or broken
 * mailbox for one project can never delay — or, worse, crash the sweep for —
 * every other project's mailbox.
 *
 *   php artisan email:poll-inbound
 */
class EmailPollInbound extends Command
{
    protected $signature = 'email:poll-inbound';

    protected $description = 'Queue an inbound-mail check for every connected, enabled mailbox';

    public function handle(): int
    {
        $accounts = EmailAccount::enabled()->get();

        if ($accounts->isEmpty()) {
            $this->line('No enabled mailboxes — nothing to poll.');
            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            PollInboundEmail::dispatch($account);
        }

        $this->info("Queued a poll for {$accounts->count()} mailbox(es).");

        return self::SUCCESS;
    }
}
