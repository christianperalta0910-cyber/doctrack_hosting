@extends('layouts.app')
@section('title', 'Control Center')
@section('page-title', 'Admin Control Center')

@section('content')
<div class="space-y-6">
    <div id="admin-overview" data-poll-url="{{ route('admin.dashboard.poll') }}" data-refresh-url="{{ route('admin.dashboard.refresh') }}">
        @include('admin.partials.overview')
    </div>
</div>

<script>
    // Live-updates the KPI cards + SLA alerts without a full page reload —
    // instant via Reverb (see startLiveChannel in resources/js/app.js) the
    // moment any document changes status anywhere in the system; the slow
    // poll behind it is only a fallback in case the WebSocket connection
    // is down. Not scoped to one user's channel — every admin shares the
    // same 'admin-dashboard' channel, since admins see all documents.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE — see the matching
    // comment in approver/dashboard.blade.php for why: this plain inline
    // script would otherwise run before app.js's deferred module script
    // has defined startLiveChannel/startLivePoll, throw immediately, and
    // silently never wire anything up.
    document.addEventListener('DOMContentLoaded', function () {
        const overviewEl = document.getElementById('admin-overview');
        if (!overviewEl) return;

        const opts = {
            refreshUrl: overviewEl.dataset.refreshUrl,
            target: overviewEl,
        };

        startLiveChannel('admin-dashboard', '.document.status-changed', opts);
        // Covers everything else on this page — logins, uploads, decisions,
        // SLA escalations, approver availability toggles — that isn't a
        // document status change but still needs Recent Activity/Analytics/
        // etc. to update live (see AdminActivityLogged).
        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: overviewEl.dataset.pollUrl });

        // Analytics panel's Day/Month/Year tab toggle — delegated on the
        // stable #admin-overview wrapper rather than bound directly to the
        // tab buttons, because those buttons live inside
        // admin/partials/overview.blade.php, which gets replaced wholesale
        // on every live refresh above. A listener bound to the old
        // (now-removed) buttons would silently stop working after the
        // first live update; delegation on the wrapper that never gets
        // replaced keeps working across any number of swaps.
        overviewEl.addEventListener('click', function (e) {
            const btn = e.target.closest('.analytics-tab-btn');
            if (!btn || !overviewEl.contains(btn)) return;

            const tab = btn.dataset.analyticsTab;
            overviewEl.querySelectorAll('.analytics-tab-btn').forEach((b) => {
                b.classList.toggle('bg-primary-700', b === btn);
                b.classList.toggle('text-white', b === btn);
                b.classList.toggle('text-surface-600', b !== btn);
            });
            overviewEl.querySelectorAll('.analytics-tab-panel').forEach((p) => {
                p.classList.toggle('hidden', p.dataset.analyticsPanel !== tab);
            });
        });
    });
</script>
@endsection
