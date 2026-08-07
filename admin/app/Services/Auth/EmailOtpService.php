<?php

namespace App\Services\Auth;

use App\Mail\EmailOtpMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Issues and verifies the 6-digit email-verification OTP.
 *
 *   send()   — generate a fresh code, hash + store it, email it to the user.
 *   verify() — check a submitted code; on success mark the email verified.
 *   ensure() — send only if there's no still-valid code (avoids spamming
 *              on every page view of the verify screen).
 */
class EmailOtpService
{
    public const TTL_MINUTES   = 10;
    public const MAX_ATTEMPTS  = 5;

    /** Generate a new code, replace any prior code, and email it. */
    public function send(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // One active code per user — drop the old one.
        EmailOtp::where('user_id', $user->id)->delete();
        EmailOtp::create([
            'user_id'    => $user->id,
            'code_hash'  => Hash::make($code),
            'attempts'   => 0,
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
        ]);

        try {
            Mail::to($user->email)->send(new EmailOtpMail($code, $user->name, self::TTL_MINUTES));
        } catch (\Throwable $e) {
            // Never let a mail-transport hiccup break registration/login.
            Log::warning('Email OTP send failed: ' . $e->getMessage());
        }

        // Dev convenience: surface the code in the log when there's no real
        // inbox to check (local Mailpit etc.).
        if (app()->environment('local')) {
            Log::info("[DEV] Email OTP for {$user->email}: {$code}");
        }
    }

    /** Send a code only if the user has no unexpired one outstanding. */
    public function ensure(User $user): void
    {
        $active = EmailOtp::where('user_id', $user->id)
            ->where('expires_at', '>', Carbon::now())
            ->exists();

        if (!$active) {
            $this->send($user);
        }
    }

    /**
     * Validate a submitted code. Returns true and marks the email verified
     * on success; false (consuming an attempt) otherwise.
     */
    public function verify(User $user, string $code): bool
    {
        $otp = EmailOtp::where('user_id', $user->id)->latest('id')->first();

        if (!$otp || $otp->isExpired()) {
            $otp?->delete();
            return false;
        }
        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (!Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            return false;
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new \Illuminate\Auth\Events\Verified($user));
        }
        EmailOtp::where('user_id', $user->id)->delete();

        return true;
    }
}
