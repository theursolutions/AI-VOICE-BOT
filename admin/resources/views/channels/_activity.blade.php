{{-- ── Onboarding activity ────────────────────────────────────────────
     A summary card that opens one modal. Previously every attempt was
     printed inline with its own modal, which pushed the channels themselves
     off the screen once a few connections had been tried — and the logs are
     something you consult when something breaks, not something you read on
     every visit.

     Inside the modal: tabs per status, and one accordion per attempt using
     native <details>, so every entry is collapsed by default with no JS
     needed to keep it that way.
--}}
@php
    $retryKey = [
        'facebook_page' => 'facebook',
        'instagram'     => 'instagram',
        'whatsapp'      => 'whatsapp',
        'messenger'     => 'facebook',
    ];

    $stepLabels = [
        'redirect_to_facebook'     => 'Redirected to Facebook',
        'consent'                  => 'User consent',
        'payload_stored'           => 'Saved Meta response',
        'code_exchange'            => 'Exchanged authorization code',
        'token_exchange'           => 'Token exchange',
        'long_lived_token'         => 'Upgraded to long-lived token',
        'scopes'                   => 'Permissions granted',
        'discover'                 => 'Discovered channels',
        'import'                   => 'Imported channels',
        'subscribe_page'           => 'Subscribed to Page webhooks',
        'subscribe_webhooks'       => 'Subscribed to WhatsApp webhooks',
        'qr_issued'                => 'QR code shown',
        'phone_continue'           => 'Continued on phone',
        'embedded_signup_returned' => 'Embedded Signup returned',
        'retry'                    => 'Retry started',
        'token'                    => 'Token',
        'error'                    => 'Error',
    ];

    // Group by what an operator is actually looking for.
    $tabs = [
        'failed'  => ['label' => 'Failed',      'logs' => $onboardingLogs->where('status', 'failed')->values()],
        'success' => ['label' => 'Successful',  'logs' => $onboardingLogs->where('status', 'success')->values()],
        'started' => ['label' => 'In progress', 'logs' => $onboardingLogs->where('status', 'started')->values()],
    ];

    // Open on Failed when there is something wrong, otherwise Successful.
    $activeTab = $tabs['failed']['logs']->isNotEmpty() ? 'failed' : 'success';

    $statusChip = ['success' => 'is-on', 'failed' => 'is-off', 'started' => ''];
@endphp

<div class="tva-ch-card mt-4">
    <div class="tva-ch-card__head" style="margin-bottom:0; padding-bottom:0; border-bottom:none;">
        <div style="width:36px; height:36px; border-radius:10px; background:#f1f5f9; color:#475569; display:flex; align-items:center; justify-content:center;">
            <i data-lucide="history" class="w-4 h-4"></i>
        </div>
        <div class="flex-1">
            <div class="tva-ch-card__title">Onboarding activity</div>
            <div class="text-xs text-slate-500 mt-0.5">
                @if ($onboardingLogs->isEmpty())
                    No connection attempts yet.
                @else
                    {{ $onboardingLogs->count() }} recent attempt(s) —
                    <span style="color:#15803d;">{{ $tabs['success']['logs']->count() }} successful</span>,
                    <span style="color:{{ $tabs['failed']['logs']->count() ? '#b91c1c' : '#94a3b8' }};">{{ $tabs['failed']['logs']->count() }} failed</span>,
                    {{ $tabs['started']['logs']->count() }} in progress
                @endif
            </div>
        </div>
        @if ($onboardingLogs->isNotEmpty())
            <button type="button" class="btn btn-secondary btn-sm" data-tva-modal-open="channel-logs">
                <i data-lucide="file-text" class="w-3.5 h-3.5 mr-1 inline"></i> View activity
            </button>
        @endif
    </div>
</div>

@if ($onboardingLogs->isNotEmpty())
<div id="channel-logs" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <div class="tva-modal__panel" style="max-width:720px;">
        <div class="tva-modal__head">
            <i data-lucide="history" class="w-4 h-4 mr-2 inline" style="color:#6366f1;"></i>
            Onboarding activity
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>

        {{-- Status tabs --}}
        <div style="display:flex; gap:6px; padding:14px 20px 0;">
            @foreach ($tabs as $key => $tab)
                <button type="button" class="log-tab {{ $key === $activeTab ? 'is-active' : '' }}" data-log-tab="{{ $key }}">
                    {{ $tab['label'] }}
                    <span class="log-tab__n">{{ $tab['logs']->count() }}</span>
                </button>
            @endforeach
        </div>

        {{-- Scrolls independently so the tabs and the close button stay put. --}}
        <div class="tva-modal__body" style="max-height:60vh; overflow-y:auto;">
            @foreach ($tabs as $key => $tab)
                <div class="log-panel {{ $key === $activeTab ? 'is-active' : '' }}" data-log-panel="{{ $key }}">
                    @forelse ($tab['logs'] as $log)
                        <details class="log-acc">
                            <summary class="log-acc__sum">
                                <i data-lucide="chevron-right" class="log-acc__chev w-4 h-4"></i>
                                <span class="tva-ch-chip">{{ $providers[$log->provider] ?? $log->provider }}</span>
                                <span class="tva-ch-chip {{ $statusChip[$log->status] ?? '' }}"
                                      style="{{ $log->status === 'started' ? 'background:#fef3c7;color:#92400e;' : '' }}">
                                    {{ strtoupper($log->status) }}
                                </span>
                                <span class="log-acc__title">
                                    @if ($log->status === 'success')
                                        {{ implode(', ', (array) data_get($log->result, 'channels', [])) ?: 'Imported' }}
                                    @elseif ($log->status === 'failed')
                                        {{ Str::limit($log->error, 60) }}
                                    @else
                                        Waiting…
                                    @endif
                                </span>
                                @if ($log->isRetry())<span class="tva-ch-chip">#{{ $log->attempt }}</span>@endif
                                <span class="log-acc__when">{{ $log->created_at?->diffForHumans() }}</span>
                            </summary>

                            <div class="log-acc__body">
                                <div class="flex items-center gap-2 mb-3 flex-wrap">
                                    <span class="tva-ch-chip">{{ str_replace('_', ' ', $log->method ?: 'redirect') }}</span>
                                    <span class="text-xs text-slate-400">{{ $log->created_at?->format('M j, Y H:i') }}</span>
                                </div>

                                @if ($log->guidance())
                                    <div class="mb-3 p-3 rounded" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; font-size:12.5px;">
                                        <b>What to do:</b> {{ $log->guidance() }}
                                    </div>
                                @endif

                                @if ($log->payload && $log->status === 'failed')
                                    <div class="mb-3 text-xs" style="color:#475569;">
                                        @if ($log->payload->isRetryable())
                                            Meta&rsquo;s authorisation is saved (until
                                            {{ $log->payload->expires_at?->format('M j, Y') ?? 'expiry' }}) — Retry replays
                                            our side only. Stopped at <b>{{ $log->payload->status }}</b>.
                                        @else
                                            {{ $log->payload->retryBlockedReason() }}
                                        @endif
                                    </div>
                                @endif

                                {{-- Steps --}}
                                @forelse ((array) $log->steps as $s)
                                    <div class="flex items-start gap-3 py-2" style="border-bottom:1px solid #f1f5f9;">
                                        <span style="font-size:15px; line-height:1.2; color:{{ ($s['ok'] ?? false) ? '#16a34a' : '#dc2626' }};">{{ ($s['ok'] ?? false) ? '✓' : '✗' }}</span>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium" style="color:{{ ($s['ok'] ?? false) ? '#0f172a' : '#dc2626' }};">
                                                {{ $stepLabels[$s['step'] ?? ''] ?? Str::headline((string) ($s['step'] ?? 'step')) }}
                                            </div>
                                            @if (!empty($s['detail']))
                                                <div class="text-xs text-slate-500 break-words">{{ $s['detail'] }}</div>
                                            @endif
                                        </div>
                                        <span class="text-[10px] text-slate-400 whitespace-nowrap">{{ $s['at'] ?? '' }}</span>
                                    </div>
                                @empty
                                    <div class="text-xs text-slate-400">No steps recorded.</div>
                                @endforelse

                                @if ($log->error)
                                    <div class="mt-3 p-3 rounded" style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:12.5px;">
                                        <b>Error:</b> {{ $log->error }}
                                    </div>
                                @endif

                                {{-- Actions live in the body, not the summary: a form
                                     inside <summary> would toggle the accordion on
                                     every click. --}}
                                @if ($log->status === 'failed')
                                    <div class="mt-3 flex items-center gap-2">
                                        @if ($log->canReplay())
                                            <form method="POST" action="{{ route('channels.onboarding.retry', ['client' => $client->slug, 'log' => $log->id]) }}">
                                                @csrf
                                                <input type="hidden" name="project_id" value="{{ $projectId }}">
                                                <button type="submit" class="btn btn-sm btn-primary" title="Uses the authorisation Meta already gave us — no sign-in needed">
                                                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5 mr-1 inline"></i> Retry
                                                </button>
                                            </form>
                                        @else
                                            <button type="button" onclick="openConnect('{{ $retryKey[$log->provider] ?? 'facebook' }}')"
                                                    class="btn btn-sm btn-secondary"
                                                    title="{{ $log->payload?->retryBlockedReason() ?? 'Nothing stored to replay — sign in again.' }}">
                                                <i data-lucide="external-link" class="w-3.5 h-3.5 mr-1 inline"></i> Reconnect
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </details>
                    @empty
                        <div class="text-sm text-slate-400 py-8 text-center">
                            Nothing {{ strtolower($tab['label']) }}.
                        </div>
                    @endforelse
                </div>
            @endforeach
        </div>

        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Close</button>
        </div>
    </div>
</div>
@endif
