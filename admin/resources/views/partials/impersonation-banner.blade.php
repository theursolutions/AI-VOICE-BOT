@if (session()->has('impersonator_id'))
    @php
        $imp = \App\Models\User::find(session('impersonator_id'));
        $cur = auth()->user();
    @endphp
    <div style="position:fixed; top:0; left:0; right:0; z-index:9999;
                background: linear-gradient(90deg, #ff5e87, #ff8c00);
                color:#fff; font-family: 'Inter', sans-serif; font-size: 13px;
                padding: 8px 18px; display: flex; align-items: center; gap: 12px;
                box-shadow: 0 4px 14px rgba(0,0,0,.4);">
        <span style="font-weight: 800; letter-spacing: .04em;">IMPERSONATING</span>
        <span>You are viewing as <b>{{ $cur?->name }}</b> ({{ $cur?->email }})
            @if ($imp) — operator: <b>{{ $imp->email }}</b> @endif
        </span>
        <form method="POST" action="{{ route('ops.impersonate.exit') }}" style="margin-left:auto;">
            @csrf
            <button type="submit"
                    style="background:#fff; color:#b91c1c; border:none;
                           padding:5px 12px; border-radius:6px; font-weight:700;
                           font-size:12px; font-family: inherit; cursor:pointer;">
                Exit impersonation
            </button>
        </form>
    </div>
    <style>body { padding-top: 38px !important; }</style>
@endif
