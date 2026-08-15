@extends('layouts.ops')

@section('content')
<style>
    .tsm-wrap { max-width: 1080px; }

    .tsm-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px 22px; margin-bottom:16px; }
    .tsm-card__head { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
    .tsm-card__title { font-size:15px; font-weight:700; color:#0f172a; }

    .tsm-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; }
    @media (max-width:760px){ .tsm-grid{ grid-template-columns:1fr; } }
    .tsm-field { margin:0; }
    .tsm-field.span3 { grid-column:1 / -1; }
    .tsm-field > label { display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:6px; }
    .tsm-field .hint { font-size:11px; color:#94a3b8; font-weight:400; margin-top:4px; }
    .tsm-input, .tsm-textarea, .tsm-select {
        width:100%; padding:9px 12px; border:1px solid #e2e8f0; border-radius:9px;
        background:#fff; font-size:13px; color:#0f172a; font-family:inherit;
    }
    .tsm-textarea { resize:vertical; min-height:86px; }
    .tsm-input:focus, .tsm-textarea:focus, .tsm-select:focus {
        outline:none; border-color:var(--tva-accent); box-shadow:0 0 0 3px rgba(255,184,0,.15);
    }
    .tsm-check { display:inline-flex; align-items:center; gap:8px; font-size:13px; color:#334155; font-weight:600; }

    /* ── Row list ── */
    .tsm-row { border:1px solid #e2e8f0; border-radius:14px; background:#fff; margin-bottom:12px; overflow:hidden; }
    .tsm-row.is-off { background:#f8fafc; border-style:dashed; }
    .tsm-row__head { display:flex; align-items:center; gap:14px; padding:14px 18px; }
    .tsm-av {
        width:42px; height:42px; border-radius:50%; flex-shrink:0; object-fit:cover;
        display:flex; align-items:center; justify-content:center;
        font-size:14px; font-weight:800; color:#fff; letter-spacing:.02em;
        background:linear-gradient(135deg,#f59e0b,#c2410c);
    }
    .tsm-row__who { min-width:0; flex:1; }
    .tsm-row__name { font-size:14px; font-weight:700; color:#0f172a; }
    .tsm-row__meta { font-size:12px; color:#64748b; }
    .tsm-row__quote { font-size:12.5px; color:#475569; margin-top:3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .tsm-stars { color:#f59e0b; font-size:13px; letter-spacing:1px; white-space:nowrap; }
    .tsm-pill { font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }
    .tsm-pill--on  { background:#dcfce7; color:#15803d; }
    .tsm-pill--off { background:#f1f5f9; color:#64748b; }
    .tsm-ord { font-family:ui-monospace,monospace; font-size:11px; color:#94a3b8; white-space:nowrap; }
    .tsm-acts { display:flex; align-items:center; gap:6px; margin-left:auto; }
    .tsm-row__body { display:none; padding:4px 18px 18px; border-top:1px solid #e2e8f0; }
    .tsm-row.is-editing .tsm-row__body { display:block; }
    .tsm-row__body .tsm-grid { margin-top:16px; }

    .tsm-empty { text-align:center; padding:44px 20px; color:#94a3b8; font-size:13px; }

    html.dark .tsm-card, html.dark .tsm-row { background:#1e293b; border-color:#334155; }
    html.dark .tsm-row.is-off { background:#172033; }
    html.dark .tsm-card__title, html.dark .tsm-row__name { color:#f1f5f9; }
    html.dark .tsm-card__head, html.dark .tsm-row__body { border-color:#334155; }
    html.dark .tsm-field > label, html.dark .tsm-check { color:#cbd5e1; }
    html.dark .tsm-input, html.dark .tsm-textarea, html.dark .tsm-select { background:#0f172a; color:#f1f5f9; border-color:#334155; }
    html.dark .tsm-row__quote { color:#94a3b8; }
    html.dark .tsm-pill--off { background:#334155; color:#cbd5e1; }
</style>

@php
    // Rebuild the "add" form from old() after a validation failure so the
    // operator doesn't lose a long quote to a typo'd rating.
    $stars = fn ($n) => str_repeat('★', max(0, min(5, (int) $n))) . str_repeat('☆', 5 - max(0, min(5, (int) $n)));
@endphp

<div class="content tsm-wrap">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">💬</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Testimonials</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                What your customers say, shown in the auto-scrolling carousel on the homepage.
                Add, edit, reorder or hide them here — changes go live immediately.
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

    <div class="flex items-center gap-2 mb-4">
        <a href="{{ url('/#testimonials') }}" target="_blank" class="btn btn-secondary btn-sm">
            <i data-lucide="external-link" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i> View on homepage
        </a>
        <a href="{{ route('ops.content.index') }}" class="btn btn-secondary btn-sm">
            <i data-lucide="layout-template" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i> Edit section heading
        </a>
        <div class="ml-auto" style="font-size:12px;color:#64748b;">
            {{ $testimonials->where('is_active', true)->count() }} live · {{ $testimonials->count() }} total
        </div>
    </div>

    {{-- ── Add new ─────────────────────────────────────────────────── --}}
    <div class="tsm-card">
        <div class="tsm-card__head">
            <div class="tsm-card__title">➕ Add a testimonial</div>
        </div>
        <form method="POST" action="{{ route('ops.testimonials.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="tsm-grid">
                <div class="tsm-field">
                    <label>Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="name" class="tsm-input" value="{{ old('name') }}" placeholder="Ayesha Khan" required>
                </div>
                <div class="tsm-field">
                    <label>Role / job title</label>
                    <input type="text" name="role" class="tsm-input" value="{{ old('role') }}" placeholder="Practice Manager">
                </div>
                <div class="tsm-field">
                    <label>Company</label>
                    <input type="text" name="company" class="tsm-input" value="{{ old('company') }}" placeholder="Smile Dental Clinic">
                </div>
                <div class="tsm-field span3">
                    <label>Quote <span style="color:#dc2626;">*</span></label>
                    <textarea name="quote" class="tsm-textarea" maxlength="600" required
                              placeholder="Two or three sentences in their own words — specific beats glowing.">{{ old('quote') }}</textarea>
                    <div class="hint">Up to 600 characters. Quote marks are added automatically.</div>
                </div>
                <div class="tsm-field">
                    <label>Rating</label>
                    <select name="rating" class="tsm-select">
                        @for ($n = 5; $n >= 1; $n--)
                            <option value="{{ $n }}" @selected((int) old('rating', 5) === $n)>{{ $stars($n) }} &nbsp; {{ $n }}/5</option>
                        @endfor
                    </select>
                </div>
                <div class="tsm-field">
                    <label>Photo</label>
                    <input type="file" name="avatar" class="tsm-input" accept="image/*">
                    <div class="hint">Optional — JPG/PNG/WebP, max 2 MB. Falls back to initials.</div>
                </div>
                <div class="tsm-field">
                    <label>…or photo URL</label>
                    <input type="text" name="avatar_url" class="tsm-input" value="{{ old('avatar_url') }}" placeholder="https://…">
                </div>
                <div class="tsm-field">
                    <label>Display order</label>
                    <input type="number" name="sort_order" class="tsm-input" value="{{ old('sort_order') }}" min="0" placeholder="auto">
                    <div class="hint">Lower shows first. Leave blank to append.</div>
                </div>
                <div class="tsm-field" style="display:flex;align-items:flex-end;">
                    <label class="tsm-check">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Show on homepage
                    </label>
                </div>
                <div class="tsm-field" style="display:flex;align-items:flex-end;justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="plus" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Add testimonial
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- ── Existing rows ───────────────────────────────────────────── --}}
    @forelse ($testimonials as $t)
        <div class="tsm-row {{ $t->is_active ? '' : 'is-off' }}" id="ts-{{ $t->id }}">
            <div class="tsm-row__head">
                @if ($t->avatar_url)
                    <img src="{{ $t->avatar_url }}" alt="" class="tsm-av">
                @else
                    <div class="tsm-av">{{ $t->initials }}</div>
                @endif

                <div class="tsm-row__who">
                    <div class="tsm-row__name">{{ $t->name }}</div>
                    <div class="tsm-row__meta">{{ $t->attribution ?: '—' }}</div>
                    <div class="tsm-row__quote">{{ $t->quote }}</div>
                </div>

                <div class="tsm-stars" title="{{ $t->rating }}/5">{{ $stars($t->rating) }}</div>
                <span class="tsm-ord">#{{ $t->sort_order }}</span>
                <span class="tsm-pill {{ $t->is_active ? 'tsm-pill--on' : 'tsm-pill--off' }}">
                    {{ $t->is_active ? 'Live' : 'Hidden' }}
                </span>

                <div class="tsm-acts">
                    <button type="button" class="btn btn-secondary btn-sm" data-ts-edit="{{ $t->id }}">
                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                    </button>
                    <form method="POST" action="{{ route('ops.testimonials.toggle', $t->id) }}" class="inline">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm" title="{{ $t->is_active ? 'Hide from homepage' : 'Show on homepage' }}">
                            <i data-lucide="{{ $t->is_active ? 'eye-off' : 'eye' }}" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('ops.testimonials.delete', $t->id) }}" class="inline"
                          data-confirm="Delete the testimonial from {{ $t->name }}? This cannot be undone.">
                        @csrf
                        <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                </div>
            </div>

            <div class="tsm-row__body">
                <form method="POST" action="{{ route('ops.testimonials.update', $t->id) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="tsm-grid">
                        <div class="tsm-field">
                            <label>Name <span style="color:#dc2626;">*</span></label>
                            <input type="text" name="name" class="tsm-input" value="{{ $t->name }}" required>
                        </div>
                        <div class="tsm-field">
                            <label>Role / job title</label>
                            <input type="text" name="role" class="tsm-input" value="{{ $t->role }}">
                        </div>
                        <div class="tsm-field">
                            <label>Company</label>
                            <input type="text" name="company" class="tsm-input" value="{{ $t->company }}">
                        </div>
                        <div class="tsm-field span3">
                            <label>Quote <span style="color:#dc2626;">*</span></label>
                            <textarea name="quote" class="tsm-textarea" maxlength="600" required>{{ $t->quote }}</textarea>
                        </div>
                        <div class="tsm-field">
                            <label>Rating</label>
                            <select name="rating" class="tsm-select">
                                @for ($n = 5; $n >= 1; $n--)
                                    <option value="{{ $n }}" @selected($t->rating === $n)>{{ $stars($n) }} &nbsp; {{ $n }}/5</option>
                                @endfor
                            </select>
                        </div>
                        <div class="tsm-field">
                            <label>Replace photo</label>
                            <input type="file" name="avatar" class="tsm-input" accept="image/*">
                        </div>
                        <div class="tsm-field">
                            <label>Photo URL</label>
                            <input type="text" name="avatar_url" class="tsm-input" value="{{ $t->avatar_url }}">
                            <div class="hint">Clear this (and upload nothing) to remove the photo.</div>
                        </div>
                        <div class="tsm-field">
                            <label>Display order</label>
                            <input type="number" name="sort_order" class="tsm-input" value="{{ $t->sort_order }}" min="0">
                        </div>
                        <div class="tsm-field" style="display:flex;align-items:flex-end;">
                            <label class="tsm-check">
                                <input type="checkbox" name="is_active" value="1" @checked($t->is_active)> Show on homepage
                            </label>
                        </div>
                        <div class="tsm-field" style="display:flex;align-items:flex-end;justify-content:flex-end;gap:8px;">
                            <button type="button" class="btn btn-secondary" data-ts-edit="{{ $t->id }}">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="save" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Save
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @empty
        <div class="tsm-card">
            <div class="tsm-empty">
                No testimonials yet — the homepage section stays hidden until you add one.
            </div>
        </div>
    @endforelse
</div>

<script>
(function () {
    if (window.lucide?.createIcons) { try { window.lucide.createIcons({ icons: (window.lucide.icons || {}), nameAttr: "data-lucide" }); } catch (_) {} }

    document.querySelectorAll('[data-ts-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = document.getElementById('ts-' + btn.dataset.tsEdit);
            if (row) row.classList.toggle('is-editing');
        });
    });
})();
</script>
@endsection
