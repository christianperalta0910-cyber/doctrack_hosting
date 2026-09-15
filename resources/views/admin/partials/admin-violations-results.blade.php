{{--
    Admin Violations results — split out so this can be rendered two ways:
    inline on the full sla_violations.blade.php load, and as a fragment
    for the live-poll JS to swap in place. Scoped to whichever category
    folder this is rendered inside (see AdminController::
    adminViolationsData()).

    One row per DOCUMENT, not per violation — the stage(s) where a
    violation happened are listed underneath the title, and the single
    Open/Resolved badge reflects whether ANY of that document's stages
    still needs the one Confirm/Dispute action on the Auto-Approval
    Review page (that action reviews every pending stage on a document
    at once, so one badge per document is what actually matches it).
--}}
<div class="px-6 py-3 border-b border-surface-200 bg-surface-50/50">
    <p class="text-sm text-surface-500">{{ $adminViolationTotal }} total in this category — a stage that had no eligible approver, an auto-approved document that wasn't reviewed in time, or a low-confidence classification that wasn't confirmed in time.</p>
</div>

<ul class="divide-y divide-surface-100">
    @forelse($adminViolations as $item)
        <li class="px-6 py-3">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm font-medium text-surface-800 truncate">{{ $item->document->title ?? '—' }}</p>
                <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $item->isOpen ? 'bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20' : 'bg-approved-50 text-approved-700 ring-1 ring-inset ring-approved-500/20' }}">
                    {{ $item->isOpen ? 'Open' : 'Resolved' }}
                </span>
            </div>
            <p class="text-sm text-surface-500 mt-0.5">{{ $item->stages->implode(', ') }}</p>
            {{-- Live-ticking "ago" only while still open — once resolved,
                 freezing it as a plain string avoids it reading as "still
                 happening" right next to a Resolved badge. --}}
            <p class="text-xs text-surface-400 mt-0.5">
                @if($item->isOpen)
                    Flagged {{ $item->firstViolatedAt->format('M j, Y g:i A') }} (<span data-live-time="{{ $item->firstViolatedAt->timestamp }}">{{ $item->firstViolatedAt->diffForHumans() }}</span>)
                @else
                    Flagged {{ $item->firstViolatedAt->format('M j, Y g:i A') }} ({{ $item->firstViolatedAt->diffForHumans() }})
                @endif
            </p>
        </li>
    @empty
        <li class="px-6 py-8 text-center text-sm text-surface-400">No Admin violations recorded.</li>
    @endforelse
</ul>

@if($adminViolations->hasPages())
    <div class="px-6 py-3 border-t border-surface-100">{{ $adminViolations->links() }}</div>
@endif
