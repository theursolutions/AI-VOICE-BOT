<?php

namespace App\Console\Commands;

use App\Services\Telephony\WebCalls;
use Illuminate\Console\Command;

/**
 * Deletes website "Call us" accounts from Asterisk once they can no longer
 * be used: never called within their few minutes, or the call long over.
 * The voice engine deletes an account when its call ends; this catches
 * the clicks that never became calls.
 */
class WebCallPrune extends Command
{
    protected $signature = 'webcall:prune';

    protected $description = 'Remove expired website-call SIP accounts from Asterisk';

    public function handle(WebCalls $calls): int
    {
        $n = $calls->prune();
        $this->info("Pruned {$n} website-call account(s).");

        return self::SUCCESS;
    }
}
