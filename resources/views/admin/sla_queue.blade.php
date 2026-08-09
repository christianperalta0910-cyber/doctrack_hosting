@extends('layouts.app')
@section('title', 'SLA Overrides')
@section('page-title', 'SLA Override Queue')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs">
        These assignments violated their SLA deadline and were escalated for Admin review. If left unresolved for the configured grace period, the system will auto-approve them and notify all Admins &amp; Approvers.
    </div>

    <div id="sla-queue-results" class="space-y-6" data-poll-url="{{ route('admin.sla.queue.poll') }}" data-refresh-url="{{ route('admin.sla.queue.refresh') }}">
        @include('admin.partials.sla-queue-results')
    </div>
</div>

<script>
    // Same live-poll pattern as every other admin module — see
    // dashboard.blade.php's comment for the full reasoning. Another admin
    // overriding a violation, or a new escalation/auto-approval landing
    // here, needs to show up without a manual reload.
    document.addEventListener('DOMContentLoaded', function () {
        const resultsEl = document.getElementById('sla-queue-results');
        if (!resultsEl) return;

        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
            preserveQueryString: true, // keeps whichever page of the queue is currently open
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });

        // Both the SLA Override Queue and Auto-Approved — Awaiting Review
        // pagination live inside this one container — see
        // enableAjaxPagination()'s docblock in app.js for why one shared
        // fragment fetch correctly handles both.
        enableAjaxPagination(resultsEl, opts);
    });
</script>
@endsection
