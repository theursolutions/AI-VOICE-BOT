<?php

namespace Msd\MailChannel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Msd\MailChannel\Contracts\HandlesInboundEmail;
use Msd\MailChannel\Models\EmailAccount;
use Msd\MailChannel\Services\SmtpMailer;
use Msd\MailChannel\Support\InboundEmail;
use Msd\MailChannel\Support\OutboundEmail;

/**
 * One inbound email → the host's CRM brain → (optionally) a sent reply.
 *
 * Mirrors Msd\MetaChannels\Jobs\ProcessInboundMessage: the package never
 * imports the CRM brain, it only calls the bound HandlesInboundEmail
 * contract and sends back whatever text comes out.
 */
class ProcessInboundEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly InboundEmail $message) {}

    public function handle(HandlesInboundEmail $handler): void
    {
        $reply = $handler->handle($this->message);

        if ($reply === null || trim($reply) === '') {
            return;
        }

        $account = EmailAccount::find($this->message->accountId);
        if (!$account || !$account->isEnabled()) {
            Log::warning('mail-channel: reply skipped, account missing or disabled', [
                'account' => $this->message->accountId,
            ]);
            return;
        }

        $references = $this->message->references;
        if ($this->message->messageId && !in_array($this->message->messageId, $references, true)) {
            $references[] = $this->message->messageId;
        }

        SmtpMailer::forAccount($account)->send(new OutboundEmail(
            to: [$this->message->fromEmail],
            subject: $this->replySubject($this->message->subject),
            text: $reply,
            html: nl2br(e($reply)),
            inReplyTo: $this->message->messageId,
            references: $references,
        ));
    }

    private function replySubject(string $subject): string
    {
        return preg_match('/^re:/i', trim($subject)) ? $subject : "Re: {$subject}";
    }
}
