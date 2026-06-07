@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">📞</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">All Twilio numbers</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Every phone number assigned across every project — with routing config and owner.
            </div>
        </div>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar" id="opsTelTb">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search number, project, owner…" autocomplete="off">
            </div>
            <select name="project_id" onchange="this.form.submit()">
                <option value="0">All projects</option>
                @foreach ($projectList as $p)
                    <option value="{{ $p->id }}" @selected((int)$projectFilter === (int)$p->id)>
                        {{ $p->name }} · {{ $clients[$p->client_id]->name ?? '—' }}
                    </option>
                @endforeach
            </select>
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($paginator->total()) }} number(s)</div>
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table">
                <thead>
                    <tr>
                        <th>Phone number</th>
                        <th>Project · Owner</th>
                        <th>Routing</th>
                        <th>Welcome voice</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($paginator as $n)
                    <tr>
                        <td data-label="Number">
                            <span style="font-family: ui-monospace, monospace; font-size:14px; font-weight:700;">{{ $n['phone'] }}</span>
                        </td>
                        <td data-label="Owner">
                            <div style="font-size:12.5px; font-weight:600;">{{ $n['project_name'] }}</div>
                            <div style="font-size:11px; color:#94a3b8;">{{ $n['client_name'] ?? '—' }}</div>
                        </td>
                        <td data-label="Routing">
                            @if ($n['routing_type'] === 'skill')
                                <span class="tva-status is-qualified">Skill #{{ $n['skill_id'] ?? '—' }}</span>
                            @else
                                <span class="tva-status is-new">{{ count($n['agent_ids']) }} agent(s)</span>
                            @endif
                        </td>
                        <td data-label="Welcome" style="font-family: ui-monospace, monospace; font-size:12px; color:#475569;">
                            {{ $n['welcome_voice'] ?? '—' }}
                        </td>
                        <td data-label="Status">
                            @if ($n['enabled'])
                                <span class="tva-status is-active">Active</span>
                            @else
                                <span class="tva-status is-ended">Disabled</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center; padding:60px 20px; color:#94a3b8;">No numbers match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($paginator->total() > 0)
            <div class="tva-dt-footer">
                <div class="tva-dt-footer__info">
                    Showing <b>{{ $paginator->firstItem() }}</b>–<b>{{ $paginator->lastItem() }}</b> of <b>{{ number_format($paginator->total()) }}</b>
                </div>
                @include('partials.pagination', ['paginator' => $paginator])
            </div>
        @endif
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    (function () { var f = document.getElementById('opsTelTb'); if (!f) return;
        var input = f.querySelector('input[name="q"]'); var timer = null;
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function(){ f.submit(); }, 350); });
    })();
</script>
@endsection
