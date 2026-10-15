{{--
    Lead Generation page header: icon + title, project switcher, a one-line
    "how to use this page" note, and the section tabs.

    Expects $client, $project (nullable), $projects. Optional:
      $title     page title (defaults to the tab's label)
      $subtitle  overrides the page's help line
      $hideTitle true on the Overview, which has its own banner
    Tabs only show for actions the member's role allows.
--}}
@php
    $u    = auth()->user();
    $canA = fn (string $a) => $u && $u->canAbility($client->id, 'leadgen', $a);
    $pq   = $project ? ['project_id' => $project->id] : [];

    // route => [label, icon, ability, also-active-on]
    $tabs = array_filter([
        ['leadgen.index',           'Overview',       'layout-grid',     'view',     []],
        ['leadgen.prospects.index', 'Prospects',      'building',        'view',     ['leadgen.prospects.show', 'leadgen.drafts.*']],
        ['leadgen.review.index',    'Review',         'clipboard-check', 'view',     []],
        ['leadgen.lists.index',     'Lists',          'list',            'view',     []],
        ['leadgen.searches.create', 'New search',     'search',          'search',   []],
        ['leadgen.searches.index',  'History',        'clock',           'view',     ['leadgen.searches.show']],
        ['leadgen.imports.create',  'Import',         'upload',          'import',   ['leadgen.imports.*']],
        ['leadgen.profiles.index',  'Offer profiles', 'target',          'settings', ['leadgen.profiles.*']],
        ['leadgen.templates.index', 'Templates',      'file-text',       'settings', ['leadgen.templates.*']],
    ], fn ($t) => $canA($t[3]));

    $current = collect($tabs)->first(fn ($t) => request()->routeIs($t[0], ...$t[4]));

    // One line per page on what it's for and how to use it.
    $help = [
        'leadgen.index'           => 'Find businesses that fit what you sell, check their details, score them with AI and prepare outreach — nothing is ever sent without your approval.',
        'leadgen.prospects.index' => 'Every business you have found or imported. Tick rows, then choose an action: check their websites, score them, add them to a list or to your CRM.',
        'leadgen.prospects.show'  => 'Everything known about this business and where each detail came from. Score it, write a draft, or add it to your CRM.',
        'leadgen.review.index'    => 'AI scores each prospect against an offer profile and shows its reasons. Approve the good fits — and change any score you disagree with.',
        'leadgen.lists.index'     => 'Group prospects into lists, e.g. “Dubai agencies – October”. A campaign sends to one list.',
        'leadgen.searches.create' => 'Pick an area, tick the types of business you want, and start. The search runs in the background — you can leave the page.',
        'leadgen.searches.index'  => 'Your past searches and imports. Open one to see what it found, pause or cancel it, or run it again.',
        'leadgen.searches.show'   => 'Progress and results of this search. New businesses are added to your Prospects automatically.',
        'leadgen.imports.create'  => 'Upload your own list as CSV or Excel. On the next step you match your columns (name, website, email…).',
        'leadgen.imports.map'     => 'Tell us which of your columns is which. Only the business name is required.',
        'leadgen.profiles.index'  => 'Describe what you sell and who to. AI scores and drafts are based on the profile you choose — agencies: one per client.',
        'leadgen.profiles.create' => 'The clearer you describe your offer and ideal customer, the better the scores and drafts.',
        'leadgen.profiles.edit'   => 'The clearer you describe your offer and ideal customer, the better the scores and drafts.',
        'leadgen.templates.index' => 'Reusable message outlines with {{variables}}. The AI adapts a template to each business, or you can fill one as-is.',
        'leadgen.templates.create'=> 'Write the outline once; {{business_name}}, {{city}} and the rest are filled in for each business.',
        'leadgen.templates.edit'  => 'Write the outline once; {{business_name}}, {{city}} and the rest are filled in for each business.',
        'leadgen.drafts.edit'     => 'Read and edit before using it. We flag anything that pretends you already know them, and any placeholder left empty.',
    ];
    $helpText = $subtitle ?? collect($help)->first(fn ($t, $r) => request()->routeIs($r));
    $headIcon = $current[2] ?? 'target';
@endphp
@include('leadgen._styles')

<div class="tva-lg-head">
    @unless ($hideTitle ?? false)
        <div class="tva-lg-head__ico"><i data-lucide="{{ $headIcon }}"></i></div>
        <div class="tva-lg-head__t">{{ $title ?? ($current[1] ?? 'Lead Generation') }}</div>
    @endunless
    @if ($projects->count() > 1)
        <form method="GET" class="ml-auto flex items-center gap-2">
            <span class="text-xs text-slate-500 hidden sm:inline">Project</span>
            <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:160px">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected($project && (int) $project->id === (int) $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    @endif
</div>

@if ($project)
<nav class="tva-lg-tabs2" aria-label="Lead Generation sections">
    @foreach ($tabs as [$route, $label, $icon, $ability, $also])
        @php $on = request()->routeIs($route, ...$also); @endphp
        <a href="{{ route($route, ['client' => $client->slug] + $pq) }}" class="tva-lg-tab2 {{ $on ? 'tva-lg-tab2--on' : '' }}" @if ($on) aria-current="page" @endif>
            <i data-lucide="{{ $icon }}"></i>{{ $label }}
        </a>
    @endforeach
</nav>
@endif

@if ($helpText && !($hideTitle ?? false))
    <div class="tva-lg-help"><i data-lucide="info"></i><span>{{ $helpText }}</span></div>
@endif

@if (session('success'))
    <div class="alert alert-success mt-4 flex items-center gap-2"><i data-lucide="check-circle" class="w-4 h-4"></i>{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger mt-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
@endif
