<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Escalating rate limit for "Resend code" on the verify-email screen.
 *
 * The user gets a window of {@see MAX_PER_WINDOW} resends. Exhausting a
 * window starts a cooldown, and each successive window costs more:
 *
 *     3 resends -> wait 1 hour -> 3 more -> wait 24 hours -> 3 more -> ...
 *
 * State lives in the cache (file driver in this app, so it survives a
 * session change or a re-login) keyed by user id, not by IP or session --
 * clearing cookies must not hand out a fresh allowance.
 */
class EmailOtpResendLimiter
{
    /** Resends allowed before a cooldown kicks in. */
    public const MAX_PER_WINDOW = 3;

    /**
     * Cooldown after each exhausted window, in minutes. The last entry is
     * reused for every window beyond the list.
     */
    public const COOLDOWN_MINUTES = [60, 1440];

    /**
     * Consume one resend. Returns false when a cooldown is still running --
     * the caller must not send anything in that case.
     */
    public function attempt(User $user): bool
    {
        $state = $this->read($user);

        if ($state['until'] && $state['until']->isFuture()) {
            return false;
        }

        // A served cooldown opens a fresh window; the tier stays where it is
        // so the next lockout is the longer one.
        $used = $state['until'] ? 1 : $state['used'] + 1;
        $tier = $state['tier'];
        $until = null;

        if ($used >= self::MAX_PER_WINDOW) {
            $until = Carbon::now()->addMinutes($this->cooldownMinutes($tier));
            $tier++;
        }

        $this->write($user, $used, $tier, $until);

        return true;
    }

    /** Is the user currently inside a cooldown? */
    public function locked(User $user): bool
    {
        $until = $this->read($user)['until'];

        return $until !== null && $until->isFuture();
    }

    /**
     * Everything the verify-email view needs to render the resend control.
     *
     * @return array{locked: bool, availableAt: ?Carbon, remaining: int, wait: ?string, message: ?string}
     */
    public function state(User $user): array
    {
        $s = $this->read($user);
        $until = $s['until'];

        if ($until && $until->isFuture()) {
            $wait = $this->waitLabel($until);

            return [
                'locked'      => true,
                'availableAt' => $until,
                'remaining'   => 0,
                'wait'        => $wait,
                'message'     => "Too many retries. You can request a new code after {$wait}.",
            ];
        }

        // Cooldown served (or never started) -> the window is open again.
        $used = $until ? 0 : $s['used'];

        return [
            'locked'      => false,
            'availableAt' => null,
            'remaining'   => max(0, self::MAX_PER_WINDOW - $used),
            'wait'        => null,
            'message'     => null,
        ];
    }

    /** The error to show when a resend was refused. */
    public function lockMessage(User $user): string
    {
        return $this->state($user)['message']
            ?? 'Too many retries. Please try again later.';
    }

    /** Drop the record once the address is verified. */
    public function clear(User $user): void
    {
        Cache::forget($this->key($user));
    }

    /** Cooldown length for the given tier, clamped to the last entry. */
    private function cooldownMinutes(int $tier): int
    {
        $index = min($tier, count(self::COOLDOWN_MINUTES) - 1);

        return self::COOLDOWN_MINUTES[$index];
    }

    /** "45 minutes" / "1 hour" / "24 hours" -- rounded up, never "0". */
    private function waitLabel(Carbon $until): string
    {
        $minutes = (int) ceil(abs(Carbon::now()->diffInSeconds($until, false)) / 60);

        if ($minutes >= 60) {
            $hours = (int) ceil($minutes / 60);

            return $hours . ' hour' . ($hours === 1 ? '' : 's');
        }

        $minutes = max(1, $minutes);

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    /** @return array{used: int, tier: int, until: ?Carbon} */
    private function read(User $user): array
    {
        $s = Cache::get($this->key($user), []);

        return [
            'used'  => (int) ($s['used'] ?? 0),
            'tier'  => (int) ($s['tier'] ?? 0),
            'until' => !empty($s['until']) ? Carbon::parse($s['until']) : null,
        ];
    }

    private function write(User $user, int $used, int $tier, ?Carbon $until): void
    {
        // Outlive the cooldown by a day so the tier -- and therefore the
        // escalation -- isn't forgotten the moment the lock lifts.
        $ttl = ($until ? $until->copy() : Carbon::now())->addDay();

        Cache::put($this->key($user), [
            'used'  => $used,
            'tier'  => $tier,
            'until' => $until?->toIso8601String(),
        ], $ttl);
    }

    private function key(User $user): string
    {
        return 'email-otp:resend:' . $user->getKey();
    }
}
