@extends('layouts.app')
@section('title', 'Unassigned Documents')
@section('page-title', 'Unassigned Documents')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs">
        These stages have nobody currently eligible to review them — usually because the only qualified approver
        for that category/stage was deactivated.
    </div>

    <div id="unassigned-wrapper" data-poll-url="{{ route('admin.unassigned.poll') }}" data-refresh-url="{{ route('admin.unassigned.refresh') }}">
        @include('admin.partials.unassigned-documents')
    </div>
</div>

<script>
    // Same live-poll pattern as every other admin module (see
    // dashboard.blade.php's comment for why this is wrapped in
    // DOMContentLoaded, and Document Tracking's for the channel choice) —
    // this needs to update the instant an admin toggles another user, or
    // an assignment here is assigned/decided elsewhere (e.g. another admin
    // tab).
    document.addEventListener('DOMContentLoaded', function () {
        const wrapperEl = document.getElementById('unassigned-wrapper');
        if (!wrapperEl) return;

        const opts = {
            refreshUrl: wrapperEl.dataset.refreshUrl,
            target: wrapperEl,
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: wrapperEl.dataset.pollUrl });
    });
</script>
@endsection
