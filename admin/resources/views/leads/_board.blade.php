{{--
    Leads kanban.

    One column per Lead::STATUSES entry, so the pipeline's shape lives in the
    model and not in this markup. Cards are draggable between columns; the drop
    is applied optimistically and rolled back if the server refuses, because a
    board that waits for a round-trip before moving the card feels broken.

    Expects: $board (status => ['total' => int, 'leads' => Collection]),
             $client, $projectId, $columnLimit
--}}
<style>
    .tva-kb {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(272px, 1fr);
        gap: 14px;
        overflow-x: auto;
        padding-bottom: 6px;
        align-items: start;
    }
    .tva-kb__col {
        background: var(--tva-surface-2);
        border: 1px solid var(--tva-border);
        border-radius: 14px;
        display: flex; flex-direction: column;
        min-height: 140px;
        /* The column scrolls, not the page: with five columns of different
           lengths a page-level scroll means the short ones run out and you
           lose the header you are dragging toward. */
        max-height: calc(100vh - 340px);
    }
    .tva-kb__head {
        display: flex; align-items: center; gap: 8px;
        padding: 12px 14px;
        border-bottom: 1px solid var(--tva-border);
        flex-shrink: 0;
    }
    .tva-kb__dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .tva-kb__name {
        font-size: 12px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; color: var(--tva-text-2);
    }
    .tva-kb__count {
        margin-left: auto; font-size: 11px; font-weight: 700;
        color: var(--tva-text-3);
        background: var(--tva-surface-3);
        border-radius: 999px; padding: 2px 8px;
    }
    .tva-kb__body {
        padding: 10px; display: flex; flex-direction: column; gap: 9px;
        overflow-y: auto;
        /* Without this the flex item refuses to shrink below its content and
           the column's own scrollbar never appears. */
        min-height: 0; flex: 1 1 auto;
    }
    .tva-kb__col.is-over .tva-kb__body {
        background: var(--tva-info-bg);
        border-radius: 10px;
    }

    .tva-kb-card {
        display: block; text-decoration: none; color: inherit;
        background: var(--tva-surface);
        border: 1px solid var(--tva-border);
        border-radius: 11px;
        padding: 11px 12px;
        box-shadow: var(--tva-shadow);
        cursor: grab;
        transition: box-shadow .14s, border-color .14s, transform .14s;
    }
    .tva-kb-card:hover { border-color: var(--tva-info-line); box-shadow: var(--tva-shadow-lg); }
    .tva-kb-card:active { cursor: grabbing; }
    .tva-kb-card.is-dragging { opacity: .45; }
    /* While a move is in flight the card cannot be picked up again — two
       overlapping drags of one card would race each other's rollback. */
    .tva-kb-card.is-saving { opacity: .6; pointer-events: none; }

    .tva-kb-card__top { display: flex; align-items: center; gap: 9px; }
    .tva-kb-card__av {
        width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
        background: var(--tva-gradient); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 10.5px;
    }
    .tva-kb-card__name { font-weight: 600; font-size: 13px; line-height: 1.25; }
    .tva-kb-card__sub  { font-size: 11px; color: var(--tva-text-3); }
    .tva-kb-card__name, .tva-kb-card__sub {
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .tva-kb-card__id {
        margin-left: auto; font-size: 10.5px; font-family: ui-monospace, monospace;
        color: var(--tva-text-3); flex-shrink: 0;
    }
    .tva-kb-card__meta {
        margin-top: 9px; display: flex; flex-direction: column; gap: 3px;
        font-size: 11.5px; color: var(--tva-text-2);
    }
    .tva-kb-card__meta div { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .tva-kb-card__foot {
        margin-top: 10px; display: flex; align-items: center; gap: 8px;
        font-size: 11px; color: var(--tva-text-3);
    }
    .tva-kb-card__foot .tva-confidence-bar { flex: 1 1 auto; }

    .tva-kb__empty {
        text-align: center; padding: 22px 10px;
        font-size: 12px; color: var(--tva-text-3);
    }
    .tva-kb__more {
        text-align: center; padding: 8px; font-size: 11px; color: var(--tva-text-3);
    }
    .tva-kb__err {
        margin-bottom: 12px; display: none;
        border-radius: 10px; padding: 10px 13px; font-size: 13px;
        background: var(--tva-danger-bg); color: var(--tva-danger);
        border: 1px solid var(--tva-danger-line);
    }

    @media (max-width: 760px) {
        /* Columns stack rather than scroll sideways — a horizontal board on a
           phone means dragging against the same axis the page pans on. */
        .tva-kb { grid-auto-flow: row; grid-auto-columns: auto; }
        .tva-kb__col { max-height: none; }
    }
</style>

@php
    // Dot colours mirror the .tva-status pills already defined on this page,
    // so a card's column and its status pill never disagree.
    $dots = [
        'new'          => '#3b82f6',
        'contacted'    => '#f59e0b',
        'qualified'    => '#6366f1',
        'converted'    => '#10b981',
        'disqualified' => '#94a3b8',
    ];
@endphp

<div class="tva-kb__err" id="tva-kb-err"></div>

<div class="tva-kb" id="tva-kb">
    @foreach (\App\Models\Lead::STATUSES as $st)
        @php $col = $board[$st] ?? ['total' => 0, 'leads' => collect()]; @endphp
        <div class="tva-kb__col" data-status="{{ $st }}">
            <div class="tva-kb__head">
                <span class="tva-kb__dot" style="background: {{ $dots[$st] ?? '#94a3b8' }};"></span>
                <span class="tva-kb__name">{{ $st }}</span>
                <span class="tva-kb__count" data-count>{{ number_format($col['total']) }}</span>
            </div>
            <div class="tva-kb__body" data-drop>
                @forelse ($col['leads'] as $lead)
                    @php
                        $f    = $lead->fields ?? [];
                        $name = $f['name'] ?? 'Anonymous';
                        $conf = (int) round(($lead->confidence ?? 0) * 100);
                        $init = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'A', 0, 2));
                    @endphp
                    <a class="tva-kb-card"
                       draggable="true"
                       data-status="{{ $st }}"
                       data-url="{{ route('leads.status', ['client' => $client->slug, 'id' => $lead->id]) }}"
                       href="{{ route('leads.show', ['client' => $client->slug, 'id' => $lead->id]) }}?project_id={{ hashid($projectId) }}">
                        <div class="tva-kb-card__top">
                            <span class="tva-kb-card__av">{{ $init }}</span>
                            <div style="min-width:0;">
                                <div class="tva-kb-card__name">{{ $name }}</div>
                                @if (!empty($f['company']))
                                    <div class="tva-kb-card__sub">{{ $f['company'] }}</div>
                                @endif
                            </div>
                            <span class="tva-kb-card__id">#{{ $lead->id }}</span>
                        </div>

                        @if (!empty($f['email']) || !empty($f['phone']) || !empty($f['intent']))
                            <div class="tva-kb-card__meta">
                                @if (!empty($f['email']))
                                    <div><i data-lucide="mail" class="w-3 h-3 inline -mt-0.5"></i> {{ $f['email'] }}</div>
                                @endif
                                @if (!empty($f['phone']))
                                    <div><i data-lucide="phone" class="w-3 h-3 inline -mt-0.5"></i> {{ $f['phone'] }}</div>
                                @endif
                                @if (!empty($f['intent']))
                                    <div title="{{ $f['intent'] }}">
                                        <i data-lucide="target" class="w-3 h-3 inline -mt-0.5"></i> {{ $f['intent'] }}
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="tva-kb-card__foot">
                            <span class="tva-confidence-bar"><span style="width: {{ $conf }}%;"></span></span>
                            <span style="font-weight:600;">{{ $conf }}%</span>
                        </div>
                    </a>
                @empty
                    <div class="tva-kb__empty">Nothing here</div>
                @endforelse

                @if ($col['total'] > $col['leads']->count())
                    <div class="tva-kb__more">
                        Showing {{ $col['leads']->count() }} of {{ number_format($col['total']) }} — switch to the table for the rest
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>

<script>
(function () {
    var board = document.getElementById('tva-kb');
    var errBox = document.getElementById('tva-kb-err');
    if (!board) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.getAttribute('content') : '';
    var projectId = @json(hashid($projectId));
    var dragged = null;

    function fail(msg) {
        errBox.textContent = msg;
        errBox.style.display = 'block';
    }

    // The header count is the column's TRUE total, which can exceed the number
    // of cards rendered. Adjusting it by the delta keeps it honest either way.
    function bump(col, delta) {
        var el = col.querySelector('[data-count]');
        if (!el) return;
        var n = parseInt(el.textContent.replace(/[^0-9]/g, ''), 10);
        if (!isNaN(n)) el.textContent = Math.max(0, n + delta).toLocaleString();
    }

    function emptyState(col) {
        var body = col.querySelector('[data-drop]');
        var has  = body.querySelector('.tva-kb-card');
        var msg  = body.querySelector('.tva-kb__empty');
        if (has && msg) msg.remove();
        if (!has && !msg) {
            var d = document.createElement('div');
            d.className = 'tva-kb__empty';
            d.textContent = 'Nothing here';
            body.appendChild(d);
        }
    }

    board.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.tva-kb-card');
        if (!card) return;
        dragged = card;
        card.classList.add('is-dragging');
        // Required for Firefox to start a drag at all.
        try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
        e.dataTransfer.effectAllowed = 'move';
    });

    board.addEventListener('dragend', function () {
        if (dragged) dragged.classList.remove('is-dragging');
        dragged = null;
        board.querySelectorAll('.tva-kb__col').forEach(function (c) { c.classList.remove('is-over'); });
    });

    board.querySelectorAll('.tva-kb__col').forEach(function (col) {
        col.addEventListener('dragover', function (e) {
            if (!dragged) return;
            e.preventDefault();               // without this the drop never fires
            e.dataTransfer.dropEffect = 'move';
            col.classList.add('is-over');
        });
        col.addEventListener('dragleave', function (e) {
            // Moving over a CHILD fires dragleave on the column, so ignore any
            // leave whose destination is still inside it — otherwise the
            // highlight flickers off on every card the cursor crosses.
            if (col.contains(e.relatedTarget)) return;
            col.classList.remove('is-over');
        });

        col.addEventListener('drop', function (e) {
            e.preventDefault();
            col.classList.remove('is-over');
            if (!dragged) return;

            var card = dragged;
            var from = card.getAttribute('data-status');
            var to   = col.getAttribute('data-status');
            if (from === to) return;

            var fromCol = board.querySelector('.tva-kb__col[data-status="' + from + '"]');
            var body    = col.querySelector('[data-drop]');
            var anchor  = body.querySelector('.tva-kb__more');

            // Optimistic: move it now, put it back if the server says no.
            var prevNext = card.nextSibling;
            var prevParent = card.parentNode;
            body.insertBefore(card, anchor || null);
            card.setAttribute('data-status', to);
            card.classList.add('is-saving');
            bump(fromCol, -1); bump(col, 1);
            emptyState(fromCol); emptyState(col);
            errBox.style.display = 'none';

            fetch(card.getAttribute('data-url'), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ project_id: projectId, status: to })
            })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function () {
                card.classList.remove('is-saving');
            })
            .catch(function () {
                prevParent.insertBefore(card, prevNext);
                card.setAttribute('data-status', from);
                card.classList.remove('is-saving');
                bump(fromCol, 1); bump(col, -1);
                emptyState(fromCol); emptyState(col);
                fail('Could not move that lead. It has been put back — check your connection and try again.');
            });
        });
    });

    // A card is an <a>; a drag that ends on the card itself would otherwise
    // follow the link on mouse-up and navigate away mid-move.
    board.addEventListener('click', function (e) {
        var card = e.target.closest('.tva-kb-card');
        if (card && card.classList.contains('is-dragging')) e.preventDefault();
    });
})();
</script>
