<?php

namespace Msd\MailChannel\Services;

use Msd\MailChannel\Models\EmailAccount;
use Msd\MailChannel\Support\OutboundEmail;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends through one EmailAccount's own SMTP credentials — deliberately NOT
 * Laravel's `Mail` facade, which is wired to the app's single transactional
 * mailer (OTP/invoices/etc.) and has no notion of "which of a project's
 * several mailboxes." Built directly on Symfony Mailer instead, which
 * `illuminate/mail` already depends on, so this adds no new package.
 */
class SmtpMailer
{
    public static function forAccount(EmailAccount $account): self
    {
        return new self($account);
    }

    public function __construct(private readonly EmailAccount $account) {}

    public function send(OutboundEmail $message): void
    {
        $email = (new Email())
            ->from(new Address($this->account->from_email, (string) ($this->account->from_name ?: '')))
            ->subject($message->subject);

        foreach ($message->to as $to) {
            $email->addTo($to);
        }
        foreach ($message->cc as $cc) {
            $email->addCc($cc);
        }
        foreach ($message->bcc as $bcc) {
            $email->addBcc($bcc);
        }

        if ($message->html !== null) {
            $email->html($message->html);
        }
        // Always set a text part too — even when HTML is present, mail
        // clients that show a "plain text preview" and spam filters that
        // score multipart/alternative without one both get a real body.
        $email->text($message->text ?? ($message->html ? strip_tags($message->html) : ''));

        foreach ($message->attachments as $attachment) {
            $bytes = $attachment['bytes'] ?? (isset($attachment['path']) ? file_get_contents($attachment['path']) : null);
            if ($bytes === null || $bytes === false) {
                continue;
            }
            $email->addPart(
                (new DataPart($bytes, $attachment['filename'], $attachment['mime']))
                    ->asInline(false)
            );
        }

        if ($message->inReplyTo) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $message->inReplyTo);
        }
        if ($message->references) {
            $email->getHeaders()->addTextHeader('References', implode(' ', $message->references));
        }

        $this->transport()->send($email);
    }

    /** Opens the SMTP connection with the stored credentials — used by the "Test" button. */
    public function testConnection(): void
    {
        // EsmtpTransport doesn't expose a bare "connect", so the connection
        // is proven by asking the server for its capabilities via a no-op
        // handshake: constructing and starting the transport is enough to
        // surface a bad host/port/credential immediately rather than only
        // on the first real send.
        $transport = $this->transport();
        if (method_exists($transport, 'start')) {
            $transport->start();
            $transport->stop();
        }
    }

    private function transport(): TransportInterface
    {
        if ($this->account->oauth_provider) {
            // Transport::fromDsn() has no XOAUTH2 support, so an OAuth
            // account builds the transport directly instead — same host/port
            // either way, just a bearer token in place of a password, sent
            // via the XOAuth2Authenticator Symfony Mailer already ships but
            // doesn't register by default.
            $token = app(OAuthTokenBroker::class)->freshAccessToken($this->account);

            $transport = new EsmtpTransport(
                $this->account->smtp_host,
                $this->account->smtp_port,
                null, // TLS auto-negotiated via STARTTLS on 587, which is what both Gmail and Outlook use
                null,
                null,
                null,
                [new XOAuth2Authenticator()],
            );
            $transport->setUsername((string) $this->account->smtp_username);
            $transport->setPassword($token);

            return $transport;
        }

        $scheme = match ($this->account->smtp_encryption) {
            'ssl'   => 'smtps',   // implicit TLS, typically port 465
            default => 'smtp',    // STARTTLS negotiated automatically when the server offers it, or plaintext
        };

        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode($this->account->smtp_username),
            rawurlencode($this->account->smtp_password ?? ''),
            $this->account->smtp_host,
            $this->account->smtp_port,
        );

        return Transport::fromDsn($dsn);
    }
}
