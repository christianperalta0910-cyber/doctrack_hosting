@extends('layouts.app')
@section('title', 'Workflow Config')
@section('page-title', 'Workflow Configuration')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-4">Add Workflow Stage</h2>
            <form method="POST" action="{{ route('admin.workflow.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Document Category</label>
                    <select name="document_category" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                        @foreach($categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Stage Name</label>
                    <input name="stage_name" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Sequence Order</label>
                    <input type="number" name="sequence_order" min="1" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                </div>
                <p class="text-[11px] text-surface-400">Approver SLA windows are no longer configured per stage — they're calculated automatically as a business-hours-aware percentage of the time remaining until each document's own due date.</p>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2"></textarea>
                </div>
                <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg">Add Stage</button>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6 mt-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-1">Approver Decision Restriction</h2>
            <p class="text-xs text-surface-500 mb-4">
                When on, an approver can only Approve/Reject during business hours (9 AM–5 PM, Mon–Sat) — this stops a document from being decided after-hours to dodge acting on it during paid working time. Off by default.
            </p>
            <form method="POST" action="{{ route('admin.systemSettings.businessHoursToggle') }}">
                @csrf
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="enforce_business_hours_decisions" value="1"
                        {{ $businessHoursEnforced ? 'checked' : '' }}
                        onchange="this.form.submit()"
                        class="sr-only peer">
                    <span class="relative w-10 h-6 bg-surface-200 peer-checked:bg-primary-700 rounded-full transition-colors">
                        <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></span>
                    </span>
                    <span class="text-xs font-medium text-surface-700">
                        Restrict approver decisions to business hours — currently <strong class="{{ $businessHoursEnforced ? 'text-primary-700' : 'text-surface-500' }}">{{ $businessHoursEnforced ? 'ON' : 'OFF' }}</strong>
                    </span>
                </label>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2 space-y-6" id="workflow-config-results"
        data-poll-url="{{ route('admin.workflow.config.poll') }}" data-refresh-url="{{ route('admin.workflow.config.refresh') }}">
        @include('admin.partials.workflow-config-results')
    </div>
</div>

<script>
    // Same live-poll pattern as every other admin module — see
    // dashboard.blade.php's comment for the full reasoning. A stage
    // added/archived or an assignment decided elsewhere (e.g. from the SLA
    // queue) needs to show up here without a manual reload, since the
    // pending counts and "review & decide" panel above act on live data.
    document.addEventListener('DOMContentLoaded', function () {
        const resultsEl = document.getElementById('workflow-config-results');
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
