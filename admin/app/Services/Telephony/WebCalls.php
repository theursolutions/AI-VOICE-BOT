<?php

namespace App\Services\Telephony;

use App\Models\Project;
use App\Models\WebCallSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Website "Call us" buttons: a visitor talks to the project's AI agent
 * straight from the browser (WebRTC → our Asterisk → the voice engine).
 *
 * Every click gets a one-call SIP account ("w…", context web-guest: it can
 * reach the AI and nothing else), valid for a few minutes and deleted once
 * the call ends. Calls are limited per visitor IP — on serveai.com.pk: one
 * minute a call, three calls a day, four hours apart.
 *
 * Settings live on the project: json_data.webcall.
 */
class WebCalls
{
    public const DEFAULTS = [
        'enabled'        => false,
        'allowed_origins' => [],   // empty = any site may embed the button
        'max_seconds'    => 60,
        'calls_per_day'  => 3,
        'gap_hours'      => 4,
        'button_label'   => 'Talk to our AI agent',
    ];

    /** An unused account is deleted after this long. */
    public const ACCOUNT_TTL_MINUTES = 5;

    /** Clicks an IP may make in an hour, used or not (keeps account churn sane). */
    public const ISSUES_PER_HOUR = 10;

    public function __construct(private SipAccounts $accounts, private SipServer $server) {}

    public function settings(Project $project): array
    {
        $saved = (array) data_get($project->json_data, 'webcall', []);

        return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    public function save(Project $project, array $settings): void
    {
        $json = (array) $project->json_data;
        $json['webcall'] = array_merge($this->settings($project), array_intersect_key($settings, self::DEFAULTS));
        $project->json_data = $json;
        $project->save();
    }

    public function originAllowed(Project $project, ?string $origin): bool
    {
        $allowed = $this->settings($project)['allowed_origins'];
        if ($allowed === []) {
            return true;
        }
        $host = strtolower((string) parse_url((string) $origin, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        foreach ($allowed as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why this IP may not call now, or null. Returns
     * ['reason' => …, 'message' => …, 'retry_after' => seconds].
     */
    public function refusal(Project $project, string $ip, ?CarbonInterface $now = null): ?array
    {
        $now ??= now();
        $s = $this->settings($project);

        $calls = WebCallSession::where('project_id', $project->id)
            ->where('ip', $ip)
            ->where('counts_toward_limit', true)
            ->whereNotNull('started_at')
            ->where('started_at', '>=', $now->copy()->subDay())
            ->orderBy('started_at')
            ->pluck('started_at');

        if ($calls->count() >= (int) $s['calls_per_day']) {
            $retry = (int) max(60, $calls->first()->copy()->addDay()->diffInSeconds($now, true));

            return ['reason' => 'daily_limit', 'retry_after' => $retry,
                    'message' => "You've used today's {$s['calls_per_day']} calls. Please try again " . $this->humanWait($retry) . '.'];
        }

        $last = $calls->last();
        $gap  = (int) round((float) $s['gap_hours'] * 3600);
        if ($last && $gap > 0 && $last->copy()->addSeconds($gap)->greaterThan($now)) {
            $retry = (int) max(60, $last->copy()->addSeconds($gap)->diffInSeconds($now, true));

            return ['reason' => 'too_soon', 'retry_after' => $retry,
                    'message' => 'You can make your next call ' . $this->humanWait($retry) . '.'];
        }

        $recent = WebCallSession::where('project_id', $project->id)
            ->where('ip', $ip)
            ->where('counts_toward_limit', true)
            ->where('created_at', '>=', $now->copy()->subHour())
            ->count();
        if ($recent >= self::ISSUES_PER_HOUR) {
            return ['reason' => 'too_many_attempts', 'retry_after' => 3600,
                    'message' => 'Too many attempts. Please try again in an hour.'];
        }

        return null;
    }

    /**
     * A one-call account for this visitor, live on Asterisk. Returns what
     * the browser needs to place the call.
     */
    public function issue(Project $project, string $ip, ?string $origin, ?string $page, ?string $language = null): array
    {
        $username = WebCallSession::PREFIX . Str::lower(Str::random(15));
        $username = preg_replace('/[^a-z0-9]/', 'x', $username);
        $password = SipAccounts::password();

        $this->accounts->put($username, $password, 'guest', SipAccounts::CONTEXT_WEB, 'Website <web>');

        WebCallSession::create([
            'project_id'   => $project->id,
            'client_id'    => $project->client_id,
            'ip'           => $ip,
            'origin'       => $origin ? Str::limit($origin, 250, '') : null,
            'page_url'     => $page ? Str::limit($page, 495, '') : null,
            'language'     => in_array($language, ['en', 'ur'], true) ? $language : null,
            'sip_username' => $username,
            'secret'       => $password,
            'status'       => WebCallSession::ISSUED,
            'expires_at'   => now()->addMinutes(self::ACCOUNT_TTL_MINUTES),
        ]);

        $s = $this->settings($project);

        return [
            'ws_url'      => $this->server->wsUrl(),
            'domain'      => $this->server->domain(),
            'ice_servers' => $this->server->iceServers(),
            'username'    => $username,
            'password'    => $password,
            // Any number: the account, not the number, decides who answers.
            // (Two digits or more — the dialplan's _X. needs them.)
            'dial'        => '100',
            'max_seconds' => (int) $s['max_seconds'],
        ];
    }

    /**
     * The call reached the AI (voice engine → asterisk/call-start): count
     * it against the IP, and hand back its limit. Null if the account is
     * unknown, used or expired.
     */
    public function start(string $username): ?WebCallSession
    {
        $web = WebCallSession::where('sip_username', $username)->first();
        if (! $web || $web->status !== WebCallSession::ISSUED || $web->expires_at->isPast()) {
            return null;
        }
        $web->status     = WebCallSession::STARTED;
        $web->started_at = now();
        $web->save();

        return $web;
    }

    /**
     * Give a visitor IP a fresh allowance: its calls so far stop counting
     * toward the limits (they stay in the stats). $projectIds null = every
     * project. Returns how many calls were released.
     */
    public function resetLimit(string $ip, ?array $projectIds = null): int
    {
        return WebCallSession::where('ip', trim($ip))
            ->where('counts_toward_limit', true)
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->update(['counts_toward_limit' => false]);
    }

    /** Remove accounts that were never used, or whose call is long over. */
    public function prune(): int
    {
        $stale = WebCallSession::whereIn('status', [WebCallSession::ISSUED, WebCallSession::STARTED])
            ->where(function ($q) {
                $q->where(fn ($q) => $q->where('status', WebCallSession::ISSUED)->where('expires_at', '<', now()))
                  ->orWhere(fn ($q) => $q->where('status', WebCallSession::STARTED)->where('started_at', '<', now()->subMinutes(30)));
            })
            ->get();

        foreach ($stale as $web) {
            try {
                $this->accounts->delete($web->sip_username);
            } catch (\Throwable $e) {
                // The voice engine deletes it after the call anyway; try next run.
                continue;
            }
            $web->status = $web->status === WebCallSession::ISSUED ? WebCallSession::EXPIRED : WebCallSession::ENDED;
            $web->ended_at ??= now();
            $web->save();
        }

        return $stale->count();
    }

    private function humanWait(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        if ($minutes < 60) {
            return 'in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }
        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        return 'in ' . $hours . ' hour' . ($hours === 1 ? '' : 's') . ($mins ? " {$mins} min" : '');
    }
}
