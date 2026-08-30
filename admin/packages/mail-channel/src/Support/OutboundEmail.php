<?php

namespace Msd\MailChannel\Support;

/**
 * What SmtpMailer::send() needs to build and dispatch one message.
 */
class OutboundEmail
{
    /**
     * @param array<int, string> $to
     * @param array<int, string> $cc
     * @param array<int, string> $bcc
     * @param array<int, array{filename: string, mime: string, path?: string, bytes?: string}> $attachments
     * @param array<int, string> $references
     */
    public function __construct(
        public readonly array $to,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly string $subject = '',
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        public readonly array $attachments = [],
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
    ) {}
}
