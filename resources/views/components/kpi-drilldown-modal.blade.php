{{--
    Drill-down modal for the Admin dashboard's clickable KPI cards (Feature:
    click a card, see exactly which documents/users it counted). One shared
    modal, invoked from anywhere via JS: openKpiDrilldown(type, label, url)
    — mirrors the fetch-and-inject pattern already used by
    document-viewer-modal.blade.php.
--}}
<div id="kpi-drilldown-overlay" class="hidden fixed inset-0 z-50 bg-surface-900/60 backdrop-blur-sm flex items-center justify-center p-4" onclick="if(event.target === this) closeKpiDrilldown()">
    <div class="bg-white rounded-xl shadow-2xl w-[90vw] max-w-6xl h-[85vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 flex-shrink-0">
            <h3 id="kpi-drilldown-title" class="text-sm font-semibold text-surface-900"></h3>
            <button type="button" onclick="closeKpiDrilldown()" class="text-surface-400 hover:text-surface-700" aria-label="Close">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="kpi-drilldown-body" class="flex-1 overflow-auto"></div>
    </div>
</div>

<script>
    function closeKpiDrilldown() {
        document.getElementById('kpi-drilldown-overlay').classList.add('hidden');
        document.getElementById('kpi-drilldown-body').innerHTML = '';
    }

    async function openKpiDrilldown(type, label, url) {
        const overlay = document.getElementById('kpi-drilldown-overlay');
        const title = document.getElementById('kpi-drilldown-title');
        const body = document.getElementById('kpi-drilldown-body');

        title.textContent = label;
        overlay.classList.remove('hidden');
        body.innerHTML = '<div class="p-10 text-center text-sm text-surface-400">Loading…</div>';

        try {
            const res = await fetch(url, { headers: { Accept: 'text/html' } });
            if (!res.ok) throw new Error('Fetch failed');
            body.innerHTML = await res.text();
        } catch (e) {
            body.innerHTML = '<div class="p-10 text-center text-sm text-rejected-600">Couldn\'t load this list.</div>';
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeKpiDrilldown();
    });
</script>
