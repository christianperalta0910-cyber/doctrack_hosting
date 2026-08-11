{{--
    Unassigned Documents results — split out from unassigned_documents.blade.php
    so the same markup can be rendered two ways: a normal full page load, and
    a fragment returned by AdminController::unassignedDocumentsRefresh() for
    the live-poll JS to swap in place, without a full page reload.
--}}
<div id="unassigned-results" class="space-y-6">
    @forelse($containers as $container)
        @php
            $doc = $container->document;
            // Only the earliest still-pending stage gets an actionable
            // panel — same "work through it one at a time" pattern the
            // Approver Queue uses (see approver/partials/queue.blade.php),
            // rather than every stuck stage getting its own Approve/Reject
            // box at once. $container->assignments is already sorted by
            // stage sequence order (see AdminController::
            // unassignedDocumentsData()). The OTHER stuck stages are still
            // visible above in the pipeline view, just not actionable yet.
            $assignment = $container->assignments->first();

            // Urgent/Normal/Low/Expired — identical array/order/colors to
            // the Approver Queue's own $urgencyStyles (see approver/
            // partials/queue.blade.php), so the badge means the same thing
            // and looks the same wherever it appears.
            $urgencyStyles = [
                1 => ['Urgent', 'bg-rejected-50 text-rejected-700 ring-rejected-500/20'],
                2 => ['Normal', 'bg-processing-50 text-processing-700 ring-processing-500/20'],
                3 => ['Low', 'bg-surface-100 text-surface-600 ring-surface-300'],
                4 => ['Expired', 'bg-rejected-100 text-rejected-800 ring-rejected-500/40'],
            ];
            [$urgencyLabel, $urgencyClass] = $urgencyStyles[$assignment->urgencyRank()];

            // Real (business-hours-aware) countdown — identical calculation
            // and live-tick mechanism to the Approver Queue's own
            // $realSecondsRemaining/$realRemainingLabel, reusing the same
            // shared [data-real-remaining] script in layouts/app.blade.php
            // rather than the plain wall-clock diffForHumans() this used
            // before (which, like every other raw wall-clock diff in this
            // app, can look misleadingly long across an evening/weekend).
            $realSecondsRemaining = $assignment->realSecondsRemaining();
            if ($realSecondsRemaining <= 0) {
                $realRemainingLabel = 'expired';
            } else {
                $rh = intdiv($realSecondsRemaining, 3600);
                $rm = intdiv($realSecondsRemaining % 3600, 60);
                $realRemainingLabel = $rh > 0 ? "{$rh}h {$rm}m remaining" : "{$rm}m remaining";
            }
        @endphp
        <div class="rounded-xl border border-processing-500/20 bg-white shadow-card overflow-hidden">
            <div class="p-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-processing-50 text-processing-700 ring-1 ring-inset ring-processing-500/20">Needs Approver</span>
                    {{-- Same field, same format, same position as the
                         Approver Queue's document-level "Due" line. --}}
                    <span class="text-xs font-medium {{ $doc->due_date && $doc->due_date->isPast() ? 'text-rejected-700' : 'text-surface-600' }}">
                        Due {{ $doc->due_date?->format('M j, Y g:i A') ?? '—' }}
                    </span>
                </div>
                <h3 class="text-sm font-semibold text-surface-900 mb-1">{{ $doc->title }}</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Category: {{ $doc->ml_category }} · Submitted by {{ $doc->originator->full_name ?? 'Unknown' }} ·
                    <button type="button"
                        onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}', {{ $doc->document_id }})"
                        class="text-primary-700 hover:underline font-medium">View original file</button>
                </p>

                <div class="mb-4">
                    <x-workflow-stage-list :document="$doc" :highlight-assignment-id="$assignment->assignment_id" />
                </div>

                <div class="rounded-lg border border-processing-500/30 bg-processing-50/40 p-4">
                    <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                        <div class="flex-1 min-w-0">
                            {{-- Badge sits next to "Stage: X" here, same
                                 position as the Approver Queue's own active-
                                 assignment panel, not up with the document-
                                 level "Needs Approver" pill. --}}
                            <div class="flex items-center gap-2 mb-1">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ring-1 ring-inset {{ $urgencyClass }}">{{ $urgencyLabel }}</span>
                                <span class="text-xs text-surface-500 font-medium">Stage: {{ $assignment->stage->stage_name }}</span>
                            </div>
                            <p class="text-xs text-surface-500">
                                No approver is currently eligible for this category/stage
                                @if($assignment->reassignedFrom)
                                    — {{ $assignment->reassignedFrom->full_name }}'s account was deactivated
                                    @if($assignment->reassignment_reason)
                                        (<span class="italic">"{{ $assignment->reassignment_reason }}"</span>)
                                    @endif
                                @endif
                                @if($assignment->needs_approver_at)
                                    &middot; since <span class="font-semibold text-surface-600">{{ $assignment->needs_approver_at->format('M j, Y, g:i A') }}</span>
                                    (<span data-live-time="{{ $assignment->needs_approver_at->timestamp }}">{{ $assignment->needs_approver_at->diffForHumans() }}</span>)
                                @endif
                            </p>
                            {{-- Same "SLA expires [date] ([live countdown])"
                                 structure as the Approver Queue's own panel —
                                 see that file's $realSecondsRemaining/
                                 $realRemainingLabel for the identical
                                 calculation this reuses. --}}
                            <p class="text-xs text-surface-400 mt-1">
                                SLA expires
                                <span class="font-semibold text-surface-600">{{ $assignment->sla_expires_at?->format('M j, Y, g:i A') ?? '—' }}</span>
                                <span class="font-semibold {{ $realSecondsRemaining < 3600 ? 'text-rejected-700' : 'text-surface-600' }}"
                                      data-real-remaining="{{ max(0, $realSecondsRemaining) }}"
                                      data-live-urgent-under="3600">
                                    ({{ $realRemainingLabel }})
                                </span>
                                before this escalates to the SLA Override Queue.
                            </p>
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
