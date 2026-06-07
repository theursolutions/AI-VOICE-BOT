@extends('layouts.master')

@section('content')
<style>
    .tva-pp-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-pp-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }

    .tva-pp-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px 24px; margin-bottom:18px; }
    .tva-pp-card__head { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
    .tva-pp-card__title { font-size:15px; font-weight:600; color:#0f172a; }
    .tva-pp-card__subtitle { font-size:11px; color:#64748b; margin-top:2px; }

    .tva-pp-logo {
        width:96px; height:96px; border-radius:18px;
        background:#f8fafc; border:2px dashed #cbd5e1;
        display:flex; align-items:center; justify-content:center;
        color:#94a3b8; font-size:32px; font-weight:700;
        overflow:hidden; flex-shrink:0;
    }
    .tva-pp-logo img { width:100%; height:100%; object-fit:cover; }

    .tva-pp-grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
    @media (max-width: 720px) { .tva-pp-grid { grid-template-columns: 1fr; } }

    html.dark .tva-pp-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-pp-card__head { border-bottom-color:#334155; }
    html.dark .tva-pp-card__title { color:#f1f5f9; }
    html.dark .tva-pp-logo { background:#0f172a; border-color:#334155; }
</style>

<div class="content">
    <div class="tva-pp-hero mt-6">
        <div class="tva-pp-hero__icon">🏷️</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Project Profile</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Your business identity — logo, name, contact, industry. Shows up in the topbar and is fed to the bot so it stays in character.
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    {{-- Project picker --}}
    <div class="intro-y box p-3 mb-4">
        <form method="GET">
            <label class="text-xs text-slate-500 uppercase tracking-wider font-semibold">Project</label>
            <select name="project_id" class="form-select mt-1 w-full md:w-1/3" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((int) $projectId === (int) $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if ($project)
    <form method="POST" action="{{ route('project-profile.update', ['client' => $client->slug]) }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="project_id" value="{{ $project->id }}">

        {{-- Identity --}}
        <div class="tva-pp-card">
            <div class="tva-pp-card__head">
                <div style="width:36px; height:36px; border-radius:10px; background:#ede9fe; color:#7c3aed; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="image" class="w-4 h-4"></i>
                </div>
                <div>
                    <div class="tva-pp-card__title">Identity</div>
                    <div class="tva-pp-card__subtitle">Square logo (PNG/SVG up to 2 MB) shows in the topbar at ~32 px.</div>
                </div>
            </div>

            <div class="flex flex-col md:flex-row items-start gap-5">
                <div class="tva-pp-logo">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $project->name }} logo">
                    @else
                        {{ strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $project->name) ?: 'P', 0, 2)) }}
                    @endif
                </div>
                <div class="flex-1 w-full">
                    <div class="mb-3">
                        <label class="form-label">Project name <span class="text-danger">*</span></label>
                        <input type="text" name="name" required maxlength="120" class="form-control"
                               value="{{ old('name', $project->name) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo</label>
                        <input type="file" name="logo" accept="image/*" class="form-control">
                        <small class="text-slate-500 text-xs">Leave empty to keep the current logo.</small>
                    </div>
                    @if ($logoUrl)
                        <label class="inline-flex items-center gap-2 cursor-pointer text-sm text-danger">
                            <input type="checkbox" name="remove_logo" value="1">
                            <span>Remove current logo</span>
                        </label>
                    @endif
                </div>
            </div>
        </div>

        {{-- Contact --}}
        <div class="tva-pp-card">
            <div class="tva-pp-card__head">
                <div style="width:36px; height:36px; border-radius:10px; background:#dbeafe; color:#1e40af; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="mail" class="w-4 h-4"></i>
                </div>
                <div>
                    <div class="tva-pp-card__title">Contact</div>
                    <div class="tva-pp-card__subtitle">Public website + support reach. Used inside bot answers when callers ask "how do I reach a human?".</div>
                </div>
            </div>

            <div class="tva-pp-grid">
                <div>
                    <label class="form-label">Public website</label>
                    <input type="url" name="website" maxlength="255" class="form-control"
                           value="{{ old('website', $profile['website']) }}" placeholder="https://acme.com">
                </div>
                <div>
                    <label class="form-label">Support email</label>
                    <input type="email" name="support_email" maxlength="255" class="form-control"
                           value="{{ old('support_email', $profile['support_email']) }}" placeholder="help@acme.com">
                </div>
                <div>
                    <label class="form-label">Support phone</label>
                    <input type="text" name="support_phone" maxlength="60" class="form-control"
                           value="{{ old('support_phone', $profile['support_phone']) }}" placeholder="+1 (555) 010-0100">
                </div>
                <div>
                    <label class="form-label">Business hours</label>
                    <input type="text" name="business_hours" maxlength="500" class="form-control"
                           value="{{ old('business_hours', $profile['business_hours']) }}" placeholder="Mon–Fri 9 AM – 6 PM EST">
                </div>
            </div>
        </div>

        {{-- Context --}}
        <div class="tva-pp-card">
            <div class="tva-pp-card__head">
                <div style="width:36px; height:36px; border-radius:10px; background:#dcfce7; color:#15803d; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="briefcase" class="w-4 h-4"></i>
                </div>
                <div>
                    <div class="tva-pp-card__title">Business context</div>
                    <div class="tva-pp-card__subtitle">Helps the bot tailor its tone and answers. Fed into the LLM system prompt.</div>
                </div>
            </div>

            <div class="tva-pp-grid">
                <div>
                    <label class="form-label">Industry</label>
                    <input type="text" name="industry" maxlength="120" class="form-control"
                           value="{{ old('industry', $profile['industry']) }}" placeholder="SaaS / E-commerce / Healthcare">
                </div>
                <div>
                    <label class="form-label">Timezone</label>
                    <select name="timezone" class="form-select">
                        @foreach (\DateTimeZone::listIdentifiers() as $tz)
                            <option value="{{ $tz }}" @selected(($profile['timezone'] ?? 'UTC') === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Default language</label>
                    <select name="language" class="form-select">
                        @foreach ([
                            'en' => 'English',
                            'es' => 'Spanish',
                            'fr' => 'French',
                            'de' => 'German',
                            'pt' => 'Portuguese',
                            'it' => 'Italian',
                            'nl' => 'Dutch',
                            'ar' => 'Arabic',
                            'hi' => 'Hindi',
                            'ur' => 'Urdu',
                            'zh' => 'Chinese',
                            'ja' => 'Japanese',
                            'ko' => 'Korean',
                        ] as $code => $label)
                            <option value="{{ $code }}" @selected(($profile['language'] ?? 'en') === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div></div>
                <div class="md:col-span-2" style="grid-column: span 2 / span 2;">
                    <label class="form-label">About / tagline</label>
                    <textarea name="about" rows="3" maxlength="1000" class="form-control"
                              placeholder="Acme builds the world's friendliest project-management software for small teams.">{{ old('about', $profile['about']) }}</textarea>
                    <small class="text-slate-500 text-xs">Used in the bot's system prompt so it can introduce the business in conversation.</small>
                </div>
            </div>
        </div>

        <div class="text-right">
            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" class="w-4 h-4 mr-1 inline"></i> Save profile
            </button>
        </div>
    </form>
    @else
        <div class="tva-pp-card text-center text-slate-400 py-12">
            No project found in this workspace.
        </div>
    @endif
</div>

<script>
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}
</script>
@endsection
