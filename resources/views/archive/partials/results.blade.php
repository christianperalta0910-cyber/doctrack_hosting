{{--
    Extracted from archive/index.blade.php so ArchiveController::refresh()
    can return exactly this fragment for the live-search JS to swap in,
    without re-rendering the whole page (filter bar, sidebar, layout).
    Expects: $documents, $isOwnSubmissionsView.
--}}
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-semibold text-surface-900">Approved Documents</h2>
            @if($isOwnSubmissionsView)
                <p class="text-xs text-surface-400 mt-0.5">Showing only documents you submitted, across all categories.</p>
            @endif
        </div>
        <span class="text-xs text-surface-400">{{ $documents->total() }} total</span>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-4 py-3 font-medium">Document</th>
                <th class="text-left px-4 py-3 font-medium">Category</th>
                <th class="text-left px-4 py-3 font-medium">Originator</th>
                <th class="text-left px-4 py-3 font-medium">Uploaded</th>
                <th class="text-left px-4 py-3 font-medium">Approved</th>
                <th class="text-left px-4 py-3 font-medium">Due Date</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($documents as $doc)
                @php
                    // Legacy-imported documents (see ArchiveController::storeLegacy())
                    // never get real DocumentAssignment rows — they skip the
                    // workflow entirely — so there's no "last approver" to
                    // anchor on; falls back to the row's own updated_at for
                    // those specifically.
                    $approvedAt = $doc->assignments
                        ->whereIn('individual_status', ['approved', 'auto_approved'])
                        ->max('acted_at') ?? $doc->updated_at;
                @endphp
                <tr class="hover:bg-surface-50 transition-colors cursor-pointer"
                    onclick="document.getElementById('archive-movements-{{ $doc->document_id }}').classList.toggle('hidden'); this.querySelector('.archive-expand-icon').classList.toggle('rotate-90')">
                    <td class="px-4 py-3 font-medium text-surface-800 max-w-xs truncate">
                        <svg class="archive-expand-icon inline-block w-3 h-3 mr-1 text-surface-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        {{ $doc->title }}
                        @if($doc->is_legacy_import)
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-processing-50 text-processing-700 align-middle">Imported</span>
                        @endif
                        @if($doc->disputed_at)
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-rejected-50 text-rejected-700 align-middle">Disputed</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-surface-600">{{ $doc->ml_category }}</td>
                    <td class="px-4 py-3 text-surface-500">{{ $doc->originator->full_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-surface-500 whitespace-nowrap">{{ $doc->upload_date?->format('M j, Y, g:i A') ?? '—' }}</td>
                    <td class="px-4 py-3 text-surface-500 whitespace-nowrap">{{ $approvedAt?->format('M j, Y, g:i A') ?? '—' }}</td>
                    <td class="px-4 py-3 text-surface-500 whitespace-nowrap">{{ $doc->due_date?->format('M j, Y, g:i A') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1.5 flex-wrap">
                            @if($doc->requires_printing)
                                @if(auth()->user()->isOriginator())
                                    <button type="button"
                                        onclick="event.stopPropagation(); openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}', {{ $doc->document_id }}, true)"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors whitespace-nowrap cursor-pointer">
                                        🖨 Print Required
                                    </button>
                                @else
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-50 text-indigo-700 whitespace-nowrap">🖨 Print Required</span>
                                @endif
                            @endif
                            <button type="button"
                                onclick="event.stopPropagation(); openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}', {{ $doc->document_id }}, false)"
                                class="inline-flex items-center border border-surface-300 text-surface-600 hover:bg-surface-50 font-medium text-xs px-2.5 py-1 rounded-lg transition-colors whitespace-nowrap">
                                View
                            </button>
                            <a href="{{ route('archive.download', $doc) }}" onclick="event.stopPropagation()"
                                class="inline-flex items-center bg-primary-700 hover:bg-primary-800 text-white font-medium text-xs px-2.5 py-1 rounded-lg transition-colors whitespace-nowrap">
                                Download &darr;
                            </a>
                        </div>
                    </td>
                </tr>
                <tr id="archive-movements-{{ $doc->document_id }}" class="hidden bg-surface-50/60">
                    <td colspan="7" class="px-6 py-3">
                        <div class="border border-surface-200 rounded-lg overflow-hidden bg-white">
                            <x-document-movement-timeline :document="$doc" />
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-10 text-center text-surface-400 text-sm">No archived documents match your search.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if($documents->hasPages())
        <div class="px-6 py-4 border-t border-surface-200">{{ $documents->links() }}</div>
    @endif
</div>
