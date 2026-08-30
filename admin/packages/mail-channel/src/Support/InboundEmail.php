<?php

namespace Msd\MailChannel\Support;

/**
 * A normalized inbound message, handed from ImapClient/ProcessInboundEmail to
 * the host's HandlesInboundEmail implementation.
 */
class InboundEmail
{
    /**
     * @param array<int, array{name?: ?string, email: string}> $to
     * @param array<int, array{name?: ?string, email: string}> $cc
     * @param array<int, string> $references
     * @param array<int, array{filename: string, mime: string, bytes: string}> $attachments
     */
    public function __construct(
        public readonly int $accountId,
        public readonly int $projectId,
        public readonly string $mailbox,       // the connected address (email_accounts.from_email)
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly string $textBody,
        public readonly ?string $htmlBody,
        public readonly ?string $messageId,     // RFC822 Message-ID
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $attachments,
        public readonly int $receivedAt,
    ) {}
}
