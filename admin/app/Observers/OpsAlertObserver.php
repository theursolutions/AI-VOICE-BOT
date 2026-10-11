<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\ContactLead;
use App\Models\Project;
use App\Models\User;
use App\Services\Ops\OpsAlerts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * New users, clients, projects and website contacts → the super-admin
 * notification panel and the ops inbox (App\Services\Ops\OpsAlerts).
 *
 * An observer rather than calls in each controller: users alone are created
 * by the sign-up form, Google sign-in, team invitations and admins adding a
 * member, and every one of those must be seen.
 */
class OpsAlertObserver
{
    /** Only once the sign-up's transaction has committed: no alerts for rolled-back rows. */
    public bool $afterCommit = true;

    /** Where a new user came from, by the controller that created them. */
    private const USER_SOURCES = [
        'RegisteredUserController'   => 'Sign-up form',
        'SocialAuthController'       => 'Google sign-in',
        'InvitationAcceptController' => 'Accepted a team invitation',
        'MemberWebController'        => 'Added by a workspace admin',
    ];

    public function __construct(private OpsAlerts $alerts) {}

    public function created(Model $model): void
    {
        match (true) {
            $model instanceof User        => $this->user($model),
            $model instanceof Client      => $this->client($model),
            $model instanceof Project     => $this->project($model),
            $model instanceof ContactLead => $this->contact($model),
            default                       => null,
        };
    }

    private function user(User $user): void
    {
        $this->alerts->record('user', "New user: {$user->name}", $user->email,
            route('ops.users.index', ['q' => $user->email]), [
                'Name'       => $user->name,
                'Email'      => $user->email,
                'Signed up'  => $this->userSource(),
                'Added by'   => $this->actor($user->id),
                'IP address' => $this->ip(),
            ]);
    }

    private function client(Client $client): void
    {
        $this->alerts->record('client', "New client: {$client->name}", $this->actor() ? 'Created by ' . $this->actor() : null,
            route('ops.clients.index', ['q' => $client->name]), [
                'Client'        => $client->name,
                'Slug'          => $client->slug,
                'Billing email' => $client->billing_email,
                'Country'       => $client->billing_country,
                'Created by'    => $this->actor(),
            ]);
    }

    private function project(Project $project): void
    {
        $client = Client::find($project->client_id);
        $this->alerts->record('project', "New project: {$project->name}", $client ? "In {$client->name}" : null,
            route('ops.projects.index', ['q' => $project->name]), [
                'Project'    => $project->name,
                'Client'     => $client?->name,
                'Website'    => $project->url,
                'Created by' => $this->actor(),
            ]);
    }

    private function contact(ContactLead $lead): void
    {
        $who = $lead->name ?: ($lead->phone ?: $lead->email ?: 'someone');
        $kind = match ($lead->source) {
            'contact_form' => 'Contact form',
            'reach_out'    => '"Reach out" phone bar (homepage)',
            'demo_call'    => 'Demo call request',
            default        => Str::headline((string) $lead->source),
        };

        $this->alerts->record('contact', "New website contact: {$who}",
            $lead->message ? Str::limit($lead->message, 160) : $kind,
            route('ops.contacts.index', ['q' => $lead->phone ?: ($lead->email ?: $lead->name)]), [
                'Name'    => $lead->name,
                'Phone'   => $lead->phone,
                'Email'   => $lead->email,
                'Subject' => $lead->subject,
                'Message' => $lead->message,
                'From'    => $kind,
                'Page'    => $lead->referrer,
                'IP address' => $lead->ip,
            ]);
    }

    private function userSource(): ?string
    {
        if (app()->runningInConsole()) {
            return 'Command line';
        }
        $controller = class_basename((string) request()->route()?->getControllerClass());

        return self::USER_SOURCES[$controller] ?? null;
    }

    /** The signed-in person who did it, unless it is the new user themself. */
    private function actor(?int $exceptUserId = null): ?string
    {
        $me = auth()->user();
        if (! $me || $me->id === $exceptUserId) {
            return null;
        }

        return "{$me->name} ({$me->email})";
    }

    private function ip(): ?string
    {
        return app()->runningInConsole() ? null : request()->ip();
    }
}
