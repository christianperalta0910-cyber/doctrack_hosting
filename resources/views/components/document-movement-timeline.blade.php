@props(['document'])

{{--
    Every recorded movement on a document, merged into one chronological
    feed: audit log entries (classification, validation, routing,
    decisions, overrides, downloads, etc. — see AuditLog::record() call
    sites throughout the app) plus review-session open/close events (who
    opened it, how long they reviewed — see DocumentReviewSession). Built
    by App\Services\DocumentMovementTimeline,
    shared with the Originator's Audit Trail table (same data, table format
    instead of this list format) — reused as-is on the Admin Document
    Tracking module and the Audit Logs page's nested per-document view.
--}}
@php
    $timeline = \App\Services\DocumentMovementTimeline::build($document);
@endphp

<ul class="divide-y divide-surface-100">
    @forelse($timeline as $event)
        <li class="px-4 py-2.5 text-sm">
            @if($event['kind'] === 'session_group')
                {{-- One row per person's review activity, not one flat row
                     per open/close — see DocumentMovementTimeline::build()'s
                     docblock. Pass breakdown is short enough to just show
                     plainly underneath, no click needed. --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold mr-1.5 {{ \App\Services\DocumentMovementTimeline::CATEGORY_CLASSES['review'] }}">
                            {{ $event['label'] }}
                        </span>
                        <span class="text-surface-700 font-medium">{{ $event['actor'] }}</span>
                        <span class="text-surface-500">— {{ $event['note'] }}</span>
                    </div>
                    <span class="shrink-0 text-xs text-surface-400">{{ $event['timestamp']->format('M j, Y g:i:s A') }}</span>
                </div>
                <p class="mt-1.5 ml-1 pl-3 border-l-2 border-surface-100 text-xs text-surface-500">{{ $event['passes_detail'] }}</p>
            @else
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold mr-1.5
                            {{ \App\Services\DocumentMovementTimeline::CATEGORY_CLASSES[$event['category']] ?? \App\Services\DocumentMovementTimeline::CATEGORY_CLASSES['config'] }}">
                            {{ $event['label'] }}
                        </span>
                        <span class="text-surface-700 font-medium">{{ $event['actor'] }}</span>
                        @if($event['note'])
                            <span class="text-surface-500">— {{ $event['note'] }}</span>
                        @endif
                    </div>
                    <span class="shrink-0 text-xs text-surface-400">{{ $event['timestamp']->format('M j, Y g:i:s A') }}</span>
                </div>
            @endif
        </li>
    @empty
        <li class="px-4 py-6 text-center text-sm text-surface-400">No recorded activity yet.</li>
    @endforelse
</ul>
