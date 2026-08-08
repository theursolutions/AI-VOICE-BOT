<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Services\Auth\EmailOtpResendLimiter;
use App\Services\Auth\EmailOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailOtpController extends Controller
{
    /**
     * Verify the 6-digit code the user typed on the verify-email screen.
     */
    public function verify(Request $request, EmailOtpService $otp, EmailOtpResendLimiter $limiter): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        // Tolerate spaces / dashes the user might paste between digits.
        $code = preg_replace('/\D/', '', (string) $request->input('code'));

        if ($code !== '' && $otp->verify($user, $code)) {
            $limiter->clear($user);

            return redirect()
                ->intended(RouteServiceProvider::HOME)
                ->with('status', 'email-verified');
        }

        return back()->withErrors([
            'code' => 'That code is invalid or has expired. Request a new one and try again.',
        ]);
    }
}
