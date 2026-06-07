<div id="{{ $modalId }}" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <form method="POST" action="{{ $action }}" class="tva-modal__panel" style="max-width:640px;">
        @csrf
        @if ($method !== 'POST') @method($method) @endif
        <input type="hidden" name="project_id" value="{{ $projectId }}">

        <div class="tva-modal__head">
            <i data-lucide="user" class="w-4 h-4 mr-2 inline" style="color:#7c3aed;"></i>
            {{ $title }}
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>

        <div class="tva-modal__body">
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" required maxlength="120" class="form-control"
                           value="{{ old('name', $agent->name ?? '') }}"
                           placeholder="Sarah">
                </div>
                <div>
                    <label class="form-label">Voice</label>
                    <select name="voice_id" class="form-select">
                        <option value="">— project default —</option>
                        @foreach ($voices as $v)
                            <option value="{{ $v->id }}" @selected(($agent->voice_id ?? null) == $v->id)>
                                {{ $v->name }} ({{ $v->language }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Persona / system prompt</label>
                <textarea name="persona" rows="5" maxlength="4000" class="form-control"
                          placeholder="You handle billing questions. Be empathetic and concise. Confirm account details before discussing balances.">{{ old('persona', $agent->persona ?? '') }}</textarea>
                <small class="text-slate-500 text-xs">Injected into the LLM as a system message so the bot stays in this character.</small>
            </div>

            <div class="mb-3">
                <label class="form-label">Skills</label>
                @if ($skills->isEmpty())
                    <div class="text-xs text-slate-500">
                        No skills yet. <a href="{{ route('skills.index', ['client' => $client->slug]) }}?project_id={{ $projectId }}" class="text-primary">Create one first</a>.
                    </div>
                @else
                    @php
                        $assigned = isset($agent) && $agent ? $agent->skills->pluck('id')->all() : [];
                    @endphp
                    <select name="skill_ids[]" class="tom-select w-full" multiple data-placeholder="Pick one or more skills…">
                        @foreach ($skills as $s)
                            <option value="{{ $s->id }}" @selected(in_array($s->id, $assigned))>{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <small class="text-slate-500 text-xs">Pick multiple — the agent handles all selected skills.</small>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-3">
                @if ($showStatus)
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            @foreach (['active','archived'] as $st)
                                <option value="{{ $st }}" @selected(($agent->status ?? 'active') === $st)>{{ ucfirst($st) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <label class="flex items-end gap-2 cursor-pointer pb-2">
                    <input type="hidden" name="is_default" value="0">
                    <input type="checkbox" name="is_default" value="1" @checked(!empty($agent?->is_default))>
                    <span class="text-sm">Default agent for widget</span>
                </label>
            </div>
        </div>

        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" class="w-3 h-3 mr-1 inline"></i> Save
            </button>
        </div>
    </form>
</div>
