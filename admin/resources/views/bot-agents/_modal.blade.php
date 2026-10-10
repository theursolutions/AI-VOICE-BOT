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
            @php $atype = old('type', $agent->type ?? 'ai'); @endphp
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" required maxlength="120" class="form-control"
                           value="{{ old('name', $agent->name ?? '') }}"
                           placeholder="Sarah">
                </div>
                <div>
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select" onchange="(function(s){var f=s.closest('form');f.querySelectorAll('.js-human').forEach(e=>e.style.display=s.value==='human'?'':'none');f.querySelectorAll('.js-ai').forEach(e=>e.style.display=s.value==='human'?'none':'');})(this)">
                        <option value="ai" @selected($atype==='ai')>🤖 AI agent</option>
                        <option value="human" @selected($atype==='human')>🙋 Human agent</option>
                    </select>
                </div>
            </div>

            {{-- Human agent fields --}}
            <div class="grid grid-cols-2 gap-3 mb-3 js-human" style="{{ $atype==='human' ? '' : 'display:none' }}">
                <div>
                    <label class="form-label">Team user <span class="text-danger">*</span></label>
                    <select name="user_id" class="form-select">
                        <option value="">— pick a user —</option>
                        @foreach (($users ?? []) as $u)
                            <option value="{{ $u->id }}" @selected(($agent->user_id ?? null) == $u->id)>{{ $u->name }} ({{ $u->email }})</option>
                        @endforeach
                    </select>
                    <small class="text-slate-500 text-xs">The person who logs in and takes over chats.</small>
                </div>
                <div>
                    <label class="form-label">Max concurrent chats</label>
                    <input type="number" name="max_active_chats" min="1" max="50" class="form-control"
                           value="{{ old('max_active_chats', $agent->max_active_chats ?? 3) }}">
                    <small class="text-slate-500 text-xs">Capacity before new chats queue.</small>
                </div>
            </div>

            {{-- AI agent fields --}}
            <div class="js-ai" style="{{ $atype==='human' ? 'display:none' : '' }}">
                <div class="mb-3">
                    <label class="form-label">Voice</label>
                    @php
                        // One choice: the project's response voice, one of its own
                        // cloned voices ("voice:<id>"), or a Google voice
                        // ("google:<name>"). Not named *_id — DecodeHashids would
                        // coerce "google:Kore" to an int.
                        $agentVoice    = ($agent->voice_id ?? null) ? $voices->firstWhere('id', $agent->voice_id) : null;
                        $savedChoice   = $agentVoice
                            ? ($agentVoice->isGoogle() ? 'google:' . $agentVoice->external_id : 'voice:' . $agentVoice->id)
                            : '';
                        $currentChoice = old('voice_choice', $savedChoice);
                        $ownVoices     = $voices->reject(fn ($v) => $v->isGoogle());
                        $googleVoices  = (array) config('services.voice.google_voices', []);
                        $notReady      = $ownVoices->filter(fn ($v) => $v->status !== 'ready');
                    @endphp
                    <select name="voice_choice" class="form-select">
                        <option value="">— project's response voice (Voices page) —</option>
                        @if ($ownVoices->isNotEmpty())
                            <optgroup label="Your voices (cloned)">
                                @foreach ($ownVoices as $v)
                                    @php
                                        $usable = $v->isUsable();
                                        $isCurrent = $currentChoice === 'voice:' . $v->id;
                                    @endphp
                                    {{-- Never disable the option that's already saved: a disabled
                                         <option> isn't submitted, so doing so would silently clear
                                         the agent's voice the next time anyone saved this form. --}}
                                    <option value="voice:{{ $v->id }}"
                                            @selected($isCurrent)
                                            @disabled(!$usable && !$isCurrent)>
                                        {{ $v->name }} ({{ $v->language }})@unless ($usable) — {{ $v->status }}, not usable yet @endunless
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        @foreach (['female' => 'Google voices — female', 'male' => 'Google voices — male'] as $gender => $groupLabel)
                            <optgroup label="{{ $groupLabel }}">
                                @foreach ($googleVoices as $name => $gv)
                                    @continue($gv['gender'] !== $gender)
                                    <option value="google:{{ $name }}" @selected($currentChoice === 'google:' . $name)>{{ $name }} — {{ $gv['style'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <small class="text-slate-500 text-xs mt-1 block">
                        Hear the Google voices on the
                        <a href="{{ route('voices.index', ['client' => $client->slug]) }}?project_id={{ hashid($projectId) }}" class="text-primary">Voices</a> page.
                    </small>
                    @if ($notReady->isNotEmpty())
                        <small class="text-xs mt-1 block" style="color:#b45309;">
                            <i data-lucide="alert-triangle" class="w-3 h-3 inline -mt-0.5"></i>
                            {{ $notReady->count() }} voice(s) can't be used yet — a clone stays
                            <code>training</code> when its audio upload didn't land.
                            Re-upload it on the
                            <a href="{{ route('voices.index', ['client' => $client->slug]) }}?project_id={{ hashid($projectId) }}" class="text-primary">Voices</a>
                            page.
                        </small>
                    @endif
                </div>
                <div class="mb-3">
                    <label class="form-label">Persona / system prompt</label>
                    <textarea name="persona" rows="5" maxlength="4000" class="form-control"
                              placeholder="You handle billing questions. Be empathetic and concise.">{{ old('persona', $agent->persona ?? '') }}</textarea>
                    <small class="text-slate-500 text-xs">Injected into the LLM as a system message so the bot stays in this character.</small>
                </div>

                {{-- How the agent talks on phone calls --}}
                @php
                    $call = $agent ? $agent->callSettings() : ['language' => 'en', 'auto_detect' => true, 'greeting' => null, 'vocabulary' => null];
                    $callLang = old('call_language', $call['language']);
                @endphp
                <div class="mb-3 p-3 rounded-lg" style="border:1px solid rgba(100,116,139,.25)">
                    <div class="form-label mb-2" style="font-weight:600">On calls</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label">Speaks</label>
                            <select name="call_language" class="form-select">
                                @foreach (\App\Models\BotAgent::CALL_LANGUAGE_GROUPS as $group => $codes)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($codes as $code)
                                            <option value="{{ $code }}" @selected($callLang === $code)>{{ \App\Models\BotAgent::CALL_LANGUAGES[$code] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="flex items-start gap-2 text-sm cursor-pointer">
                                <input type="hidden" name="call_auto_detect" value="0">
                                <input type="checkbox" name="call_auto_detect" value="1" class="mt-0.5"
                                       @checked(old('call_auto_detect', $call['auto_detect']))>
                                <span>Auto-detect the caller's language and reply in it</span>
                            </label>
                        </div>
                    </div>
                    <small class="text-slate-500 text-xs block mt-1">
                        With auto-detect on, each caller is answered in the language they speak — an Urdu caller in
                        Urdu (in the script chosen above when that's Urdu or Roman Urdu); the language above is used
                        when it's unclear. Every language here needs a Google voice except English, Spanish, French,
                        German, Italian, Portuguese, Polish, Turkish, Russian, Dutch, Czech, Arabic, Chinese, Japanese,
                        Hungarian, Korean and Hindi, which cloned voices can also speak.
                    </small>
                    <label class="form-label mt-3">Greeting <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="text" name="call_greeting" maxlength="300" class="form-control"
                           value="{{ old('call_greeting', $call['greeting']) }}"
                           placeholder="Leave empty for a greeting in the agent's language">
                    <label class="form-label mt-3">Words to listen for <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="text" name="call_vocabulary" maxlength="500" class="form-control"
                           value="{{ old('call_vocabulary', $call['vocabulary']) }}"
                           placeholder="e.g. Lahore, Karachi, Umer Foods, Super Biscuit">
                    <small class="text-slate-500 text-xs">Names, products and places callers mention — helps the AI hear them right.</small>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Skills</label>
                @if ($skills->isEmpty())
                    <div class="text-xs text-slate-500">
                        No skills yet. <a href="{{ route('skills.index', ['client' => $client->slug]) }}?project_id={{ hashid($projectId) }}" class="text-primary">Create one first</a>.
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

            {{-- Channels + permissions. Owner only: these decide who answers
                 which customers and what they may do, so they are not
                 something a team member should be able to widen for
                 themselves. BotAgentWebController::metadataFor() refuses to
                 write them for anyone else regardless of what is posted. --}}
            @if (!empty($isOwner))
                @php
                    $agentType    = $agent?->type ?? 'ai';
                    $agentChannels = $agent ? $agent->channels() : array_keys(\App\Models\BotAgent::CHANNELS);
                    $agentCaps    = $agent ? $agent->capabilities() : [];
                @endphp

                <div class="tva-ag-perm">
                    <div class="tva-ag-perm__h">Channels this agent handles</div>
                    <div class="text-xs text-slate-500 mb-2">
                        Conversations on a channel are routed only to agents that handle it.
                        Tick none to handle every channel.
                    </div>
                    <div class="tva-ag-perm__grid">
                        @foreach (\App\Models\BotAgent::CHANNELS as $key => $label)
                            <label class="tva-ag-perm__opt">
                                <input type="checkbox" name="channels[]" value="{{ $key }}"
                                       @checked(in_array($key, $agentChannels, true))>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="tva-ag-perm">
                    <div class="tva-ag-perm__h">What this agent may do</div>
                    <div class="text-xs text-slate-500 mb-2">
                        Enforced on the server, not just hidden — an agent without a permission
                        gets a clear refusal rather than a broken button.
                    </div>
                    <div class="tva-ag-perm__grid">
                        @foreach (\App\Models\BotAgent::CAPABILITIES as $key => [$label, $appliesTo])
                            {{-- Human-only permissions (transfer, statuses) are
                                 meaningless on an AI persona; showing them would
                                 invite someone to tick a box that does nothing. --}}
                            {{-- js-human is the class the type selector at the
                                 top of this form already toggles, so these
                                 show and hide with it for free. --}}
                            <label class="tva-ag-perm__opt {{ $appliesTo === 'human' ? 'js-human' : '' }}"
                                   @if($appliesTo === 'human' && $agentType !== 'human') style="display:none" @endif>
                                <input type="checkbox" name="capabilities[{{ $key }}]" value="1"
                                       @checked($agentCaps[$key] ?? true)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" class="w-3 h-3 mr-1 inline"></i> Save
            </button>
        </div>
    </form>
</div>
