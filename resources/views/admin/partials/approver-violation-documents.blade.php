{{-- Approver's own violated documents — fetched by openKpiDrilldown() (see components/kpi-drilldown-modal.blade.php) when their row is clicked on the SLA Violations page's Approvers table. Same table pattern as dashboard-drilldown-documents.blade.php. Grouped by document (see AdminController::approverViolationDocuments()) — a document violated on more than one stage shows once, with its stages joined. --}}
<div class="px-6 py-2.5 border-b border-surface-200 bg-surface-50/60">
    <p class="text-sm text-surface-500">{{ $violations->count() }} document{{ $violations->count() === 1 ? '' : 's' }}</p>
</div>
<div class="overflow-y-auto">
    <table class="w-full text-sm table-fixed">
        <thead class="bg-white sticky top-0 border-b border-surface-200">
            <tr class="text-left text-sm text-surface-500 font-medium">
                <th class="px-6 py-2 w-[40%]">Document</th>
                <th class="px-4 py-2 w-[35%]">Stage</th>
                <th class="px-4 py-2 w-[25%]">Violated</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($violations as $item)
                <tr class="hover:bg-surface-50/60">
                    <td class="px-6 py-2.5 font-medium text-surface-800 truncate">{{ $item->document->title ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-surface-600">{{ $item->stages->implode(' | ') }}</td>
                    <td class="px-4 py-2.5 text-surface-500">{{ $item->latestViolatedAt->format('M j, Y g:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-6 py-10 text-center text-sm text-surface-400">No violations recorded for this approver.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
