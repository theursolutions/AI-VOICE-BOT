<?php

namespace Msd\MailChannel\Services;

use Illuminate\Support\Facades\Log;
use Msd\MailChannel\Models\EmailAccount;
use Msd\MailChannel\Support\InboundEmail;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * Thin wrapper around webklex/php-imap for one EmailAccount.
 *
 * Cursor is the IMAP \Unseen flag, not a UID watermark: messages are fetched
 * with leaveUnread() (so a fetch alone never marks them read) and only
 * flagged \Seen once they've been turned into an InboundEmail DTO. A message
 * that fails to parse simply stays unseen and is picked up again on the next
 * sweep — no separate retry bookkeeping needed.
 */
class ImapClient
{
    public static function forAccount(EmailAccount $account): self
    {
        return new self($account);
    }

    public function __construct(private readonly EmailAccount $account) {}

    /** @return InboundEmail[] */
    public function fetchNew(int $limit): array
    {
        $client = $this->connect();
        $out = [];

        try {
            $folder = $client->getFolder('INBOX');
            $messages = $folder->messages()
                ->unseen()
                ->leaveUnread()
                ->setFetchOrder('asc')
                ->limit($limit)
                ->get();

            foreach ($messages as $message) {
                try {
                    $out[] = $this->toInboundEmail($message);
                    $message->setFlag('Seen');
                } catch (\Throwable $e) {
                    Log::warning('mail-channel: failed to parse an inbound message, will retry next sweep', [
                        'account' => $this->account->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $client->disconnect();
        }

        return $out;
    }

    /** Opens both the connection and the mailbox — used by the "Test" button before saving credentials. */
    public function testConnection(): void
    {
        $client = $this->connect();
        $client->getFolder('INBOX')->messages()->limit(1)->get();
        $client->disconnect();
    }

    private function connect(): Client
    {
        $auth = $this->account->oauth_provider
            // The stored password field stays empty for an OAuth account —
            // webklex sends this as a bearer token via AUTHENTICATE XOAUTH2
            // instead of a plaintext LOGIN when 'authentication' is 'oauth'.
            ? ['password' => app(OAuthTokenBroker::class)->freshAccessToken($this->account), 'authentication' => 'oauth']
            : ['password' => $this->account->imap_password, 'authentication' => null];

        $client = (new ClientManager())->make([
            'host'           => $this->account->imap_host,
            'port'           => $this->account->imap_port,
            'protocol'       => 'imap',
            'encryption'     => $this->mapEncryption($this->account->imap_encryption),
            'validate_cert'  => true,
            'username'       => $this->account->imap_username,
            'password'       => $auth['password'],
            'authentication' => $auth['authentication'],
        ]);
        $client->connect();

        return $client;
    }

    /**
     * The account form speaks the same vocabulary as the SMTP side (ssl =
     * implicit TLS, tls = STARTTLS, none = plaintext), but webklex's IMAP
     * layer spells explicit-upgrade 'starttls', not 'tls' — 'tls' there means
     * implicit, same as 'ssl'. Translate rather than repeat webklex's naming
     * on the form and confuse the person filling it in.
     */
    private function mapEncryption(string $value): string|false
    {
        return match ($value) {
            'ssl'  => 'ssl',
            'tls'  => 'starttls',
            default => false,
        };
    }

    private function toInboundEmail(Message $message): InboundEmail
    {
        $from = $message->getFrom()->first();

        $attachments = [];
        foreach ($message->getAttachments() as $att) {
            $attachments[] = [
                'filename' => (string) ($att->name ?: $att->filename ?: 'attachment'),
                'mime'     => (string) ($att->getMimeType() ?: 'application/octet-stream'),
                'bytes'    => (string) $att->getContent(),
            ];
        }

        $references = [];
        $refValue = $message->getReferences()?->first();
        if ($refValue) {
            $references = array_values(array_filter(preg_split('/\s+/', (string) $refValue)));
        }

        return new InboundEmail(
            accountId:  $this->account->id,
            projectId:  (int) $this->account->project_id,
            mailbox:    $this->account->from_email,
            fromEmail:  $from instanceof Address ? $from->mail : '',
            fromName:   $from instanceof Address ? ($from->personal ?: null) : null,
            to:         $this->addressList($message->getTo()?->all() ?? []),
            cc:         $this->addressList($message->getCc()?->all() ?? []),
            subject:    (string) ($message->getSubject()?->first() ?? '(no subject)'),
            textBody:   trim($message->getTextBody()),
            htmlBody:   $message->getHTMLBody() ?: null,
            messageId:  $this->trimOrNull($message->getMessageId()?->first()),
            inReplyTo:  $this->trimOrNull($message->getInReplyTo()?->first()),
            references: $references,
            attachments: $attachments,
            receivedAt: time(),
        );
    }

    /** @return array<int, array{name: ?string, email: string}> */
    private function addressList(array $addresses): array
    {
        return array_values(array_filter(array_map(
            fn ($a) => $a instanceof Address ? ['name' => $a->personal ?: null, 'email' => $a->mail] : null,
            $addresses,
        )));
    }

    private function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
