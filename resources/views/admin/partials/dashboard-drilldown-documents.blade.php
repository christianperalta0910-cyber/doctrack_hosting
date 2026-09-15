{{-- KPI card drill-down: document list fragment, fetched by openKpiDrilldown() (see components/kpi-drilldown-modal.blade.php) --}}
<div class="px-6 py-2.5 border-b border-surface-200 bg-surface-50/60">
    <p class="text-xs text-surface-500">Showing {{ $documents->count() }} of {{ $total }}</p>
</div>
<div class="overflow-y-auto">
    {{--
        table-fixed + percentage widths on the header row — plain
        table-layout:auto let long titles/status labels push the whole
        table wider than the modal, forcing a horizontal scrollbar even
        though there was room to just wrap/truncate instead. Percentages
        (not fixed px) so this degrades gracefully whether the decisions
        columns are present (approved/rejected) or not (pending/total) —
        they just sum to less than 100% in that case, leaving blank space
        on the right rather than ever overflowing. Only Title truncates to
        one line (matches its original single-line intent for long
        filenames); every other column wraps instead of truncating or
        forcing nowrap — under table-fixed that grows the ROW, never the
        column, so nothing needs to be cut off just to avoid a horizontal
        scrollbar.
    --}}
    <table class="w-full text-sm table-fixed">
        <thead class="bg-white sticky top-0 border-b border-surface-200">
            <tr class="text-left text-xs text-surface-500 font-medium">
                {{-- Status is 24% — measured, not guessed: the longest
                     real label ("Auto-Approved — Pending Review") needs
                     ~241px unwrapped, so anything much narrower forces it
                     onto 2-3 cramped lines regardless of table-fixed. --}}
                <th class="px-6 py-2 w-[16%]">Title</th>
                <th class="px-4 py-2 w-[8%]">Category</th>
                <th class="px-4 py-2 w-[8%]">Originator</th>
                <th class="px-4 py-2 w-[13%]">Uploaded</th>
                <th class="px-4 py-2 w-[24%] whitespace-nowrap">Status</th>
                @if($decisions)
                    <th class="px-4 py-2 w-[15%] whitespace-nowrap">{{ $label === 'Rejected' ? 'Rejected By' : 'Approved By' }}</th>
                    <th class="px-4 py-2 w-[15%] whitespace-nowrap">Decided At</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($documents as $doc)
                <tr class="hover:bg-surface-50/60">
                    <td class="px-6 py-2.5 font-medium text-surface-800 truncate">{{ $doc->title }}</td>
                    <td class="px-4 py-2.5 text-surface-600">{{ $doc->ml_category ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-surface-600">{{ $doc->originator->full_name ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-surface-500">{{ $doc->upload_date?->format('M j, Y g:i A') }}</td>
                    <td class="px-4 py-2.5"><x-status-badge :status="$doc->display_status" class="whitespace-normal leading-snug" /></td>
                    @if($decisions)
                        @php $decision = $decisions[$doc->document_id]; @endphp
                        <td class="px-4 py-2.5 text-surface-600">{{ $decision['by'] }}</td>
                        <td class="px-4 py-2.5 text-surface-500">{{ $decision['at']?->format('M j, Y g:i A') ?? '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $decisions ? 7 : 5 }}" class="px-6 py-10 text-center text-sm text-surface-400">No documents in this category yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
