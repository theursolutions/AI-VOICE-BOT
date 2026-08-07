<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
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
    public function __invoke(Request $request, EmailOtpService $otp): RedirectResponse|View
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        $otp->ensure($request->user());

        return view('auth.verify-email');
    }
}
