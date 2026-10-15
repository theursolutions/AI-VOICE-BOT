@extends('layouts.master')
@section('content')
@php
    $u       = auth()->user();
    $canEdit = $u->canAbility($client->id, 'leadgen', 'edit');
    $canSrch = $u->canAbility($client->id, 'leadgen', 'search');
    $base    = ['client' => $client->slug, 'project_id' => $project->id];
    $meta    = (array) $p->field_meta;
    $src     = $p->source_provider === 'import' ? 'Your import' : 'Overture Maps';
    $enrich  = ['none' => 'Not checked yet', 'queued' => 'Queued', 'running' => 'Checking now…', 'done' => 'Checked',
                'failed' => 'Website didn’t respond', 'blocked' => 'Not checked (site asks bots not to, or unsafe address)'];
@endphp
<div class="content">
    @include('leadgen._header', ['title' => $p->business_name, 'subtitle' => $p->category ? str_replace('_', ' ', $p->category) : null])

    <div class="flex gap-2 mt-4 flex-wrap items-center">
        @if ($p->status === 'in_crm')
            <span class="tva-st tva-st--in_crm">In your CRM</span>
            @if ($p->lead_id && $u->canModule($client->id, 'leads'))
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('leads.show', ['client' => $client->slug, 'id' => $p->lead_id, 'project_id' => $project->id]) }}"><i data-lucide="external-link"></i>Open lead</a>
            @endif
        @elseif ($canEdit && !$p->suppressed_at)
            <form method="POST" action="{{ route('leadgen.prospects.crm', $base + ['id' => $p->id]) }}">@csrf<button class="btn btn-sm btn-primary"><i data-lucide="user-plus"></i>Add to CRM</button></form>
        @endif
        @if ($canSrch && $p->website)
            <form method="POST" action="{{ route('leadgen.prospects.enrich', $base + ['id' => $p->id]) }}">@csrf<button class="btn btn-sm btn-outline-secondary" @disabled(in_array($p->enrichment_status, ['queued', 'running'], true))><i data-lucide="globe"></i>Check their website</button></form>
        @endif
        <span class="tva-lg-sub">{{ $enrich[$p->enrichment_status] ?? $p->enrichment_status }}@if ($p->last_verified_at) · {{ \Carbon\Carbon::createFromTimestamp($p->last_verified_at)->diffForHumans() }}@endif</span>
        @if ($p->suppressed_at)<span class="tva-st tva-st--blocked">On your do-not-contact list</span>@endif
        <span class="ml-auto">@include('leadgen._chips', ['chip' => 'status', 'status' => $p->status])</span>
    </div>

    @if ($possibleOf)
        <div class="alert alert-warning mt-4">
            Possibly the same business as <a class="underline" href="{{ route('leadgen.prospects.show', $base + ['id' => $possibleOf->id]) }}">{{ $possibleOf->business_name }}</a>
            (same phone number, different name). Check before contacting both.
        </div>
    @endif

    <div class="tva-lg-grid" style="align-items:start">
        <div>
            <div class="tva-lg-panel">
                <div class="tva-lg-h">Contact details</div>
                @foreach ([
                    'website' => ['Website', $p->website],
                    'email'   => ['Email', $p->email],
                    'phone'   => ['Phone', $p->phone_e164 ?: ($meta['phone']['raw'] ?? null)],
                ] as $field => [$label, $value])
                    <div class="tva-lg-row">
                        <div style="min-width:0">
                            <div class="tva-lg-row__t">{{ $label }}</div>
                            <div class="tva-lg-row__d" style="word-break:break-all">
                                @if ($value)
                                    @if ($field === 'website')<a href="{{ $value }}" target="_blank" rel="noopener nofollow" class="text-primary">{{ $value }}</a>@else {{ $value }} @endif
                                    @if (!empty($meta[$field]['source']))<br>From: {{ $meta[$field]['source'] === 'user' ? 'your team' : $meta[$field]['source'] }}@endif
                                    @if (!empty($meta[$field]['url']))<br>Seen on: <a class="text-primary" href="{{ $meta[$field]['url'] }}" target="_blank" rel="noopener nofollow">{{ \Illuminate\Support\Str::limit($meta[$field]['url'], 60) }}</a>@endif
                                    @if ($field === 'phone' && !$p->phone_e164)<br>Couldn’t confirm the country code — not used for sending.@endif
                                @else
                                    Not known
                                @endif
                            </div>
                            @if ($field === 'email' && !empty($meta['email']['alternatives']))
                                <div class="tva-lg-row__d mt-1">Also on their site (not used automatically): {{ implode(', ', $meta['email']['alternatives']) }}</div>
                            @endif
                        </div>
                        @if ($value)<div style="margin-left:auto">@include('leadgen.prospects._verified', ['level' => $p->verificationOf($field), 'mx' => $field === 'email' ? ($meta['email']['mx'] ?? null) : null])</div>@endif
                    </div>
                @endforeach
                @if ($p->socials)
                    <div class="tva-lg-row">
                        <div><div class="tva-lg-row__t">Social</div>
                            <div class="tva-lg-row__d">@foreach ($p->socials as $s)<a class="text-primary mr-2" href="{{ $s['url'] }}" target="_blank" rel="noopener nofollow">{{ ucfirst($s['platform']) }}</a>@endforeach</div>
                        </div>
                    </div>
                @endif
                <div class="tva-lg-row">
                    <div><div class="tva-lg-row__t">Address</div>
                        <div class="tva-lg-row__d">{{ implode(', ', array_filter([$p->address['line'] ?? null, $p->city, $p->country_code])) ?: '—' }}</div></div>
                </div>
            </div>

            <div class="tva-lg-panel">
                <div class="tva-lg-h">Where this came from</div>
                <div class="tva-lg-row__d">
                    {{ $src }}@if ($p->source_license) · licence {{ $p->source_license }}@endif
                    @if ($p->discovered_at) · found {{ \Carbon\Carbon::createFromTimestamp($p->discovered_at)->toFormattedDateString() }}@endif
                    @if ($p->source_url)<br><a class="text-primary" href="{{ $p->source_url }}" target="_blank" rel="noopener nofollow">View the source listing</a>@endif
                    @if (!empty($p->source_meta['local_name']))<br>Local name: {{ $p->source_meta['local_name'] }}@endif
                    @if (!empty($p->source_meta['also_from']))<br>Also listed in {{ count($p->source_meta['also_from']) }} other record(s), merged here.@endif
                    @if ($p->source_provider !== 'import')<br><span class="tva-lg-sub">{{ \App\Services\LeadGen\Providers\OvertureProvider::ATTRIBUTION }}</span>@endif
                </div>
            </div>

            @if ($similar->isNotEmpty())
            <div class="tva-lg-panel">
                <div class="tva-lg-h">Similar names in {{ $p->city }}</div>
                <div class="tva-lg-sub mb-2">Different listings — possibly branches, possibly the same business. Not merged automatically.</div>
                @foreach ($similar as $o)
                    <div><a class="text-primary text-sm" href="{{ route('leadgen.prospects.show', $base + ['id' => $o->id]) }}">{{ $o->business_name }}</a> <span class="tva-lg-sub">{{ $o->domain_norm }}</span></div>
                @endforeach
            </div>
            @endif
        </div>

        <div>
            @if ($canEdit)
            <form method="POST" action="{{ route('leadgen.prospects.update', $base + ['id' => $p->id]) }}" class="tva-lg-panel">
                @csrf @method('PATCH')
                <div class="tva-lg-h">Details</div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="col-span-2"><label class="text-xs font-semibold">Business name</label><input name="business_name" value="{{ $p->business_name }}" class="form-control form-control-sm" required maxlength="255"></div>
                    <div><label class="text-xs font-semibold">Website</label><input name="website" value="{{ $p->website }}" class="form-control form-control-sm"></div>
                    <div><label class="text-xs font-semibold">Category</label><input name="category" value="{{ $p->category }}" class="form-control form-control-sm"></div>
                    <div><label class="text-xs font-semibold">Email</label><input name="email" type="email" value="{{ $p->email }}" class="form-control form-control-sm"></div>
                    <div><label class="text-xs font-semibold">Phone</label><input name="phone" value="{{ $p->phone_e164 }}" class="form-control form-control-sm" placeholder="+971…"></div>
                    <div><label class="text-xs font-semibold">City</label><input name="city" value="{{ $p->city }}" class="form-control form-control-sm"></div>
                    <div><label class="text-xs font-semibold">Country</label><input name="country_code" value="{{ $p->country_code }}" class="form-control form-control-sm" maxlength="2"></div>
                    <div>
                        <label class="text-xs font-semibold">Status</label>
                        @if ($p->status === 'in_crm')
                            <input type="hidden" name="status" value="approved"><div class="text-sm pt-1">In CRM</div>
                        @else
                            <select name="status" class="form-select form-select-sm">
                                @foreach (['new', 'reviewing', 'approved', 'rejected'] as $s)<option value="{{ $s }}" @selected($p->status === $s)>{{ ucfirst($s) }}</option>@endforeach
                            </select>
                        @endif
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Owner</label>
                        <select name="owner_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach ($members as $id => $name)<option value="{{ hashid($id) }}" @selected((int) $p->owner_id === (int) $id)>{{ $name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-span-2"><label class="text-xs font-semibold">Tags</label><input name="tags" value="{{ implode(', ', (array) $p->tags) }}" class="form-control form-control-sm" placeholder="comma, separated"></div>
                    <div class="col-span-2"><label class="text-xs font-semibold">Notes</label><textarea name="notes" rows="3" class="form-control form-control-sm" maxlength="5000">{{ $p->notes }}</textarea></div>
                </div>
                <div class="tva-lg-hint">Contact details you change are marked as confirmed by your team.</div>
                <button class="btn btn-sm btn-primary mt-3"><i data-lucide="save"></i>Save changes</button>
            </form>
            @endif

            <div class="tva-lg-panel">
                <div class="tva-lg-h">Fit</div>
                @if ($qualification)
                    <div class="tva-lg-sub mb-2">Against “{{ $qualification->profile?->name ?? 'a deleted profile' }}” · {{ \Carbon\Carbon::createFromTimestamp($qualification->created_at)->diffForHumans() }}</div>
                    @include('leadgen.review._qualification', ['q' => $qualification])
                @else
                    <div class="tva-lg-sub">Not scored yet.</div>
                @endif
                @if ($canSrch && $profiles->isNotEmpty() && !$p->suppressed_at)
                    <form method="POST" action="{{ route('leadgen.qualify.one', $base + ['id' => $p->id]) }}" class="flex gap-2 mt-3">
                        @csrf
                        <select name="profile_id" class="form-select form-select-sm">@foreach ($profiles as $pf)<option value="{{ hashid($pf->id) }}" @selected($qualification && $qualification->profile_id === $pf->id)>{{ $pf->name }}</option>@endforeach</select>
                        <button class="btn btn-sm btn-outline-secondary"><i data-lucide="award"></i>{{ $qualification ? 'Score again' : 'Score now' }}</button>
                    </form>
                @elseif ($profiles->isEmpty())
                    <div class="tva-lg-sub mt-2">Create an offer profile to score prospects.</div>
                @endif
            </div>

            <div class="tva-lg-panel">
                <div class="tva-lg-h">Outreach drafts</div>
                @forelse ($drafts as $d)
                    <div class="tva-lg-row">
                        <div style="min-width:0">
                            <a class="tva-lg-row__t text-primary" href="{{ route('leadgen.drafts.edit', $base + ['id' => $d->id]) }}">{{ $d->subject ?: \Illuminate\Support\Str::limit($d->body, 60) }}</a>
                            <div class="tva-lg-row__d">{{ ucfirst($d->channel) }} · {{ $d->status }} · {{ $d->ai_generated ? 'AI' : 'template' }}@if ($d->last_error) · <span class="text-warning">check warnings</span>@endif</div>
                        </div>
                    </div>
                @empty
                    <div class="tva-lg-sub">No drafts yet.</div>
                @endforelse
                @if ($canEdit && $profiles->isNotEmpty() && !$p->suppressed_at)
                    <form method="POST" action="{{ route('leadgen.drafts.store', $base + ['id' => $p->id]) }}" class="mt-3 grid grid-cols-2 gap-2">
                        @csrf
                        <select name="profile_id" class="form-select form-select-sm col-span-2">@foreach ($profiles as $pf)<option value="{{ hashid($pf->id) }}">{{ $pf->name }}</option>@endforeach</select>
                        <select name="channel" class="form-select form-select-sm">
                            <option value="email" @disabled(!$p->email)>Email{{ $p->email ? '' : ' (no address)' }}</option>
                            <option value="whatsapp">WhatsApp (opted-in only)</option>
                            <option value="messenger">Messenger / Instagram</option>
                        </select>
                        <select name="template_id" class="form-select form-select-sm">
                            <option value="">No template</option>
                            @foreach ($templates as $t)<option value="{{ hashid($t->id) }}">{{ $t->name }} ({{ $t->channel }})</option>@endforeach
                        </select>
                        <select name="language" class="form-select form-select-sm">@foreach (config('leadgen.languages') as $code => $name)<option value="{{ $code }}" @selected($profiles->first()->language === $code)>{{ \Illuminate\Support\Str::before($name, ' (') }}</option>@endforeach</select>
                        <select name="tone" class="form-select form-select-sm">@foreach (\App\Services\LeadGen\Ai\Drafter::TONES as $k => $l)<option value="{{ $k }}" @selected($profiles->first()->tone === $k)>{{ $l }}</option>@endforeach</select>
                        <select name="length" class="form-select form-select-sm">
                            <option value="short">Short</option><option value="medium" selected>Medium</option><option value="detailed">Detailed</option>
                        </select>
                        <div class="flex gap-2">
                            <button name="mode" value="ai" class="btn btn-sm btn-primary flex-1"><i data-lucide="pen-tool"></i>Write with AI</button>
                            <button name="mode" value="template" class="btn btn-sm btn-outline-secondary" title="Fill the chosen template, no AI"><i data-lucide="file-text"></i>Template</button>
                        </div>
                        <div class="col-span-2 tva-lg-hint">Drafts are saved for you to edit. Nothing is sent from here.</div>
                    </form>
                @endif
            </div>

            <div class="tva-lg-panel">
                <div class="tva-lg-h">Lists</div>
                @forelse ($p->lists as $l)
                    <a class="tva-tag tva-tag--{{ crc32($l->name) % 6 }} mr-1" href="{{ route('leadgen.prospects.index', $base + ['list_id' => $l->id]) }}">{{ $l->name }}</a>
                @empty
                    <div class="tva-lg-sub">Not in any list.</div>
                @endforelse
            </div>

            @if ($events->isNotEmpty())
            <div class="tva-lg-panel">
                <div class="tva-lg-h">Activity</div>
                @foreach ($events as $e)
                    <div class="tva-lg-sub" style="padding:2px 0">{{ \Carbon\Carbon::createFromTimestamp($e->created_at)->format('M j, H:i') }} — {{ str_replace(['.', '_'], [' ', ' '], $e->event) }}</div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
