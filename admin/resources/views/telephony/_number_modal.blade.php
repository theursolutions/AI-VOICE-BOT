<div id="{{ $modalId }}" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <form method="POST" action="{{ route('telephony.save-number', ['client' => $client->slug]) }}" class="tva-modal__panel" style="max-width:640px;">
        @csrf
        <input type="hidden" name="project_id"   value="{{ $project->id }}">
        <input type="hidden" name="number_index" value="{{ $numberIndex }}">

        <div class="tva-modal__head">
            <i data-lucide="phone" class="w-4 h-4 mr-2 inline" style="color:#3730a3;"></i>
            {{ $title }} <span class="text-slate-400 text-sm ml-1">· {{ $project->name }}</span>
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>

        <div class="tva-modal__body">
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="form-label">Twilio number <span class="text-danger">*</span></label>
                    <input type="text" name="phone_number" required maxlength="32" class="form-control"
                           value="{{ old('phone_number', $number['phone_number'] ?? '') }}"
                           placeholder="+12346352160">
                    <small class="text-slate-500 text-xs">E.164 format. Leading <code>+</code> auto-added.</small>
                </div>
                <div>
                    <label class="form-label">Polly fallback voice</label>
                    <select name="welcome_voice" class="form-select">
                        @php $wv = $number['welcome_voice'] ?? 'Polly.Matthew'; @endphp
                        @foreach ([
                            'Polly.Matthew'  => 'Matthew (US male)',
                            'Polly.Joanna'   => 'Joanna (US female)',
                            'Polly.Brian'    => 'Brian (UK male)',
                            'Polly.Amy'      => 'Amy (UK female)',
                            'Polly.Salli'    => 'Salli (US female)',
                            'Polly.Justin'   => 'Justin (US male)',
                        ] as $k => $v)
                            <option value="{{ $k }}" @selected($wv === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                    <small class="text-slate-500 text-xs">
                        Fallback only. Calls normally speak in the routed agent's cloned
                        voice — this stock voice is used just when that can't be synthesized.
                    </small>
                </div>
            </div>

            <label class="tva-num-modal-row mb-4">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked(($number['enabled'] ?? true))>
                <span class="text-sm">Number is active — accept incoming calls</span>
            </label>

            {{-- Routing tabs --}}
            @php $routingType = $number['routing_type'] ?? 'agents'; @endphp
            <div class="tva-routing-tabs">
                <input type="hidden" name="routing_type" value="{{ $routingType }}">
                <div class="tva-routing-tab {{ $routingType === 'agents' ? 'is-selected' : '' }}" data-routing="agents">
                    <i data-lucide="users" class="w-3 h-3 mr-1 inline"></i> Specific agents
                </div>
                <div class="tva-routing-tab {{ $routingType === 'skill' ? 'is-selected' : '' }}" data-routing="skill">
                    <i data-lucide="tag" class="w-3 h-3 mr-1 inline"></i> Skill pool
                </div>
                <div class="tva-routing-tab {{ $routingType === 'flow' ? 'is-selected' : '' }}" data-routing="flow">
                    <i data-lucide="git-branch" class="w-3 h-3 mr-1 inline"></i> Conversation flow
                </div>
            </div>

            {{-- Agents block --}}
            <div data-routing-block="agents" style="display: {{ $routingType === 'agents' ? '' : 'none' }};">
                <label class="form-label">Route calls to these agents</label>
                @if ($agents->isEmpty())
                    <div class="text-sm text-slate-500 p-3 bg-slate-50 rounded border border-slate-200">
                        No agents yet. <a href="{{ route('bot-agents.index', ['client' => $client->slug]) }}" class="text-primary">Create one first</a>.
                    </div>
                @else
                    @php $assigned = (array) ($number['agent_ids'] ?? []); @endphp
                    <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto" style="padding:2px;">
                        @foreach ($agents as $a)
                            <label class="tva-num-modal-row">
                                <input type="checkbox" name="agent_ids[]" value="{{ $a->id }}" @checked(in_array($a->id, $assigned))>
                                <span class="text-sm">
                                    {{ $a->name }}
                                    {{-- The call is answered in the agent's voice, so show it
                                         here: this screen is where people come looking for
                                         "the telephony voice setting". --}}
                                    @if ($a->voice)
                                        <span class="text-xs" style="color:#7c3aed;">· 🎤 {{ $a->voice->name }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">· no voice — will use the fallback below</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <small class="text-slate-500 text-xs mt-1 block">
                        Multiple agents share the load — one is picked per call.
                        Calls are answered in that agent's cloned voice; change it under
                        <a href="{{ route('bot-agents.index', ['client' => $client->slug]) }}?project_id={{ hashid($project->id) }}" class="text-primary">Agents</a>.
                    </small>
                @endif
            </div>

            {{-- Skill block --}}
            <div data-routing-block="skill" style="display: {{ $routingType === 'skill' ? '' : 'none' }};">
                <label class="form-label">Route calls to this skill</label>
                @if ($skills->isEmpty())
                    <div class="text-sm text-slate-500 p-3 bg-slate-50 rounded border border-slate-200">
                        No skills yet. <a href="{{ route('skills.index', ['client' => $client->slug]) }}" class="text-primary">Create one first</a>.
                    </div>
                @else
                    <select name="skill_id" class="form-select">
                        <option value="">— pick a skill —</option>
                        @foreach ($skills as $s)
                            <option value="{{ $s->id }}" @selected((int) ($number['skill_id'] ?? 0) === $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <small class="text-slate-500 text-xs">Any active agent in this skill can take the call.</small>
                @endif
            </div>

            {{-- Flow block --}}
            <div data-routing-block="flow" style="display: {{ $routingType === 'flow' ? '' : 'none' }};">
                <label class="form-label">Answer calls with this conversation flow</label>
                @if (($flows ?? collect())->isEmpty())
                    <div class="text-sm text-slate-500 p-3 bg-slate-50 rounded border border-slate-200">
                        No active flows yet. <a href="{{ route('flows.index', ['client' => $client->slug]) }}?project_id={{ hashid($project->id) }}" class="text-primary">Build one in the Flow Builder</a> (set status to <b>active</b> to make it bindable).
                    </div>
                @else
                    <select name="flow_id" class="form-select">
                        <option value="">— pick a flow —</option>
                        @foreach ($flows as $f)
                            <option value="{{ $f->id }}" @selected((int) ($number['flow_id'] ?? 0) === $f->id)>
                                {{ $f->name }} @if($f->language) · {{ strtoupper($f->language) }} @endif
                            </option>
                        @endforeach
                    </select>
                    <small class="text-slate-500 text-xs">Flow runs first. AI takes over only when a <b>Transfer to AI</b> node is reached.</small>
                @endif
            </div>
        </div>

        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" class="w-3 h-3 mr-1 inline"></i> Save number
            </button>
        </div>
    </form>
</div>
