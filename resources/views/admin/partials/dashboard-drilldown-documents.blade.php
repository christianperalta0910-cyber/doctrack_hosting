{{-- KPI card drill-down: document list fragment, fetched by openKpiDrilldown() (see components/kpi-drilldown-modal.blade.php) --}}
<div class="px-6 py-2.5 border-b border-surface-200 bg-surface-50/60">
    <p class="text-xs text-surface-500">Showing {{ $documents->count() }} of {{ $total }}</p>
</div>
<div class="overflow-y-auto">
    <table class="w-full text-sm">
        <thead class="bg-white sticky top-0 border-b border-surface-200">
            <tr class="text-left text-xs text-surface-500 font-medium">
                <th class="px-6 py-2">Title</th>
                <th class="px-4 py-2">Category</th>
                <th class="px-4 py-2">Originator</th>
                <th class="px-4 py-2">Uploaded</th>
                <th class="px-4 py-2">Status</th>
                @if($decisions)
                    <th class="px-4 py-2">{{ $label === 'Rejected' ? 'Rejected By' : 'Approved By' }}</th>
                    <th class="px-4 py-2">Decided At</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($documents as $doc)
                <tr class="hover:bg-surface-50/60">
                    <td class="px-6 py-2.5 font-medium text-surface-800 truncate max-w-xs">{{ $doc->title }}</td>
                    <td class="px-4 py-2.5 text-surface-600">{{ $doc->ml_category ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-surface-600">{{ $doc->originator->full_name ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-surface-500 whitespace-nowrap">{{ $doc->upload_date?->format('M j, Y g:i A') }}</td>
                    <td class="px-4 py-2.5"><x-status-badge :status="$doc->global_status" /></td>
                    @if($decisions)
                        @php $decision = $decisions[$doc->document_id]; @endphp
                        <td class="px-4 py-2.5 text-surface-600">{{ $decision['by'] }}</td>
                        <td class="px-4 py-2.5 text-surface-500 whitespace-nowrap">{{ $decision['at']?->format('M j, Y g:i A') ?? '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $decisions ? 7 : 5 }}" class="px-6 py-10 text-center text-sm text-surface-400">No documents in this category yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
