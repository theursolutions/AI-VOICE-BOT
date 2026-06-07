<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public Invitation $invitation;
    public Client     $client;
    public User       $inviter;
    public ?string    $recipientName;

    public function __construct(Invitation $invitation, Client $client, User $inviter, ?string $recipientName = null)
    {
        $this->invitation    = $invitation;
        $this->client        = $client;
        $this->inviter       = $inviter;
        $this->recipientName = $recipientName;
    }

    public function build(): self
    {
        $acceptUrl = route('invitations.accept.show', ['token' => $this->invitation->token]);

        return $this
            ->subject("You've been invited to join {$this->client->name}")
            ->markdown('emails.invitations.invite', [
                'invitation'    => $this->invitation,
                'client'        => $this->client,
                'inviter'       => $this->inviter,
                'recipientName' => $this->recipientName,
                'acceptUrl'     => $acceptUrl,
            ]);
    }
}
