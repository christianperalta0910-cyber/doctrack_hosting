{{--
    Performance Insights results — split out from performance_insights.blade.php
    so the same markup can be rendered two ways: a normal full page load, and
    a fragment returned by AdminController::performanceInsightsRefresh() for
    the live-poll JS to swap in place, without a full page reload.

    Three plain historical rankings, side by side — see
    PerformanceInsightsService's docblock for why none of this is ML.
--}}
@php
    $panels = [
        ['title' => 'Fastest Approvers', 'subtitle' => 'Ranked by average time from assignment to decision.', 'rows' => $fastestApprovers, 'empty' => 'Not enough decision history yet to rank approvers.'],
        ['title' => 'Fastest Departments', 'subtitle' => 'Same ranking, grouped by department.', 'rows' => $fastestDepartments, 'empty' => 'Not enough decision history yet to rank departments.'],
        ['title' => 'Fastest Categories', 'subtitle' => 'Which document types move through approval quickest.', 'rows' => $fastestCategories, 'empty' => 'Not enough decision history yet to rank categories.'],
    ];
@endphp
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    @foreach($panels as $panel)
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-surface-200">
                <h2 class="text-sm font-semibold text-surface-900 tracking-tight">{{ $panel['title'] }}</h2>
                <p class="text-xs text-surface-400 mt-0.5">{{ $panel['subtitle'] }}</p>
            </div>
            <ul class="divide-y divide-surface-100">
                @forelse($panel['rows'] as $i => $row)
                    <li class="px-5 py-3 flex items-center gap-3">
                        <span class="w-5 h-5 shrink-0 rounded-full flex items-center justify-center text-[11px] font-bold {{ $i === 0 ? 'bg-approved-500 text-white' : 'bg-surface-100 text-surface-500' }}">{{ $i + 1 }}</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-surface-800 truncate">{{ $row['label'] }}</p>
                            <p class="text-xs text-surface-400">{{ $row['decisions_count'] }} decision{{ $row['decisions_count'] === 1 ? '' : 's' }}</p>
                        </div>
                        <span class="shrink-0 text-xs font-semibold text-surface-700 tabular-nums">{{ \Carbon\CarbonInterval::seconds($row['avg_seconds'])->cascade()->forHumans(['short' => true]) }}</span>
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-xs text-surface-400">{{ $panel['empty'] }}</li>
                @endforelse
            </ul>
        </div>
    @endforeach
</div>
