{{-- Self-contained "Sweet" alert/confirm system (no CDN, themed to the
     app's --tva-primary/--tva-gradient). Replaces native alert()/confirm():
       • window.alert(msg)      → a themed popup (transparent override)
       • Sweet.confirm({...})   → Promise<bool> for confirmations
       • Sweet.fire({...})      → general popup (SweetAlert2-ish API)
       • Sweet.toast(msg, icon) → top-right auto-dismiss toast
       • <form data-confirm="…"> / <a data-confirm="…"> → auto-wired
     Include once per layout (it guards against double-init). --}}
@once
<style>
    .tva-swal__backdrop{
        position:fixed; inset:0; z-index:2147483000;
        display:flex; align-items:center; justify-content:center; padding:16px;
        background:rgba(15,23,42,.55); backdrop-filter:blur(4px);
        opacity:0; transition:opacity .18s ease;
    }
    .tva-swal__backdrop.is-open{ opacity:1; }
    .tva-swal{
        width:min(92vw,420px); background:#fff; color:#0f172a;
        border-radius:20px; padding:30px 28px 22px; text-align:center;
        box-shadow:0 25px 60px -15px rgba(0,0,0,.45);
        transform:scale(.9) translateY(8px); opacity:0;
        transition:transform .22s cubic-bezier(.16,1,.3,1), opacity .22s ease;
    }
    .tva-swal__backdrop.is-open .tva-swal{ transform:scale(1) translateY(0); opacity:1; }
    html.dark .tva-swal{ background:#1e293b; color:#f1f5f9; }

    .tva-swal__icon{
        width:80px; height:80px; border-radius:50%; margin:0 auto 18px;
        display:flex; align-items:center; justify-content:center;
        animation:tvaSwalPop .3s ease both;
    }
    @keyframes tvaSwalPop{ 0%{transform:scale(.4);opacity:0} 60%{transform:scale(1.08)} 100%{transform:scale(1);opacity:1} }
    .tva-swal__icon svg{ width:42px; height:42px; }
    .tva-swal__icon--success{ background:rgba(34,197,94,.14); color:#16a34a; }
    .tva-swal__icon--error{   background:rgba(239,68,68,.14); color:#dc2626; }
    .tva-swal__icon--warning{ background:rgba(245,158,11,.16); color:#d97706; }
    .tva-swal__icon--question{background:rgba(99,102,241,.14); color:var(--tva-primary,#6366f1); }
    .tva-swal__icon--info{    background:rgba(59,130,246,.14); color:#2563eb; }

    .tva-swal__title{ font-size:21px; font-weight:700; line-height:1.25; margin:0 0 6px; }
    .tva-swal__text{ font-size:14.5px; line-height:1.55; color:#64748b; margin:0 0 22px; word-break:break-word; }
    html.dark .tva-swal__text{ color:#94a3b8; }

    .tva-swal__actions{ display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
    .tva-swal__btn{
        appearance:none; border:0; cursor:pointer; font-size:14px; font-weight:600;
        padding:11px 22px; border-radius:11px; transition:transform .1s ease, filter .15s ease, background .15s ease;
        min-width:108px;
    }
    .tva-swal__btn:active{ transform:scale(.96); }
    .tva-swal__btn--confirm{ color:#fff; background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); box-shadow:0 8px 18px -6px rgba(99,102,241,.5); }
    .tva-swal__btn--confirm:hover{ filter:brightness(1.06); }
    .tva-swal__btn--danger{ color:#fff; background:linear-gradient(135deg,#ef4444,#dc2626); box-shadow:0 8px 18px -6px rgba(239,68,68,.5); }
    .tva-swal__btn--cancel{ background:#f1f5f9; color:#475569; }
    .tva-swal__btn--cancel:hover{ background:#e2e8f0; }
    html.dark .tva-swal__btn--cancel{ background:#334155; color:#e2e8f0; }
    html.dark .tva-swal__btn--cancel:hover{ background:#3f4d63; }

    /* Toasts */
    .tva-swal__toasts{ position:fixed; top:18px; right:18px; z-index:2147483001; display:flex; flex-direction:column; gap:10px; max-width:92vw; }
    .tva-toast{
        display:flex; align-items:flex-start; gap:10px; min-width:260px; max-width:380px;
        background:#fff; color:#0f172a; border-radius:12px; padding:13px 15px;
        box-shadow:0 12px 30px -10px rgba(0,0,0,.35); border-left:4px solid var(--tva-primary,#6366f1);
        transform:translateX(120%); opacity:0; transition:transform .3s cubic-bezier(.16,1,.3,1), opacity .3s ease;
    }
    .tva-toast.is-open{ transform:translateX(0); opacity:1; }
    html.dark .tva-toast{ background:#1e293b; color:#f1f5f9; }
    .tva-toast--success{ border-left-color:#16a34a; } .tva-toast--success .tva-toast__ic{ color:#16a34a; }
    .tva-toast--error{ border-left-color:#dc2626; }   .tva-toast--error .tva-toast__ic{ color:#dc2626; }
    .tva-toast--warning{ border-left-color:#d97706; } .tva-toast--warning .tva-toast__ic{ color:#d97706; }
    .tva-toast--info{ border-left-color:#2563eb; }    .tva-toast--info .tva-toast__ic{ color:#2563eb; }
    .tva-toast__ic{ flex-shrink:0; margin-top:1px; color:var(--tva-primary,#6366f1); }
    .tva-toast__msg{ font-size:13.5px; line-height:1.45; }
</style>
<script>
(function(){
    if (window.Sweet && window.Sweet.__init) return;

    var ICONS = {
        success:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        error:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        warning:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
        question:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="10"/></svg>',
        info:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16v-4m0-4h.01"/><circle cx="12" cy="12" r="10"/></svg>'
    };
    function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function fire(opts){
        opts = opts || {};
        var icon = ICONS[opts.icon] ? opts.icon : 'info';
        var showCancel = !!opts.showCancel;
        return new Promise(function(resolve){
            var bd = document.createElement('div');
            bd.className = 'tva-swal__backdrop';
            bd.innerHTML =
                '<div class="tva-swal" role="dialog" aria-modal="true">' +
                    '<div class="tva-swal__icon tva-swal__icon--'+icon+'">'+ICONS[icon]+'</div>' +
                    (opts.title ? '<h2 class="tva-swal__title">'+esc(opts.title)+'</h2>' : '') +
                    (opts.text  ? '<p class="tva-swal__text">'+esc(opts.text)+'</p>' : '') +
                    '<div class="tva-swal__actions">' +
                        (showCancel ? '<button type="button" class="tva-swal__btn tva-swal__btn--cancel" data-act="cancel">'+esc(opts.cancelText||'Cancel')+'</button>' : '') +
                        '<button type="button" class="tva-swal__btn '+(opts.danger?'tva-swal__btn--danger':'tva-swal__btn--confirm')+'" data-act="confirm">'+esc(opts.confirmText||'OK')+'</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(bd);
            requestAnimationFrame(function(){ bd.classList.add('is-open'); });

            var done = false;
            function close(result){
                if (done) return; done = true;
                bd.classList.remove('is-open');
                setTimeout(function(){ bd.remove(); document.removeEventListener('keydown', onKey); }, 200);
                resolve(result);
            }
            function onKey(e){
                if (e.key === 'Escape') close(false);
                else if (e.key === 'Enter') { e.preventDefault(); close(true); }
            }
            bd.addEventListener('click', function(e){
                var act = e.target.getAttribute && e.target.getAttribute('data-act');
                if (act === 'confirm') close(true);
                else if (act === 'cancel') close(false);
                else if (e.target === bd) close(false);  // backdrop click
            });
            document.addEventListener('keydown', onKey);
            var btn = bd.querySelector('[data-act="confirm"]'); if (btn) btn.focus();
        });
    }

    function confirmDialog(opts){
        opts = opts || {};
        return fire(Object.assign({
            icon: 'warning',
            title: opts.title || 'Are you sure?',
            confirmText: 'Yes, continue',
            cancelText: 'Cancel',
            showCancel: true,
            danger: true
        }, opts));
    }

    var toastWrap;
    function toast(msg, icon){
        icon = ICONS[icon] ? icon : 'info';
        if (!toastWrap){ toastWrap = document.createElement('div'); toastWrap.className = 'tva-swal__toasts'; document.body.appendChild(toastWrap); }
        var t = document.createElement('div');
        t.className = 'tva-toast tva-toast--'+icon;
        t.innerHTML = '<span class="tva-toast__ic">'+ICONS[icon]+'</span><div class="tva-toast__msg">'+esc(msg)+'</div>';
        t.querySelector('svg').style.cssText = 'width:18px;height:18px;';
        toastWrap.appendChild(t);
        requestAnimationFrame(function(){ t.classList.add('is-open'); });
        setTimeout(function(){ t.classList.remove('is-open'); setTimeout(function(){ t.remove(); }, 320); }, 3800);
    }

    window.Sweet = { __init:true, fire:fire, confirm:confirmDialog, toast:toast };
    // Light SweetAlert2 compatibility shim (Swal.fire(...))
    window.Swal = window.Swal || { fire:function(o){ o=o||{}; return fire({icon:o.icon,title:o.title,text:o.text||o.html,confirmText:o.confirmButtonText,cancelText:o.cancelButtonText,showCancel:!!o.showCancelButton}).then(function(ok){ return {isConfirmed:ok,isDismissed:!ok}; }); } };

    // Transparent replacement for the native blocking alert().
    window.__nativeAlert = window.alert.bind(window);
    window.alert = function(msg){
        var s = msg == null ? '' : String(msg);
        var icon = /fail|error|❌|invalid|cannot|denied/i.test(s) ? 'error'
                 : /✅|success|saved|done|uploaded|created/i.test(s) ? 'success' : 'info';
        fire({ icon:icon, text:s, confirmText:'OK', showCancel:false });
    };

    // Auto-wire data-confirm on forms + links (replaces onsubmit="return confirm()").
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    ready(function(){
        document.addEventListener('submit', function(e){
            var f = e.target;
            if (!f || f.nodeName !== 'FORM' || !f.hasAttribute('data-confirm')) return;
            if (f.getAttribute('data-confirmed') === '1') return;   // already accepted
            e.preventDefault(); e.stopPropagation();
            confirmDialog({
                text: f.getAttribute('data-confirm'),
                icon: f.getAttribute('data-confirm-icon') || 'warning',
                confirmText: f.getAttribute('data-confirm-btn') || 'Yes, continue'
            }).then(function(ok){
                if (!ok) return;
                f.setAttribute('data-confirmed', '1');
                if (typeof f.requestSubmit === 'function') f.requestSubmit(); else f.submit();
            });
        }, true);

        document.addEventListener('click', function(e){
            var el = e.target.closest ? e.target.closest('a[data-confirm]') : null;
            if (!el) return;
            e.preventDefault();
            confirmDialog({ text: el.getAttribute('data-confirm') }).then(function(ok){
                if (ok && el.href) window.location.href = el.href;
            });
        }, true);
    });
})();
</script>
@endonce
