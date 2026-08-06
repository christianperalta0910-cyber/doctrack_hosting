{{--
    KPI cards + SLA Override Alerts + Active ML Model — split out from
    dashboard.blade.php so the same markup can be rendered two ways: a
    normal full page load, and a fragment returned by
    AdminController::overviewRefresh() for the live-poll JS to swap in
    place (see dashboard.blade.php) without a full page reload. The ML
    Model panel is included purely to keep the 3-column grid row (SLA
    alerts + ML model side by side) as one swap target — it rarely
    changes, but re-rendering it each cycle is harmless.
--}}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
    @foreach([
        ['Total Documents', $stats['total_documents'], 'text-surface-900', 'bg-surface-100 text-surface-600', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'total'],
        ['In Progress', $stats['pending'], 'text-processing-700', 'bg-processing-50 text-processing-600', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'pending'],
        ['Approved', $stats['approved'], 'text-approved-700', 'bg-approved-50 text-approved-600', 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'approved'],
        ['Rejected', $stats['rejected'], 'text-rejected-700', 'bg-rejected-50 text-rejected-600', 'M6 18L18 6M6 6l12 12', 'rejected'],
        ['Active Users', $stats['active_users'], 'text-primary-700', 'bg-primary-50 text-primary-600', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-8a4 4 0 11-8 0 4 4 0 018 0zm6 3a4 4 0 11-8 0 4 4 0 018 0z', 'users'],
    ] as [$label, $value, $color, $iconClasses, $iconPath, $type])
        <button type="button"
            onclick="openKpiDrilldown('{{ $type }}', '{{ $label }}', '{{ route('admin.dashboard.drilldown', $type) }}')"
            class="text-left w-full bg-white rounded-xl shadow-card hover:shadow-card-hover border border-surface-200 p-5 transition-shadow cursor-pointer focus:outline-none focus:ring-2 focus:ring-primary-500">
            <div class="w-9 h-9 rounded-lg flex items-center justify-center mb-3 {{ $iconClasses }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}"/></svg>
            </div>
            <p class="text-xs text-surface-500 mb-1 font-medium">{{ $label }}</p>
            <p class="text-2xl font-bold {{ $color }} tabular-nums">{{ $value }}</p>
        </button>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
    {{-- SLA override alerts --}}
    <div class="lg:col-span-2 bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-surface-900 tracking-tight flex items-center gap-2">
                <span class="relative flex w-2 h-2">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-rejected-400 opacity-75 animate-ping"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-rejected-500"></span>
                </span>
                SLA Override Alerts
            </h2>
            <div class="flex items-center gap-3">
                @if($reviewCount > 0)
                    <a href="{{ route('admin.sla.queue') }}" class="text-xs bg-processing-50 text-processing-700 px-2 py-1 rounded-full font-medium ring-1 ring-inset ring-processing-500/20">{{ $reviewCount }} auto-approved awaiting review</a>
                @endif
                <a href="{{ route('admin.sla.queue') }}" class="text-xs text-primary-700 hover:underline font-medium">View all &rarr;</a>
            </div>
        </div>
        @php
            // Nest by document — a single document can have more than one
            // breached stage (e.g. Budget Check and Final Approval both
            // pending on the same doc), which previously showed as
            // separate flat rows repeating the same title.
            $alertsByDocument = $slaAlerts->groupBy('document_id')->take(5);
        @endphp
        <ul class="divide-y divide-surface-100">
            @forelse($alertsByDocument as $breaches)
                @php $doc = $breaches->first()->document; @endphp
                <li class="px-6 py-3 hover:bg-surface-50/60 transition-colors">
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-medium text-surface-800 truncate">{{ $doc->title }}</p>
                        <a href="{{ route('admin.sla.queue') }}" class="shrink-0 text-xs bg-primary-700 text-white px-3 py-1.5 rounded-lg font-medium hover:bg-primary-800 shadow-sm transition-colors">Override</a>
                    </div>
                    <ul class="mt-1.5 space-y-1">
                        @foreach($breaches as $a)
                            <li class="text-xs text-rejected-700 flex items-center gap-1.5">
                                <span class="w-1 h-1 rounded-full bg-rejected-500 shrink-0"></span>
                                <span>
                                    Stage "{{ $a->stage->stage_name }}" — expired <span data-live-time="{{ $a->sla_expires_at->timestamp }}">{{ $a->sla_expires_at->diffForHumans() }}</span>
                                    @if($a->adminGraceExpiresAt())
                                        &middot; <span data-live-time="{{ $a->adminGraceExpiresAt()->timestamp }}" data-live-urgent-under="7200">{{ $a->adminGraceExpiresAt()->diffForHumans() }}</span> until auto-approval
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @empty
                <li class="px-6 py-8 text-center text-sm text-surface-400">No SLA breaches — everything is on schedule.</li>
            @endforelse
        </ul>
    </div>

    {{-- Active ML model --}}
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
        <h2 class="text-sm font-semibold text-surface-900 tracking-tight mb-4">Active ML Model</h2>
        @if($activeModel)
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-surface-500">Version</dt><dd class="font-medium tabular-nums">{{ $activeModel->version }}</dd></div>
                <div class="flex justify-between"><dt class="text-surface-500">Training samples</dt><dd class="font-medium tabular-nums">{{ $activeModel->training_sample_count }}</dd></div>
                <div class="flex justify-between"><dt class="text-surface-500">Est. accuracy</dt><dd class="font-medium text-approved-700 tabular-nums">{{ $activeModel->accuracy_score }}%</dd></div>
                <div class="flex justify-between"><dt class="text-surface-500">Last trained</dt><dd class="font-medium">{{ $activeModel->last_trained->diffForHumans() }}</dd></div>
            </dl>
        @else
            <p class="text-sm text-surface-400">No model trained yet.</p>
        @endif
        <a href="{{ route('admin.ml.training') }}" class="mt-4 inline-block text-xs font-medium text-primary-700 hover:underline">Manage training data &rarr;</a>
    </div>
</div>

{{--
    System-wide module overview (Feature: dashboard as an actual control
    center) — recent activity, approver workload, category breakdown, and
    quick links to every admin module, none of which had any presence on
    this page before.
--}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
    {{-- Recent activity feed, drawn from the audit log --}}
    <div class="lg:col-span-2 bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-surface-900 tracking-tight">Recent Activity</h2>
            <a href="{{ route('admin.audit.logs') }}" class="text-xs text-primary-700 hover:underline font-medium">View all &rarr;</a>
        </div>
        <ul class="divide-y divide-surface-100">
            @forelse($recentActivity as $log)
                <li class="px-6 py-3 text-sm flex items-start justify-between gap-3">
                    <p class="text-surface-800 truncate">
                        <span class="font-medium">{{ $log->user->full_name ?? 'System' }}</span>
                        <span class="text-surface-500">— {{ $log->description }}</span>
                    </p>
                    <span class="shrink-0 text-xs text-surface-400" data-live-time="{{ $log->timestamp->timestamp }}">{{ $log->timestamp->diffForHumans() }}</span>
                </li>
            @empty
                <li class="px-6 py-8 text-center text-sm text-surface-400">No recent activity.</li>
            @endforelse
        </ul>
    </div>

    {{-- Approver workload / availability --}}
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
        <h2 class="text-sm font-semibold text-surface-900 tracking-tight mb-4">Approver Workload</h2>
        <ul class="space-y-2.5">
            @forelse($approverWorkload as $approver)
                <li class="flex items-center justify-between text-sm gap-2">
                    <span class="text-surface-700 truncate">{{ $approver->full_name }}</span>
                    <span class="flex items-center gap-2 shrink-0">
                        <span class="text-xs font-medium {{ $approver->is_busy ? 'text-amber-600' : 'text-approved-600' }}">{{ $approver->is_busy ? 'Busy' : 'Free' }}</span>
                        <span class="text-xs font-semibold tabular-nums bg-surface-100 text-surface-700 px-2 py-0.5 rounded-full min-w-[1.5rem] text-center">{{ $approver->pending_count }}</span>
                    </span>
                </li>
            @empty
                <li class="text-sm text-surface-400">No approvers yet.</li>
            @endforelse
        </ul>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 mt-6">
    {{-- Documents by category --}}
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
        <h2 class="text-sm font-semibold text-surface-900 tracking-tight mb-4">Documents by Category</h2>
        @php $maxCat = $categoryBreakdown->max() ?: 1; @endphp
        <ul class="space-y-3">
            @forelse($categoryBreakdown as $category => $count)
                <li>
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="text-surface-600 font-medium">{{ $category }}</span>
                        <span class="text-surface-500 tabular-nums">{{ $count }}</span>
                    </div>
                    <div class="h-1.5 bg-surface-100 rounded-full overflow-hidden">
                        <div class="h-full bg-primary-500 rounded-full" style="width: {{ ($count / $maxCat) * 100 }}%"></div>
                    </div>
                </li>
            @empty
                <li class="text-sm text-surface-400">No classified documents yet.</li>
            @endforelse
        </ul>
    </div>
</div>
