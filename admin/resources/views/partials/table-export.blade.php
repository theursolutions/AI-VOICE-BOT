{{--
    Export the page's table as CSV.

    Reads the RENDERED table rather than re-querying on the server. That is a
    deliberate trade:

      + one implementation covers every table in the app instead of a bespoke
        column map per controller, and the file always matches what the page
        is showing — same filters, same search, same columns, same formatting
      - it can only export what the page can reach

    …so when the table is paginated, pass the paginator and the button walks
    the remaining pages itself before building the file. Without it the export
    would quietly contain only the page you happen to be looking at, which is
    the failure mode people notice a week later in a spreadsheet.

    Usage:
        @include('partials.table-export', [
            'table'     => '#leads-table',     // any CSS selector
            'filename'  => 'leads',            // no extension
            'paginator' => $leads ?? null,     // optional
        ])

    Mark a column `<th data-export-skip>` to leave it out — for the action
    columns, which hold buttons and export as a run of empty cells.
--}}
@php
    $exportId    = 'tva-export-' . substr(md5($table . $filename), 0, 8);
    $exportPages = isset($paginator) && $paginator ? (int) $paginator->lastPage() : 1;
@endphp

<button type="button" class="tva-export" id="{{ $exportId }}"
        data-table="{{ $table }}"
        data-filename="{{ $filename }}"
        data-pages="{{ $exportPages }}"
        title="Download this table as a CSV file">
    <i data-lucide="download" class="w-4 h-4"></i>
    <span data-export-label>Export</span>
</button>

@once
<style>
    .tva-export {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 11px; font-size: 12px; font-weight: 600;
        border: 1px solid var(--tva-border); border-radius: 9px;
        background: var(--tva-surface); color: var(--tva-text-2);
        cursor: pointer; white-space: nowrap;
        transition: background .14s, color .14s, border-color .14s;
    }
    .tva-export:hover:not(:disabled) {
        background: var(--tva-hover); color: var(--tva-text);
        border-color: var(--tva-border);
    }
    .tva-export:disabled { opacity: .6; cursor: progress; }
    .tva-export.is-err { color: var(--tva-danger); border-color: var(--tva-danger-line); }
    /* For pages with no toolbar to hang the button off — it sits in its own
       right-aligned bar directly above the table it belongs to, so on a page
       with several tables it is never ambiguous which one it exports. */
    .tva-export-bar {
        display: flex; justify-content: flex-end;
        padding: 10px 14px 0;
    }
</style>

<script>
(function () {
    /* Walking pagination means one request per page. This caps a runaway —
       a filter matching 40k rows should not fire 1,600 requests at the app.
       When the cap bites the file still downloads, and the button says so. */
    var MAX_PAGES = 200;

    function cells(row) { return Array.prototype.slice.call(row.querySelectorAll('th, td')); }

    /* Which columns to keep, decided from the header once and applied to every
       row so a skipped column cannot shift the rest by one. */
    function keptIndexes(table) {
        var head = table.querySelector('thead tr');
        if (!head) return null;                       // no header: keep everything
        var keep = [];
        cells(head).forEach(function (th, i) {
            if (th.hasAttribute('data-export-skip')) return;
            keep.push(i);
        });
        return keep;
    }

    function text(cell) {
        /* innerText, not textContent: it honours display:none, so the mobile
           `data-label` duplicates and any collapsed helper text stay out of
           the file. Falls back for detached nodes, where innerText is ''. */
        var t = cell.innerText;
        if (!t) t = cell.textContent || '';
        return t.replace(/\s+/g, ' ').trim();
    }

    function rowValues(row, keep) {
        var cs = cells(row);
        var out = [];
        (keep || cs.map(function (_, i) { return i; })).forEach(function (i) {
            if (cs[i]) out.push(text(cs[i]));
        });
        return out;
    }

    function csvCell(v) {
        /* Quote when the value contains a delimiter, a quote or a newline;
           double any quote inside. A leading =, +, - or @ is prefixed with a
           quote so a spreadsheet treats it as text — otherwise a cell like
           "=1+1" is executed as a formula on open. */
        if (/^[=+\-@]/.test(v)) v = "'" + v;
        return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
    }

    function toCsv(rows) {
        return rows.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n');
    }

    function download(name, csv) {
        // The BOM is what makes Excel read the file as UTF-8 rather than the
        // local codepage; without it every non-ASCII name arrives mangled.
        var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function stamp() {
        var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    /* Rows from another page of the same list, fetched as HTML and read with
       the same rules as the live table. */
    function fetchPage(url, selector, keep) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var t = doc.querySelector(selector);
                if (!t) return [];
                return Array.prototype.slice.call(t.querySelectorAll('tbody tr'))
                    .filter(function (tr) { return !tr.hasAttribute('data-export-skip'); })
                    .map(function (tr) { return rowValues(tr, keep); });
            });
    }

    function run(btn) {
        var table = document.querySelector(btn.getAttribute('data-table'));
        var label = btn.querySelector('[data-export-label]');
        if (!table) return;

        var keep = keptIndexes(table);
        var rows = [];

        var head = table.querySelector('thead tr');
        if (head) rows.push(rowValues(head, keep));

        var body = Array.prototype.slice.call(table.querySelectorAll('tbody tr'))
            .filter(function (tr) { return !tr.hasAttribute('data-export-skip'); });

        // An empty table renders one full-width "nothing here" row. Exporting
        // it would produce a file whose single line is a sentence.
        if (body.length === 1 && body[0].querySelectorAll('td').length === 1) body = [];
        body.forEach(function (tr) { rows.push(rowValues(tr, keep)); });

        var pages = parseInt(btn.getAttribute('data-pages'), 10) || 1;
        var capped = false;
        if (pages > MAX_PAGES) { pages = MAX_PAGES; capped = true; }

        var name = btn.getAttribute('data-filename') + '-' + stamp() + '.csv';

        if (pages < 2) { download(name, toCsv(rows)); return; }

        // Which page is on screen already — do not fetch it twice.
        var here = parseInt(new URL(window.location.href).searchParams.get('page'), 10) || 1;
        var todo = [];
        for (var p = 1; p <= pages; p++) if (p !== here) todo.push(p);

        btn.disabled = true;
        btn.classList.remove('is-err');
        var done = 0;
        label.textContent = 'Exporting… 1/' + pages;

        // Sequential on purpose. These are full page renders; firing 200 at
        // once to build one spreadsheet is not worth what it does to the box.
        var chain = Promise.resolve();
        var collected = {};
        todo.forEach(function (p) {
            chain = chain.then(function () {
                var u = new URL(window.location.href);
                u.searchParams.set('page', p);
                return fetchPage(u.toString(), btn.getAttribute('data-table'), keep)
                    .then(function (rs) {
                        collected[p] = rs;
                        done++;
                        label.textContent = 'Exporting… ' + (done + 1) + '/' + pages;
                    });
            });
        });

        chain.then(function () {
            // Rebuilt in page order: the on-screen page keeps its position
            // instead of the file starting wherever the user happened to be.
            var out = rows.slice(0, head ? 1 : 0);
            var live = rows.slice(head ? 1 : 0);
            for (var p = 1; p <= pages; p++) {
                out = out.concat(p === here ? live : (collected[p] || []));
            }
            download(name, toCsv(out));
            label.textContent = capped ? 'Exported first ' + MAX_PAGES + ' pages' : 'Export';
            btn.disabled = false;
            if (capped) setTimeout(function () { label.textContent = 'Export'; }, 6000);
        }).catch(function () {
            // Give them the rows we did get rather than nothing at all.
            download(name, toCsv(rows));
            label.textContent = 'Partial export';
            btn.classList.add('is-err');
            btn.disabled = false;
            setTimeout(function () { label.textContent = 'Export'; btn.classList.remove('is-err'); }, 6000);
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.tva-export');
        if (btn) run(btn);
    });
})();
</script>
@endonce
