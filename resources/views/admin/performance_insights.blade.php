@extends('layouts.app')
@section('title', 'Performance Insights')
@section('page-title', 'Performance Insights')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs">
        Plain historical averages — who decides fastest, which department is fastest, and which document category moves fastest. This is a report, not a prediction; nothing here is machine-learned.
    </div>

    <div id="performance-insights-results" data-poll-url="{{ route('admin.performance.insights.poll') }}" data-refresh-url="{{ route('admin.performance.insights.refresh') }}">
        @include('admin.partials.performance-insights-results')
    </div>
</div>

<script>
    // Same live-poll pattern as every other admin module — see
    // dashboard.blade.php's comment for the full reasoning. A new decision
    // landing anywhere in the system can shift these rankings.
    document.addEventListener('DOMContentLoaded', function () {
        const resultsEl = document.getElementById('performance-insights-results');
        if (!resultsEl) return;

        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });
    });
</script>
@endsection
