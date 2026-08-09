@props(['document', 'highlightAssignmentId' => null])

{{--
    Full workflow-stage pipeline for this document's category (Feature:
    show every stage, not just whichever one currently "pops up"). Every
    configured stage for the category is listed and its state derived from
    however many assignment rows (seats — one per eligible approver, see
    WorkflowService::assignStage()) exist for it:
      - no assignment rows yet                  -> "upcoming" (not yet reached)
      - any seat rejected                       -> "rejected"
      - zero seats pending, none rejected        -> "approved"/"auto_approved"
      - otherwise (1+ seats still pending)       -> "pending", with a
        "N of M approved" progress line once a stage has more than one seat.

    Highlighting (Feature: approver can see which stages are theirs at a
    glance): when the viewer is an approver, their own pending seat(s) for
    this document are highlighted in primary blue instead of the neutral
    orange used for other approvers' pending stages. If they hold pending
    seats on more than one stage, only the earliest (lowest sequence_order)
    is marked "Your turn" — that's the one the action button elsewhere on
    the page acts on. Their other stage(s) are marked "Up next" — visible,
    but not yet actionable until the current one resolves.

    Explicit override (Feature: Admin SLA Override Queue): the viewer isn't
    the assigned approver, so the auth-based highlighting above never
    fires. Pass $highlightAssignmentId to force the ONE SPECIFIC SEAT that
    assignment belongs to into the same highlighted style (assignment-
    scoped, not stage-scoped, since a stage can now have some escalated
    seats and some non-escalated seats at once) — labeled "Escalated"
    rather than "Your turn" since it isn't the viewer's own assignment.
--}}
@php
    // A document with no ml_category yet (still processing, or extraction/
    // classification never completed) has no workflow pipeline to show —
    // WorkflowStage::forCategory() requires a non-null category string.
    $allStages = $document->ml_category
        ? \App\Models\WorkflowStage::forCategory($document->ml_category)->get()
        : collect();
    $assignmentsByStage = $document->assignments->groupBy('stage_id');
    $currentUserId = auth()->id();

    $myActiveStageId = $document->assignments
        ->where('individual_status', 'pending')
        ->where('user_id', $currentUserId)
        ->sortBy(fn ($a) => $a->stage->sequence_order)
        ->pluck('stage_id')
        ->first();

    // "Opened <date>" / "Reviewed <pass 1>, <pass 2>, … — <total>" block on
    // a resolved seat — shared formatting for both the single-seat and
    // multi-seat branches below. Each review pass is listed with its own
    // span rather than collapsed into one outer from/to range, since a
    // second pass often starts well after the first one ended and a
    // single range would silently count that gap as if it were reviewing
    // time.
    $fmtDuration = function (int $seconds) {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
    };
    $reviewSummaryFor = function (int $approverId) use ($document, $fmtDuration) {
        $sessions = \App\Models\DocumentReviewSession::closedSessionsFor($document->document_id, $approverId);
        if ($sessions->isEmpty()) {
            return null;
        }

        $totalSeconds = (int) $sessions->sum('duration_seconds');

        $segments = $sessions->map(function ($session) use ($fmtDuration) {
            $from = $session->opened_at->format('g:i A');
            $to = $session->closed_at->isSameDay($session->opened_at)
                ? $session->closed_at->format('g:i A')
                : $session->closed_at->format('M j, g:i A');
            return "{$from}–{$to} ({$fmtDuration($session->duration_seconds ?? 0)})";
        })->implode(', ');

        return [
            'opened' => $sessions->first()->opened_at,
            'segments' => $segments,
            'total' => $fmtDuration($totalSeconds),
        ];
    };
@endphp

@if(!$document->ml_category)
    <div class="rounded-lg border border-surface-200 bg-surface-50 p-3 text-xs text-surface-500">
        This document hasn't been classified yet, so it has no workflow stages to show.
        @if($document->validation_errors)
            See the validation issue below for why.
        @endif
    </div>
@else
@php
    // Statistical estimate only — see ApprovalForecastService's docblock
    // for why this isn't a trained model yet. Omitted once the document
    // is fully resolved (nothing left to estimate) or when there's no
    // historical data yet for this category.
    $forecast = in_array($document->global_status, ['approved', 'auto_approved', 'rejected'])
        ? null
        : app(\App\Services\ApprovalForecastService::class)->estimateFor($document);
@endphp
@if($forecast)
    @php
        // Business-hours-aware, not plain wall-clock addition — the same
        // helper every sla_expires_at deadline already uses, so this can
        // never claim an approval will land at 8 PM or on a Sunday.
        $estApprovalBy = app(\App\Services\BusinessHoursService::class)
            ->addBusinessMinutes(now(), (int) ceil($forecast->totalSeconds / 60));
    @endphp
    <p class="text-[11px] text-surface-500 mb-2">
        <span class="font-medium text-surface-600">Est. Approval by:</span> {{ $estApprovalBy->format('M j, Y, g:i A') }}
    </p>
@endif
<div class="space-y-2">
    @foreach($allStages as $stage)
        @php
            // Withdrawn seats (deactivated approver, already covered by a
            // sibling — see WorkflowService::withdrawAssignment()) are
            // excluded here entirely rather than shown resolved: there's
            // nothing to report for them, the remaining seat(s) already
            // fully represent this stage's outcome. They still exist in
            // the audit trail/movement timeline for history.
            $stageAssignments = $assignmentsByStage->get($stage->stage_id, collect())
                ->reject(fn ($a) => $a->individual_status === 'withdrawn');
            $totalSeats = $stageAssignments->count();
            $approvedSeats = $stageAssignments->whereIn('individual_status', ['approved', 'auto_approved']);
            $rejectedSeats = $stageAssignments->where('individual_status', 'rejected');
            $pendingSeats = $stageAssignments->where('individual_status', 'pending');

            $state = match(true) {
                $stageAssignments->isEmpty() => 'upcoming',
                $rejectedSeats->isNotEmpty() => 'rejected',
                $pendingSeats->isEmpty() => ($approvedSeats->contains(fn ($a) => $a->auto_approved) ? 'auto_approved' : 'approved'),
                default => 'pending',
            };

            $myAssignment = $pendingSeats->first(fn ($a) => $a->user_id === $currentUserId);
            $isMine = (bool) $myAssignment;
            $isForcedHighlight = $highlightAssignmentId !== null && $stageAssignments->contains('assignment_id', $highlightAssignmentId);
            $isMyActive = ($isMine && $stage->stage_id === $myActiveStageId) || $isForcedHighlight;

            // For "single seat" stages, keep referring to that one row the
            // same way the component always has — most stages only have
            // one eligible approver, and this keeps that common case
            // visually identical to before.
            $soleAssignment = $totalSeats === 1 ? $stageAssignments->first() : null;
        @endphp
        <div class="flex items-start gap-3 rounded-xl border p-3 transition-colors
            @if($isMyActive) border-primary-500 bg-primary-50 ring-1 ring-inset ring-primary-500/40 shadow-sm
            @elseif($isMine) border-primary-200 bg-primary-50/40
            @elseif($state === 'pending') border-processing-500/30 bg-processing-50/40
            @else border-surface-200 @endif">
            <div class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold flex-shrink-0 shadow-sm mt-0.5
                @if(in_array($state, ['approved', 'auto_approved'])) bg-gradient-to-br from-approved-500 to-approved-600 text-white
                @elseif($state === 'rejected') bg-gradient-to-br from-rejected-500 to-rejected-600 text-white
                @elseif($isMyActive) bg-gradient-to-br from-primary-500 to-primary-700 text-white
                @elseif($state === 'pending') bg-gradient-to-br from-processing-500 to-processing-600 text-white
                @else bg-surface-200 text-surface-400 shadow-none @endif">
                @if(in_array($state, ['approved', 'auto_approved']))
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                @elseif($state === 'rejected')
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                @else
                    {{ $stage->sequence_order }}
                @endif
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-medium text-surface-800 flex items-center gap-1.5 flex-wrap">
                    {{ $stage->stage_name }}
                    @if($isForcedHighlight)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rejected-100 text-rejected-700">Escalated</span>
                    @elseif($isMyActive)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold text-gray-600">Your turn</span>
                    @elseif($isMine)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-primary-100 text-primary-700">Up next</span>
                    @endif
                    @if($totalSeats > 1 && $state === 'pending')
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-surface-100 text-surface-600">{{ $approvedSeats->count() }} of {{ $totalSeats }} approved</span>
                    @endif
                    @if($stageAssignments->contains(fn ($a) => $a->reassigned_from))
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-processing-100 text-processing-700">Reassigned</span>
                    @endif
                </p>

                @if($state === 'upcoming')
                    <p class="text-[11px] text-surface-400">Not yet reached</p>
                @elseif($totalSeats <= 1)
                    {{-- Single-seat stage: identical wording to before the
                         multi-approver redesign. --}}
                    <p class="text-[11px] text-surface-400">
                        @if($state === 'pending' && $soleAssignment->needs_approver)
                            Awaiting decision &middot; <span class="text-processing-700 font-medium">Admin</span> (no approver eligible for this category/stage)
                        @elseif($state === 'pending' && $soleAssignment->reassigned_from)
                            Reassigned from {{ $soleAssignment->reassignedFrom->full_name ?? 'a deactivated account' }}
                        @elseif($state === 'pending')
                            @php $lastOpened = $soleAssignment->approver ? \App\Models\DocumentReviewSession::openedAtFor($document->document_id, $soleAssignment->user_id) : null; @endphp
                            Awaiting decision{{ $soleAssignment->approver ? ' · ' . $soleAssignment->approver->full_name : '' }}
                            @if($soleAssignment->approver)
                                &middot;
                                @if($lastOpened)
                                    opened {{ $lastOpened->format('M j, Y, g:i A') }}
                                @else
                                    not yet reviewed
                                @endif
                            @endif
                        @elseif($soleAssignment->auto_approved)
                            Auto-approved by the system
                        @else
                            {{ ucfirst($state) }}{{ $soleAssignment->approver ? ' by ' . $soleAssignment->approver->full_name : '' }}
                            @if($soleAssignment->acted_at) &middot; {{ $soleAssignment->acted_at->format('M j, Y, g:i A') }} @endif
                        @endif
                    </p>
                    @if($soleAssignment && $soleAssignment->comments)
                        <div class="mt-1 rounded-md bg-surface-50 border border-surface-100 px-2 py-1 text-[11px]">
                            <span class="font-semibold text-surface-600">{{ $soleAssignment->individual_status === 'rejected' ? 'Rejected Due To:' : 'Comments:' }}</span>
                            <span class="text-surface-600">{{ $soleAssignment->comments }}</span>
                        </div>
                    @endif
                    @if($soleAssignment && $soleAssignment->reassignment_reason)
                        <p class="text-[11px] text-surface-500 mt-0.5 italic">Reassignment reason: "{{ $soleAssignment->reassignment_reason }}"</p>
                    @endif
                    @if($soleAssignment && in_array($state, ['approved', 'auto_approved', 'rejected']) && $soleAssignment->approver)
                        @php $reviewSummary = $reviewSummaryFor($soleAssignment->user_id); @endphp
                        @if($reviewSummary)
                            <p class="text-[11px] text-surface-500 mt-0.5">Opened {{ $reviewSummary['opened']->format('M j, Y, g:i A') }}</p>
                            <p class="text-[11px] text-surface-500">
                                Reviewed {{ $reviewSummary['segments'] }} &mdash; {{ $reviewSummary['total'] }} total
                            </p>
                        @endif
                    @endif
                @else
                    {{-- Multi-seat stage: list who's still pending, then one
                         line per resolved seat with its own outcome/comment
                         — with several approvers, whose decision/comment is
                         whose is no longer implicit from context. --}}
                    @if($state === 'pending')
                        <p class="text-[11px] text-surface-400">
                            Awaiting decision from
                            @foreach($pendingSeats as $seat)
                                @if($seat->needs_approver)
                                    <span class="text-processing-700 font-medium">Admin</span> (no approver eligible for this category/stage){{ !$loop->last ? ', ' : '' }}
                                @else
                                    @php $lastOpened = \App\Models\DocumentReviewSession::openedAtFor($document->document_id, $seat->user_id); @endphp
                                    <span class="text-surface-500">{{ $seat->approver->full_name ?? 'a deactivated account' }}</span>@if($seat->approver) ({{ $lastOpened ? 'opened ' . $lastOpened->format('M j, Y, g:i A') : 'not yet reviewed' }})@endif{{ !$loop->last ? ', ' : '' }}
                                @endif
                            @endforeach
                        </p>
                    @elseif($state === 'rejected')
                        <p class="text-[11px] text-surface-400">Rejected</p>
                    @else
                        <p class="text-[11px] text-surface-400">
                            {{ ucfirst($state) === 'Auto_approved' ? 'Auto-approved by the system' : 'Approved' }} — all {{ $totalSeats }} approvers signed off
                        </p>
                    @endif
                    @foreach($stageAssignments->whereIn('individual_status', ['approved', 'rejected', 'auto_approved']) as $seat)
                        <p class="text-[11px] text-surface-500 mt-0.5">
                            <span class="font-medium">{{ $seat->approver->full_name ?? 'a deactivated account' }}</span>:
                            {{ $seat->auto_approved ? 'auto-approved' : ucfirst($seat->individual_status) }}
                            @if($seat->acted_at) &middot; {{ $seat->acted_at->format('M j, Y, g:i A') }} @endif
                        </p>
                        @if($seat->comments)
                            <div class="mt-1 rounded-md bg-surface-50 border border-surface-100 px-2 py-1 text-[11px]">
                                <span class="font-semibold text-surface-600">{{ $seat->individual_status === 'rejected' ? 'Rejected Due To:' : 'Comments:' }}</span>
                                <span class="text-surface-600">{{ $seat->comments }}</span>
                            </div>
                        @endif
                        @if($seat->approver)
                            @php $reviewSummary = $reviewSummaryFor($seat->user_id); @endphp
                            @if($reviewSummary)
                                <p class="text-[11px] text-surface-500">Opened {{ $reviewSummary['opened']->format('M j, Y, g:i A') }}</p>
                                <p class="text-[11px] text-surface-500">
                                    Reviewed {{ $reviewSummary['segments'] }} &mdash; {{ $reviewSummary['total'] }} total
                                </p>
                            @endif
                        @endif
                    @endforeach
                    @foreach($stageAssignments->filter(fn ($a) => $a->reassignment_reason) as $seat)
                        <p class="text-[11px] text-surface-500 mt-0.5 italic">
                            {{ $seat->approver->full_name ?? 'Unknown' }} reassignment reason: "{{ $seat->reassignment_reason }}"
                        </p>
                    @endforeach
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
