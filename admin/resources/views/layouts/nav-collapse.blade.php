{{-- Collapsible side menu: icon-only when collapsed, widens on hover to
     reveal labels. Toggle (persisted) is injected into the top bar. Only
     affects layout when body.tva-nav-collapsed is present. --}}
<style>
    .side-nav { transition: width .18s ease; }
    body.tva-nav-collapsed .side-nav { width:74px !important; min-width:74px !important; overflow:hidden; }
    body.tva-nav-collapsed .side-nav .side-menu__title,
    body.tva-nav-collapsed .side-nav .side-menu__title-section,
    body.tva-nav-collapsed .side-nav > a span { opacity:0; transition:opacity .12s; white-space:nowrap; }
    /* Hover the rail → expand and reveal labels (content reflows). */
    body.tva-nav-collapsed .side-nav:hover { width:250px !important; overflow:visible; box-shadow:6px 0 24px rgba(0,0,0,.18); }
    body.tva-nav-collapsed .side-nav:hover .side-menu__title,
    body.tva-nav-collapsed .side-nav:hover .side-menu__title-section,
    body.tva-nav-collapsed .side-nav:hover > a span { opacity:1; }

    #navCollapseBtn {
        width:38px; height:38px; border-radius:9px; border:1px solid #e2e8f0; background:#fff;
        display:flex; align-items:center; justify-content:center; cursor:pointer; color:#475569; flex-shrink:0; margin-right:6px;
    }
    #navCollapseBtn:hover { background:#f1f5f9; }
    html.dark #navCollapseBtn { background:#1e293b; border-color:#334155; color:#cbd5e1; }
</style>
<script>
(function(){
    function apply(c){ document.body.classList.toggle('tva-nav-collapsed', !!c); }
    apply(localStorage.getItem('tvaNavCollapsed')==='1');
    var bar=document.querySelector('.top-bar');
    if(bar){
        var btn=document.createElement('button');
        btn.id='navCollapseBtn'; btn.type='button'; btn.title='Collapse / expand menu';
        btn.innerHTML='<i data-lucide="menu" class="w-4 h-4"></i>';
        btn.onclick=function(){
            var c=!document.body.classList.contains('tva-nav-collapsed');
            apply(c); localStorage.setItem('tvaNavCollapsed', c?'1':'0');
        };
        bar.insertBefore(btn, bar.firstChild);
        if(window.lucide){ try{ lucide.createIcons(); }catch(e){} }
    }
})();
</script>
