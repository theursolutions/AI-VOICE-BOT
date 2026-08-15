@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">📞</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Website Contacts</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                People who reached out from the marketing site (“Call me now” / contact requests).
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success mb-4" style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:10px 14px;border-radius:8px;">
            {{ session('status') }}
        </div>
    @endif

    {{-- Status pills --}}
    <div class="flex flex-wrap gap-3 mb-4">
        @php
            $pills = [
                ''          => ['All', $counts['total'], '#334155'],
                'new'       => ['New', $counts['new'], '#2563eb'],
                'contacted' => ['Contacted', $counts['contacted'], '#92400e'],
                'closed'    => ['Closed', $counts['closed'], '#15803d'],
            ];
        @endphp
        @foreach ($pills as $key => [$label, $n, $color])
            <a href="{{ route('ops.contacts.index', array_filter(['status' => $key, 'source' => $source, 'q' => $search])) }}"
               style="display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;text-decoration:none;
                      border:1px solid {{ $status === $key ? $color : '#e2e8f0' }};
                      background:{{ $status === $key ? $color : '#fff' }};color:{{ $status === $key ? '#fff' : '#475569' }};font-size:13px;font-weight:600;">
                {{ $label }}
                <span style="font-weight:700;background:{{ $status === $key ? 'rgba(255,255,255,.25)' : '#f1f5f9' }};padding:1px 8px;border-radius:999px;font-size:11px;">{{ number_format($n) }}</span>
            </a>
        @endforeach
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="Search phone, name, email, message…"
                   style="min-width:260px;">
            @if (count($sources) > 1)
                <select name="source" onchange="this.form.submit()"
                        style="font-size:13px;padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;color:#334155;">
                    <option value="">All sources</option>
                    @foreach ($sources as $s)
                        <option value="{{ $s }}" @selected($source === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="source" value="{{ $source }}">
            @endif
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            @if ($search !== '' || $source !== '')
                <a href="{{ route('ops.contacts.index', array_filter(['status' => $status])) }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($contacts->total()) }} contact(s)</div>
            @include('partials.table-export', ['table' => '#tva-t-ops-contacts', 'filename' => 'ops-contacts', 'paginator' => $contacts ?? null])
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table" id="tva-t-ops-contacts">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Contact</th>
                        <th>Message</th>
                        <th>Source</th>
                        <th>Referrer</th>
                        <th>IP</th>
                        <th>Status</th>
                        <th style="text-align:right;" data-export-skip>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($contacts as $c)
                    @php
                        $statusColor = ['new' => '#2563eb', 'contacted' => '#92400e', 'closed' => '#15803d'][$c->status] ?? '#64748b';
                        $when = $c->created_at ? $c->created_at->format('M j, Y · H:i') : '—';
                    @endphp
                    <tr>
                        <td data-label="When" style="font-family:ui-monospace,monospace; color:#475569; white-space:nowrap;">{{ $when }}</td>
                        <td data-label="Contact">
                            @if ($c->phone)
                                <div style="font-weight:600;">
                                    <a href="tel:{{ $c->phone }}" style="color:var(--tva-accent);text-decoration:none;">{{ $c->phone }}</a>
                                </div>
                            @endif
                            @if ($c->name)<div style="font-size:12px;color:#475569;">{{ $c->name }}</div>@endif
                            @if ($c->email)<div style="font-size:11px;color:#94a3b8;">{{ $c->email }}</div>@endif
                            @if (!$c->phone && !$c->name && !$c->email)<span style="color:#94a3b8;">—</span>@endif
                        </td>
                        <td data-label="Message" style="max-width:280px;color:#475569;font-size:12.5px;">
                            {{ $c->subject ? $c->subject.' — ' : '' }}{{ $c->message ?: '—' }}
                        </td>
                        <td data-label="Source"><span style="font-family:ui-monospace,monospace;font-size:11px;color:#64748b;">{{ $c->source }}</span></td>
                        <td data-label="Referrer" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#94a3b8;"
                            title="{{ $c->referrer }}">{{ $c->referrer ?: '—' }}</td>
                        <td data-label="IP" style="font-family:ui-monospace,monospace;color:#94a3b8;font-size:11px;">{{ $c->ip ?: '—' }}</td>
                        <td data-label="Status">
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:capitalize;background:{{ $statusColor }}1a;color:{{ $statusColor }};">
                                {{ $c->status }}
                            </span>
                        </td>
                        <td data-label="Action" style="text-align:right;white-space:nowrap;">
                            <form method="POST" action="{{ route('ops.contacts.status', $c->id) }}" style="display:inline-flex;gap:6px;align-items:center;">
                                @csrf
                                <select name="status" onchange="this.form.submit()"
                                        style="font-size:12px;padding:5px 8px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;color:#334155;">
                                    <option value="new" @selected($c->status==='new')>New</option>
                                    <option value="contacted" @selected($c->status==='contacted')>Contacted</option>
                                    <option value="closed" @selected($c->status==='closed')>Closed</option>
                                </select>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center;color:#94a3b8;padding:40px 0;">
                            No contact requests yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($contacts->hasPages())
            <div style="padding:14px 16px;">{{ $contacts->links() }}</div>
        @endif
    </div>
</div>
@endsection
