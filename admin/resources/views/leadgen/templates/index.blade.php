@extends('layouts.master')
@section('content')
@php
    $base = ['client' => $client->slug, 'project_id' => $project->id];
    $ch = ['email' => ['mail', 'blue', 'Email'], 'whatsapp' => ['message-circle', 'green', 'WhatsApp'],
           'messenger' => ['message-square', 'violet', 'Messenger'], 'instagram' => ['instagram', 'rose', 'Instagram']];
    $kinds = ['intro' => 'Introduction', 'follow_up' => 'Follow-up', 'meeting' => 'Meeting invite', 'inquiry' => 'Business inquiry'];
@endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Templates'])
    <div class="mt-4 flex"><a class="btn btn-primary" href="{{ route('leadgen.templates.create', $base) }}"><i data-lucide="plus"></i>New template</a></div>

    @if ($templates->isEmpty())
        <div class="tva-lg-panel tva-lg-empty">
            <span class="tva-tone tva-tone--rose mx-auto" style="width:52px;height:52px"><i data-lucide="file-text"></i></span>
            <div class="mt-3 font-semibold text-slate-600">No templates yet</div>
            <div class="mt-1">Templates give your messages a consistent structure; the AI adapts one to each business.</div>
            <a class="btn btn-sm btn-primary mt-4" href="{{ route('leadgen.templates.create', $base) }}"><i data-lucide="plus"></i>Create a template</a>
        </div>
    @else
        <div class="tva-lg-steps" style="grid-template-columns:repeat(auto-fill, minmax(300px, 1fr))">
            @foreach ($templates as $t)
                @php [$icon, $tone, $chName] = $ch[$t->channel] ?? ['file-text', 'slate', ucfirst($t->channel)]; @endphp
                <div class="tva-lg-step">
                    <div class="flex items-center gap-3">
                        <span class="tva-tone tva-tone--{{ $tone }}" style="width:40px;height:40px"><i data-lucide="{{ $icon }}"></i></span>
                        <div style="min-width:0">
                            <a class="tva-lg-step__t block truncate" href="{{ route('leadgen.templates.edit', $base + ['id' => $t->id]) }}">{{ $t->name }}</a>
                            <div class="tva-lg-sub">{{ $chName }} · {{ $kinds[$t->kind] ?? $t->kind }}</div>
                        </div>
                    </div>
                    @if ($t->subject)<div class="text-xs font-semibold text-slate-600">{{ $t->subject }}</div>@endif
                    <div class="tva-lg-step__d" style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($t->body, 150) }}</div>
                    <div class="flex flex-wrap gap-1">
                        <span class="tva-tag tva-tag--1">{{ \Illuminate\Support\Str::before(config('leadgen.languages.' . $t->language, $t->language), ' (') }}</span>
                        @if ($t->wa_template_name)<span class="tva-tag tva-tag--2">{{ $t->wa_template_name }}</span>@endif
                    </div>
                    <div class="flex gap-2 mt-1">
                        <a class="btn btn-sm btn-outline-secondary flex-1" href="{{ route('leadgen.templates.edit', $base + ['id' => $t->id]) }}"><i data-lucide="edit-3"></i>Edit</a>
                        <form method="POST" action="{{ route('leadgen.templates.destroy', $base + ['id' => $t->id]) }}" data-confirm="Delete this template?">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" title="Delete"><i data-lucide="trash-2"></i></button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
