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
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
    @foreach([
        ['Total Documents', $stats['total_documents'], 'text-surface-900', 'bg-surface-100 text-surface-600', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'total'],
        ['In Progress', $stats['pending'], 'text-processing-700', 'bg-processing-50 text-processing-600', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'pending'],
        ['Approved', $stats['approved'], 'text-approved-700', 'bg-approved-50 text-approved-600', 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'approved'],
        ['Rejected', $stats['rejected'], 'text-rejected-700', 'bg-rejected-50 text-rejected-600', 'M6 18L18 6M6 6l12 12', 'rejected'],
        ['Active Users', $stats['active_users'], 'text-primary-700', 'bg-primary-50 text-primary-600', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-8a4 4 0 11-8 0 4 4 0 018 0zm6 3a4 4 0 11-8 0 4 4 0 018 0z', 'users'],
    ] as [$label, $value, $color, $iconClasses, $iconPath, $type])
        <button type="button"
            onclick="openKpiDrilldown('{{ $type }}', '{{ $label }}', '{{ route('admin.dashboard.drilldown', $type) }}')"
            class="text-left w-full bg-white rounded-xl shadow-card hover:shadow-card-hover border border-surface-200 p-4 transition-shadow cursor-pointer focus:outline-none focus:ring-2 focus:ring-primary-500">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center mb-2 {{ $iconClasses }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}"/></svg>
            </div>
            <p class="text-xs text-surface-500 mb-0.5 font-medium">{{ $label }}</p>
            <p class="text-xl font-bold {{ $color }} tabular-nums">{{ $value }}</p>
        </button>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
    {{--
        Analytics — constrained to 2/3 width here (not its own full-width
        row) specifically so it doesn't visually dominate the dashboard
        next to everything else, which is why it sits in this grid rather
        than below it. ONE reusable panel (see
        AdminController::analyticsPanelData()) whose content is fetched via
        AJAX whenever the Day/Week/Month/Year tab or the date filter
        changes — never four pre-rendered panels toggled by CSS. The
        wrapper below carries the persistent id + refresh URL and survives
        every swap; only what's inside it is replaced (see
        dashboard.blade.php's script). $panel is only passed in on the
        initial dashboard() load — overviewRefresh()'s periodic live-swap
        deliberately omits it (see dashboardExtras()'s docblock), so the
        script re-fetches the currently-selected state right after any
        such swap.
    --}}
    <div class="lg:col-span-2 bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-surface-200 flex items-center justify-between flex-wrap gap-3">
            <h2 class="text-sm font-semibold text-surface-900 tracking-tight">Analytics</h2>
            <div class="flex items-center gap-2 flex-wrap">
                <input type="date" id="analytics-date-filter" value="{{ $panel['as_of'] ?? now()->toDateString() }}"
                    max="{{ now()->toDateString() }}"
                    class="text-xs rounded-lg border border-surface-200 px-2 py-1.5 text-surface-600 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                <div class="inline-flex rounded-lg border border-surface-200 overflow-hidden text-xs font-medium">
                    @foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $key => $label)
                        <button type="button" data-analytics-tab="{{ $key }}"
                            class="analytics-tab-btn px-3 py-1.5 transition-colors {{ ($panel['granularity'] ?? 'day') === $key ? 'bg-primary-700 text-white' : 'text-surface-600 hover:bg-surface-50' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="px-5 py-2 border-b border-surface-100 flex flex-wrap gap-x-6 gap-y-1 text-xs text-surface-500">
            <span>Peak upload day: <span class="font-semibold text-surface-700">{{ $analytics['peak_day'] ?? '—' }}</span></span>
            <span>Peak upload hour: <span class="font-semibold text-surface-700">{{ $analytics['peak_hour'] ?? '—' }}</span></span>
            <span>Currently in progress: <span class="font-semibold text-surface-700">{{ $analytics['backlog_count'] }}</span> document{{ $analytics['backlog_count'] === 1 ? '' : 's' }}</span>
        </div>

        <div id="analytics-panel" data-refresh-url="{{ route('admin.dashboard.analyticsPanel') }}">
            @isset($panel)
                @include('admin.partials.analytics-panel', ['panel' => $panel])
            @endisset
        </div>
    </div>

    {{-- Active ML model --}}
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
        <h2 class="text-sm font-semibold text-surface-900 tracking-tight mb-3">Active ML Model</h2>
        @if($activeModel)
            <dl class="space-y-2 text-sm">
                <div><dt class="text-surface-500">Algorithm</dt><dd class="font-medium text-surface-800">{{ $activeModel->model_name }}</dd></div>
                <div class="flex justify-between"><dt class="text-surface-500">Version</dt><dd class="font-medium tabular-nums">{{ $activeModel->version }}</dd></div>
                <div class="flex justify-between"><dt class="text-surface-500">Training samples</dt><dd class="font-medium tabular-nums">{{ $activeModel->training_sample_count }}</dd></div>
                <div class="flex justify-between"><dt class="text-surface-500">Est. accuracy</dt><dd class="font-medium text-approved-700 tabular-nums">{{ $activeModel->accuracy_score }}%</dd></div>
                <div class="flex justify-between"><dt class="text-surface-500">Last trained</dt><dd class="font-medium">{{ $activeModel->last_trained->diffForHumans() }}</dd></div>
            </dl>

            {{-- Only shown once there's an actual history beyond the single
                 active model — a "history" list of one entry would just
                 repeat everything above. Reuses AdminController::
                 modelHistory(), the same query shape the ML Training page
                 already runs. --}}
            @if(isset($modelHistory) && $modelHistory->count() > 1)
                <div class="mt-4 pt-4 border-t border-surface-100">
                    <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2">Version History</h3>
                    <ul class="space-y-2">
                        @foreach($modelHistory as $m)
                            <li class="flex items-center justify-between text-xs gap-2">
                                <span class="flex items-center gap-1.5 text-surface-600 truncate">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $m->is_active ? 'bg-approved-500' : 'bg-surface-300' }}" title="{{ $m->is_active ? 'Active' : 'Retired' }}"></span>
                                    <span class="font-medium tabular-nums">{{ $m->version }}</span>
                                    <span class="text-surface-400 shrink-0">&middot; {{ $m->last_trained?->format('M j, Y') ?? '—' }}</span>
                                </span>
                                <span class="font-medium tabular-nums shrink-0 {{ $m->is_active ? 'text-approved-700' : 'text-surface-500' }}">{{ $m->accuracy_score }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @else
            <p class="text-sm text-surface-400">No model trained yet.</p>
        @endif
        <a href="{{ route('admin.ml.training') }}" class="mt-4 inline-block text-xs font-medium text-primary-700 hover:underline">Manage training data &rarr;</a>
    </div>
</div>

{{-- SLA override alerts — moved to its own full-width row (was previously
     squeezed to 2/3 width beside the ML model panel); it's a list of
     rows, not a wide chart, so full width doesn't hurt it the way it hurt
     Analytics. --}}
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden mt-4">
    <div class="px-5 py-3 border-b border-surface-200 flex items-center justify-between">
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
        // violated stage (e.g. Budget Check and Final Approval both
        // pending on the same doc), which previously showed as
        // separate flat rows repeating the same title.
        $alertsByDocument = $slaAlerts->groupBy('document_id')->take(5);
    @endphp
    <ul class="divide-y divide-surface-100">
        @forelse($alertsByDocument as $violations)
            @php $doc = $violations->first()->document; @endphp
            <li class="px-5 py-2.5 hover:bg-surface-50/60 transition-colors">
                <div class="flex items-center justify-between gap-3">
                    <p class="font-medium text-surface-800 truncate">{{ $doc->title }}</p>
                    <a href="{{ route('admin.sla.queue') }}" class="shrink-0 text-xs bg-primary-700 text-white px-3 py-1.5 rounded-lg font-medium hover:bg-primary-800 shadow-sm transition-colors">Override</a>
                </div>
                <ul class="mt-1 space-y-0.5">
                    @foreach($violations as $a)
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
            <li class="px-5 py-6 text-center text-sm text-surface-400">No SLA violations — everything is on schedule.</li>
        @endforelse
    </ul>
</div>

{{--
    Category volume — all-time (not tab-scoped, see AdminController::
    analyticsData()'s matching comment), a separate card since it answers a
    different question ("what kind of documents are busiest overall") than
    the time-series panel above ("how is the pipeline trending"). A plain
    ranked bar list, not a pie/donut — reading exact proportions off a pie
    chart is genuinely harder than reading bar lengths, and a simple list
    doesn't need its own categorical color palette when the labels already
    identify each row.
--}}
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden mt-4">
    <div class="px-5 py-3 border-b border-surface-200">
        <h2 class="text-sm font-semibold text-surface-900 tracking-tight">Category Volume</h2>
        <p class="text-xs text-surface-400 mt-0.5">All-time document volume per category.</p>
    </div>
    <div class="p-5 space-y-3">
        @php $maxCategoryCount = $analytics['category_volume']->max() ?: 1; @endphp
        @forelse($analytics['category_volume'] as $category => $count)
            <div class="flex items-center gap-3 text-sm">
                <span class="w-40 shrink-0 text-surface-700 font-medium truncate" title="{{ $category }}">{{ $category }}</span>
                <div class="flex-1 h-2 bg-surface-100 rounded-full overflow-hidden">
                    <div class="h-full bg-primary-500 rounded-full" style="width: {{ ($count / $maxCategoryCount) * 100 }}%"></div>
                </div>
                <span class="w-10 text-right tabular-nums text-surface-500">{{ $count }}</span>
            </div>
        @empty
            <p class="text-xs text-surface-400 text-center py-4">No classified documents yet.</p>
        @endforelse
    </div>
</div>

<div class="grid grid-cols-1 gap-4 mt-4">
    {{--
        Recent activity feed — literally the Audit Trail's own row partial
        (admin/partials/audit-row.blade.php), not just similarly styled —
        same columns, same badges, same document-grouped expand-to-view-
        movements interaction, so this reads as a compact preview of that
        exact table rather than a separately-built lookalike. Bounded to
        the last 5 merged document/system rows (see
        AdminController::recentActivityRows()) rather than the full-page
        query buildAuditRows() runs, since this loads on every dashboard
        view/poll.
    --}}
    <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-5 py-2.5 border-b border-surface-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-surface-900 tracking-tight">Recent Activity</h2>
            <a href="{{ route('admin.audit.logs') }}" class="text-xs text-primary-700 hover:underline font-medium">View all &rarr;</a>
        </div>
        @php
            $actionCategories = \App\Services\DocumentMovementTimeline::ACTION_CATEGORIES;
            $categoryClasses = \App\Services\DocumentMovementTimeline::CATEGORY_CLASSES;
            $actionLabels = \App\Services\DocumentMovementTimeline::ACTION_LABELS;
        @endphp
        <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left px-6 py-2 font-medium">Timestamp</th>
                    <th class="text-left px-6 py-2 font-medium">Document Title</th>
                    <th class="text-left px-6 py-2 font-medium">Actor</th>
                    <th class="text-left px-6 py-2 font-medium">Action</th>
                    <th class="text-left px-6 py-2 font-medium">Track</th>
                    <th class="text-left px-6 py-2 font-medium">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-surface-100">
                @forelse($recentActivity as $row)
                    @include('admin.partials.audit-row', ['row' => $row])
                @empty
                    <tr><td colspan="6" class="px-6 py-6 text-center text-sm text-surface-400">No recent activity.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
