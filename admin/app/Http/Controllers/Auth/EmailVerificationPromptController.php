<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Services\Auth\EmailOtpResendLimiter;
use App\Services\Auth\EmailOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification (OTP) prompt. A fresh code is emailed
     * only if the user has none still valid, so revisiting the page doesn't
     * spam them.
     */
    public function __invoke(Request $request, EmailOtpService $otp, EmailOtpResendLimiter $limiter): RedirectResponse|View
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            $limiter->clear($user);

            return redirect()->intended(RouteServiceProvider::HOME);
        }

        // The auto-send would otherwise be a way around the resend cooldown:
        // let the code expire, reload the page, get a new one.
        if (! $limiter->locked($user)) {
            $otp->ensure($user);
        }

        return view('auth.verify-email', ['resend' => $limiter->state($user)]);
    }
}
