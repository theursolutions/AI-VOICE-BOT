@extends('layouts.master')
@section('content')
@php
    $base    = ['client' => $client->slug, 'project_id' => $project->id];
    $p       = $msg->prospect;
    $canEdit = auth()->user()->canAbility($client->id, 'leadgen', 'edit') && $msg->status === 'draft';
@endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Draft for ' . ($p?->business_name ?? 'prospect')])

    <div class="flex gap-2 mt-4 items-center flex-wrap text-xs text-slate-500">
        <span class="tva-lg-pill tva-lg-pill--off">{{ ucfirst($msg->channel) }}</span>
        <span>{{ \Illuminate\Support\Str::before(config('leadgen.languages.' . $msg->language, $msg->language ?? ''), ' (') }}</span>
        <span>· {{ $msg->ai_generated ? 'Written by AI — read it before using it' : 'From a template' }}</span>
        @if ($p)<a class="btn btn-sm btn-outline-secondary ml-auto" href="{{ route('leadgen.prospects.show', $base + ['id' => $p->id]) }}"><i data-lucide="chevron-left"></i>Back to {{ $p->business_name }}</a>@endif
    </div>

    @if ($msg->last_error)
        <div class="alert alert-warning mt-3">@foreach (explode("\n", $msg->last_error) as $w)<div>{{ $w }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('leadgen.drafts.update', $base + ['id' => $msg->id]) }}" class="tva-lg-panel">
        @csrf @method('PATCH')
        @if ($msg->channel === 'email')
            <label class="text-xs font-semibold">Subject</label>
            <input name="subject" class="form-control" value="{{ $msg->subject }}" maxlength="255" @disabled(!$canEdit)>
        @endif
        <label class="text-xs font-semibold mt-3 block">Message</label>
        <textarea name="body" rows="14" class="form-control" maxlength="5000" @disabled(!$canEdit) id="lg-draft-body">{{ $msg->body }}</textarea>
        <div class="tva-lg-hint"><span id="lg-words"></span> words. Drafts aren’t sent from here — add the prospect to a campaign to send after approval.</div>
        @if ($canEdit)
            <div class="flex gap-2 mt-3">
                <button class="btn btn-primary btn-sm"><i data-lucide="save"></i>Save draft</button>
            </div>
        @endif
    </form>
    @if ($canEdit)
    <form method="POST" action="{{ route('leadgen.drafts.destroy', $base + ['id' => $msg->id]) }}" data-confirm="Delete this draft?" class="mt-2">
        @csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm"><i data-lucide="trash-2"></i>Delete draft</button>
    </form>
    @endif
</div>
@endsection

@section('script')
<script>
(function () {
    var b = document.getElementById('lg-draft-body'), w = document.getElementById('lg-words');
    function count() { w.textContent = (b.value.trim().match(/\S+/g) || []).length; }
    b.addEventListener('input', count); count();
})();
</script>
@endsection
