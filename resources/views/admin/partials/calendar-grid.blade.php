{{--
    Calendar grid — split out from calendar.blade.php so the same markup
    can be rendered two ways: a normal full page load, and a fragment
    returned by AdminController::calendarRefresh() for the live-poll JS to
    swap in place, without a full page reload.
--}}
@php
    $dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $gridStart = $month->copy()->startOfMonth()->startOfWeek(\Carbon\Carbon::SUNDAY);
    $gridEnd = $month->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SATURDAY);
    // Fixed operational window (9-5, Mon-Sat) — no longer admin-editable,
    // see config/sla.php. Only holiday marking is still admin-controlled.
    $workingDays = config('sla.default_working_days');
@endphp

<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
        <a href="{{ route('admin.calendar', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}" class="text-sm text-primary-700 hover:underline font-medium">&larr; Prev</a>
        <div class="text-center">
            <h2 class="text-sm font-semibold text-surface-900">{{ $month->format('F Y') }}</h2>
            <p class="text-[11px] text-surface-400 mt-0.5">
                Working hours: {{ \Carbon\Carbon::parse(config('sla.default_work_start'))->format('g:i A') }}&ndash;{{ \Carbon\Carbon::parse(config('sla.default_work_end'))->format('g:i A') }},
                {{ collect($workingDays)->sort()->map(fn ($d) => $dayLabels[$d])->implode(', ') }}
            </p>
        </div>
        <a href="{{ route('admin.calendar', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}" class="text-sm text-primary-700 hover:underline font-medium">Next &rarr;</a>
    </div>

    <div class="overflow-x-auto">
    <table class="w-full min-w-[560px] text-xs table-fixed">
        <thead class="bg-surface-50 text-surface-500 uppercase tracking-wide">
            <tr>
                @foreach($dayLabels as $label)
                    <th class="text-center px-1 py-2 font-medium">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @php $cursor = $gridStart->copy(); @endphp
            @while($cursor->lte($gridEnd))
                <tr class="border-t border-surface-100">
                    @for($i = 0; $i < 7; $i++)
                        @php
                            $dateKey = $cursor->toDateString();
                            $inMonth = $cursor->month === $month->month;
                            $holiday = $holidays[$dateKey] ?? null;
                            $isWorkingWeekday = in_array($cursor->dayOfWeek, $workingDays);
                        @endphp
                        <td class="align-top p-1.5 {{ $inMonth ? '' : 'opacity-30' }}">
                            <div class="rounded-lg border {{ $holiday ? 'border-rejected-300 bg-rejected-50' : ($isWorkingWeekday ? 'border-surface-200' : 'border-surface-100 bg-surface-50') }} p-1.5 min-h-[64px]">
                                <button type="button"
                                    onclick="openKpiDrilldown('date', '{{ $cursor->format('M j, Y') }}', '{{ route('admin.calendar.documentsOnDate', $dateKey) }}')"
                                    class="text-[11px] font-medium text-surface-600 hover:text-primary-700 hover:underline">
                                    {{ $cursor->day }}
                                </button>
                                @if($holiday)
                                    <p class="text-[10px] text-rejected-700 truncate" title="{{ $holiday->label }}">{{ $holiday->label ?: 'Non-working' }}</p>
                                    <form method="POST" action="{{ route('admin.calendar.holidays.destroy', $holiday) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-[10px] text-surface-400 hover:text-rejected-700 hover:underline">Remove</button>
                                    </form>
                                @elseif($inMonth)
                                    <form method="POST" action="{{ route('admin.calendar.holidays.store') }}">
                                        @csrf
                                        <input type="hidden" name="holiday_date" value="{{ $dateKey }}">
                                        <button class="text-[10px] text-primary-700 hover:underline">+ Mark off</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    @php $cursor->addDay(); @endphp
                    @endfor
                </tr>
            @endwhile
        </tbody>
    </table>
    </div>
</div>
