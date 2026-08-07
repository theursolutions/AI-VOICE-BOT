@php
    // Show only when logged in, email NOT verified, and on the two pages an
    // unverified user is allowed to see (Dashboard + Ask AI). Everywhere else
    // is route-gated anyway, so this keeps the nudge where it's useful.
    $verifyShow = Auth::check()
        && !Auth::user()->hasVerifiedEmail()
        && request()->routeIs('dashboard', 'onboard', 'assistant.*');
@endphp

@if ($verifyShow)
<div style="margin:16px 0; border-radius:14px; overflow:hidden; border:1px solid #fcd34d;
            background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%); box-shadow:0 8px 24px -16px rgba(180,83,9,.5);">
    <div style="display:flex; align-items:flex-start; gap:14px; padding:16px 18px; flex-wrap:wrap;">
        <div style="width:42px; height:42px; flex-shrink:0; border-radius:11px; display:flex; align-items:center; justify-content:center;
                    background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff;">
            <i data-lucide="mail" style="width:22px;height:22px"></i>
        </div>
        <div style="flex:1 1 340px; min-width:240px;">
            <div style="font-size:15px; font-weight:700; color:#78350f;">Verify your email to unlock the full platform</div>
            <div style="font-size:13px; color:#92400e; margin-top:3px; line-height:1.5;">
                Your account is limited to the <strong>Dashboard</strong> and <strong>Ask AI</strong> until you confirm your
                email address. Verify it to enable Channels, Data Sources, Flows, Leads, Agents and every other module.
                @if (Auth::user()->email)
                    We sent a link to <strong>{{ Auth::user()->email }}</strong> — check your inbox (and spam).
                @endif
            </div>
            @if (session('status') === 'verification-link-sent')
                <div style="font-size:12.5px; color:#166534; margin-top:8px; font-weight:600;">
                    ✓ A new verification email is on its way.
                </div>
            @endif
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-shrink:0;">
            <a href="{{ route('verification.notice') }}"
               style="display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:9px; text-decoration:none;
                      font-size:13px; font-weight:600; color:#fff; background:linear-gradient(135deg,#f59e0b,#d97706); border:1px solid #d97706;">
                <i data-lucide="check-circle" style="width:15px;height:15px"></i> Verify now
            </a>
            <form method="POST" action="{{ route('verification.send') }}" style="margin:0;">
                @csrf
                <button type="submit"
                        style="display:inline-flex; align-items:center; gap:7px; padding:9px 14px; border-radius:9px; cursor:pointer;
                               font-size:13px; font-weight:600; color:#92400e; background:#fff; border:1px solid #fcd34d;">
                    <i data-lucide="send" style="width:15px;height:15px"></i> Resend email
                </button>
            </form>
        </div>
    </div>
</div>
@endif
