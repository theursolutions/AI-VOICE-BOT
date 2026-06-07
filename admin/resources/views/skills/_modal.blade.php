<div id="{{ $modalId }}" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <form method="POST" action="{{ $action }}" class="tva-modal__panel">
        @csrf
        @if ($method !== 'POST') @method($method) @endif
        <input type="hidden" name="project_id" value="{{ $projectId }}">

        <div class="tva-modal__head">
            <i data-lucide="tag" class="w-4 h-4 mr-2 inline" style="color:#6366f1;"></i>
            {{ $title }}
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>

        <div class="tva-modal__body">
            <div class="mb-3">
                <label class="form-label">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" required maxlength="120" class="form-control"
                       value="{{ old('name', $skill->name ?? '') }}"
                       placeholder="Billing">
            </div>
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" rows="2" maxlength="500" class="form-control"
                          placeholder="Customer billing, refunds, invoices.">{{ old('description', $skill->description ?? '') }}</textarea>
            </div>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="form-label">SLA (seconds)</label>
                    <input type="number" name="sla_seconds" min="0" class="form-control"
                           value="{{ old('sla_seconds', $skill->sla_seconds ?? '') }}"
                           placeholder="optional">
                </div>
                @if ($showStatus)
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            @foreach (['active','archived'] as $s)
                                <option value="{{ $s }}" @selected(($skill->status ?? 'active') === $s)>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
            <label class="flex items-center gap-2 cursor-pointer mt-2">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" name="is_default" value="1" @checked(!empty($skill?->is_default))>
                <span class="text-sm">Make this the default skill (fallback for unassigned numbers)</span>
            </label>
        </div>

        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" class="w-3 h-3 mr-1 inline"></i> Save
            </button>
        </div>
    </form>
</div>
