<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Team alert for the ops inbox: a new user, client, project, website contact or payment. */
class OpsAlertMail extends Mailable
{
    use Queueable;

    /** @param array<string, string> $details label => value */
    public function __construct(
        public readonly string $title,
        public readonly ?string $body,
        public readonly array $details,
        public readonly ?string $url,
        public readonly string $when,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[' . tva_setting('content.brand_name', 'serveAI') . '] ' . $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ops.alert');
    }
}
