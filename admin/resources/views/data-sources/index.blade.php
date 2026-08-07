@extends('layouts.master')

@section('content')
<style>
    /* Icon action buttons in the listing — text shows via native title on hover */
    .tva-act {
        display: inline-flex; align-items: center; justify-content: center;
        width: 34px; height: 34px; margin: 0 1px;
        border-radius: 8px; vertical-align: middle; cursor: pointer;
        transition: background-color .15s ease, transform .1s ease;
    }
    .tva-act:hover { background: rgba(100,116,139,.14); }
    .tva-act:active { transform: scale(.92); }
    html.dark .tva-act:hover { background: rgba(148,163,184,.18); }
</style>
<div class="content">
    <div class="intro-y flex items-center mt-8">
        <h2 class="text-lg font-medium mr-auto">
            Data Sources — {{ $client?->name }}
        </h2>
        <a href="{{ route('data-sources.create') }}"
           class="btn btn-primary shadow-md">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i> Add data source
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mt-4" role="alert">
            {{ session('success') }}
        </div>
    @endif

    <div class="intro-y box mt-5">
        <div class="p-5 border-b border-slate-200/60 dark:border-darkmode-400">
            <h3 class="font-medium text-base">All data sources</h3>
            <p class="text-xs text-slate-500 mt-1">
                Sources across {{ $projects->count() }} project(s) in this workspace.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-report">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Project</th>
                        <th>Status</th>
                        <th class="text-center">Customers</th>
                        <th>Last synced</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($sources as $source)
                    @php
                        $projectName = optional($projects->firstWhere('id', $source->project_id))->name ?? '—';
                        $typeBadge = [
                            'website'   => 'bg-primary/20 text-primary',
                            'document'  => 'bg-success/20 text-success',
                            'database'  => 'bg-warning/20 text-warning',
                            'crm_oauth' => 'bg-info/20 text-info',
                            'agent'     => 'bg-pending/20 text-pending',
                        ][$source->type] ?? 'bg-slate-200 text-slate-700';

                        $statusClass = [
                            'active'   => 'text-success',
                            'pending'  => 'text-warning',
                            'failed'   => 'text-danger',
                            'expired'  => 'text-slate-500',
                            'disabled' => 'text-slate-400',
                        ][$source->status] ?? 'text-slate-500';
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('data-sources.show', ['id' => $source->id]) }}"
                               class="font-medium whitespace-nowrap">
                                {{ $source->name }}
                            </a>
                        </td>
                        <td>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $typeBadge }}">
                                {{ $source->type }}
                            </span>
                        </td>
                        <td>{{ $projectName }}</td>
                        <td class="{{ $statusClass }} font-medium">{{ ucfirst($source->status) }}</td>
                        <td class="text-center">
                            @if ($source->customer_visible)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-success/20 text-success">
                                    <i data-lucide="users" class="w-3 h-3 mr-1"></i> Allowed
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-slate-200 text-slate-600 dark:bg-darkmode-400 dark:text-slate-300">
                                    <i data-lucide="lock" class="w-3 h-3 mr-1"></i> Internal
                                </span>
                            @endif
                        </td>
                        <td>
                            {{ $source->last_synced_at
                                ? \Illuminate\Support\Carbon::createFromTimestamp($source->last_synced_at)->diffForHumans()
                                : '—' }}
                        </td>
                        <td class="text-center whitespace-nowrap">
                            <a href="{{ route('data-sources.show', ['id' => $source->id]) }}"
                               class="tva-act text-primary" title="View" aria-label="View">
                                <i data-lucide="eye" class="w-5 h-5"></i>
                            </a>
                            @if ($source->status !== 'disabled')
                                <form method="POST" class="inline"
                                      action="{{ route('data-sources.visibility', ['id' => $source->id]) }}">
                                    @csrf
                                    <input type="hidden" name="customer_visible" value="{{ $source->customer_visible ? 0 : 1 }}">
                                    @php $tip = $source->customer_visible ? 'Hide from customers' : 'Allow customers'; @endphp
                                    <button type="submit"
                                            class="tva-act {{ $source->customer_visible ? 'text-slate-500' : 'text-success' }}"
                                            title="{{ $tip }}" aria-label="{{ $tip }}">
                                        <i data-lucide="{{ $source->customer_visible ? 'user-x' : 'user-plus' }}" class="w-5 h-5"></i>
                                    </button>
                                </form>
                                <form method="POST" class="inline"
                                      action="{{ route('data-sources.resync', ['id' => $source->id]) }}">
                                    @csrf
                                    <button type="submit" class="tva-act text-warning" title="Resync" aria-label="Resync">
                                        <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                                    </button>
                                </form>
                                <form method="POST" class="inline"
                                      action="{{ route('data-sources.destroy', ['id' => $source->id]) }}"
                                      data-confirm="Disable this data source?">
                                    @csrf
                                    <button type="submit" class="tva-act text-danger" title="Disable" aria-label="Disable">
                                        <i data-lucide="x-circle" class="w-5 h-5"></i>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-slate-500 py-6">
                            No data sources yet. Click “Add data source” to get started.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    // Render the lucide action icons (defensive — master layout usually does this).
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        try { window.lucide.createIcons(); } catch (e) {}
    }
</script>
@endsection
