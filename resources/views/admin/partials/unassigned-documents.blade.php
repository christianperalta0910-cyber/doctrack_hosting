{{--
    Unassigned Documents results — split out from unassigned_documents.blade.php
    so the same markup can be rendered two ways: a normal full page load, and
    a fragment returned by AdminController::unassignedDocumentsRefresh() for
    the live-poll JS to swap in place, without a full page reload.
--}}
<div id="unassigned-results" class="space-y-6">
    @forelse($containers as $container)
        @php $doc = $container->document; @endphp
        <div class="rounded-xl border border-processing-500/20 bg-white shadow-card overflow-hidden">
            <div class="p-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-processing-50 text-processing-700 ring-1 ring-inset ring-processing-500/20">Needs Approver</span>
                    <span class="text-xs text-surface-500">Uploaded {{ $doc->upload_date?->format('M j, Y g:i A') ?? '—' }}</span>
                </div>
                <h3 class="text-sm font-semibold text-surface-900 mb-1">{{ $doc->title }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Category: {{ $doc->ml_category }} · Submitted by {{ $doc->originator->full_name ?? 'Unknown' }} ·
                    <button type="button"
                        onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}', {{ $doc->document_id }})"
                        class="text-primary-700 hover:underline font-medium">View original file</button>
                </p>

                <div class="mb-4">
                    <x-workflow-stage-list :document="$doc" />
                </div>

                @foreach($container->assignments as $assignment)
                    <div class="rounded-lg border border-processing-500/30 bg-processing-50/40 p-4 {{ !$loop->first ? 'mt-3' : '' }}">
                        <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-surface-700 mb-1">Stage: {{ $assignment->stage->stage_name }}</p>
                                <p class="text-xs text-surface-500">
                                    No approver is currently eligible for this category/stage
                                    @if($assignment->reassignedFrom)
                                        — {{ $assignment->reassignedFrom->full_name }}'s account was deactivated
                                    @endif
                                    @if($assignment->needs_approver_at)
                                        &middot; since <span data-live-time="{{ $assignment->needs_approver_at->timestamp }}">{{ $assignment->needs_approver_at->diffForHumans() }}</span>
                                    @endif
                                </p>
                                @if($assignment->reassignment_reason)
                                    <p class="text-xs text-surface-500 mt-1 italic">Admin note: "{{ $assignment->reassignment_reason }}"</p>
                                @endif
                            </div>

                            <div class="flex flex-col sm:flex-row gap-3 lg:w-auto">
                                <form method="POST" action="{{ route('admin.unassigned.decide', $assignment) }}" class="flex flex-col gap-2 sm:w-72">
                                    @csrf
                                    <textarea name="comments" rows="2" placeholder="Notes (required if rejecting)…"
                                        class="w-full rounded-lg border-surface-300 text-xs focus:border-primary-500 focus:ring-primary-500 px-3 py-2 resize-none"></textarea>
                                    <div class="flex gap-2">
                                        <button type="submit" name="decision" value="approved"
                                            onclick="this.form.querySelector('textarea[name=comments]').required = false"
                                            class="flex-1 bg-approved-500 hover:bg-approved-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                                            Approve
                                        </button>
                                        <button type="submit" name="decision" value="rejected"
                                            onclick="this.form.querySelector('textarea[name=comments]').required = true"
                                            class="flex-1 bg-rejected-500 hover:bg-rejected-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                                            Reject
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
            <p class="text-sm text-surface-500">No unassigned documents — every stage has someone eligible.</p>
        </div>
    @endforelse
    @if($containers->hasPages())
        <div class="bg-white rounded-xl shadow-card border border-surface-200 px-6 py-4">{{ $containers->links() }}</div>
    @endif
</div>
