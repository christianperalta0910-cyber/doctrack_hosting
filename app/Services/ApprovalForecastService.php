<?php

namespace App\Services;

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Carbon\CarbonInterval;

/**
 * A statistical estimate for most of the pipeline, upgraded to a real
 * trained regression (ApprovalTimeMlService) for the immediate next
 * decision once a (category, department) combo has enough real history
 * to train on — see that service's docblock. Stages beyond the immediate
 * next one always use the plain average below, since we don't know in
 * advance which department will end up handling those.
 */
class ApprovalForecastService
{
    public function __construct(
        private BusinessHoursService $businessHours,
        private ApprovalTimeMlService $timeMl,
    ) {
    }

    /**
     * Null whenever there isn't enough signal to say anything useful: no
     * category yet, no historical decisions for that category, or the
     * document has nothing left to approve.
     */
    public function estimateFor(DocumentRepository $document): ?CarbonInterval
    {
        if (!$document->ml_category) {
            return null;
        }

        // A custom-routed document (Feature: originator-directed routing
        // — see WorkflowService::routeToCustomApprovers()) never went
        // through the category's own pipeline, so there's no "typical
        // processing time for this category" to estimate against —
        // averaging in that history would be meaningless at best, and at
        // worst would mix in unrelated one-off stages from OTHER
        // documents that happen to share the same guessed category (this
        // query joins on ml_category alone, with nothing scoping it to
        // real configured stages only). It already has a known, exact
        // deadline instead of a statistical guess — use that directly.
        // Checked against the two explicit opt-out values, not "!== 'auto'"
        // — desired_routing defaults to 'auto' at the DB level, but a
        // freshly create()'d model in memory (never refetched) reads that
        // column as null rather than the DB default until it's actually
        // reloaded, and null !== 'auto' would otherwise misfire this
        // branch for a perfectly normal document.
        if (in_array($document->desired_routing, ['custom', 'unrelated'], true)) {
            return $this->customRoutingDeadline($document);
        }

        $stages = WorkflowStage::configured()->forCategory($document->ml_category)->where('is_archived', false)->get();
        if ($stages->isEmpty()) {
            return null;
        }

        $nextStage = $this->nextUnresolvedStage($document, $stages);
        if (!$nextStage) {
            return null; // every stage already resolved
        }

        $eligibleApprovers = $this->eligibleApproversFor($document, $nextStage);

        // Scope the historical average to whichever department(s) are
        // actually eligible for the upcoming stage, so a fast department's
        // documents aren't dragged down by averaging in a slower
        // department's history (and vice versa). Falls back to the whole
        // category when department isn't set for any eligible approver,
        // rather than filtering everything out.
        $departments = $eligibleApprovers->pluck('department')->filter()->unique()->values();

        // Averaged in PHP rather than via a DB-side date-diff function —
        // MySQL's TIMESTAMPDIFF has no portable equivalent on the sqlite
        // driver the test suite runs on, and the historical dataset this
        // averages over is small enough that pulling it into PHP costs
        // nothing.
        // Selected as rows, not plucked into a created_at-keyed map — sibling
        // seats on the same stage batch share an identical created_at (see
        // WorkflowService::assignStage()), and a keyed pluck would silently
        // collapse those duplicates down to one.
        $decisionsQuery = DocumentAssignment::query()
            ->join('document_repository', 'document_assignments.document_id', '=', 'document_repository.document_id')
            ->where('document_repository.ml_category', $document->ml_category)
            ->whereNotNull('document_assignments.acted_at')
            // An auto-approval measures how long the SLA deadline happened
            // to be, not how fast a person actually decided — mixing those
            // into the average would drag the "typical" time toward the
            // deadline itself as more documents auto-approve, instead of
            // reflecting real human speed.
            ->where('document_assignments.auto_approved', false);

        if ($departments->isNotEmpty()) {
            $decisionsQuery->join('users', 'document_assignments.user_id', '=', 'users.user_id')
                ->whereIn('users.department', $departments);
        }

        $decisions = $decisionsQuery->get(['document_assignments.created_at', 'document_assignments.acted_at']);

        if ($decisions->isEmpty()) {
            return null; // no historical decisions for this category/department yet
        }

        // Business-hours-aware, not a raw wall-clock diff — otherwise a
        // document that sat untouched over a weekend before a same-morning
        // decision reads as "took 2 days" instead of "took 10 minutes,"
        // same reasoning as every other elapsed-time calculation in this
        // app (see BusinessHoursService).
        $avgSecondsPerStage = $decisions->avg(
            fn ($row) => $this->businessHours->businessSecondsRemaining(
                \Carbon\Carbon::parse($row->created_at),
                \Carbon\Carbon::parse($row->acted_at)
            )
        );

        $remainingStageCount = $stages->where('sequence_order', '>=', $nextStage->sequence_order)->count();

        // Unanimous approval means the stage waits on its SLOWEST eligible
        // approver, not its fastest — so the queue-depth padding uses the
        // most backed-up approver among the next stage's eligible pool.
        $queueDepth = $eligibleApprovers
            ->map(fn (User $approver) => DocumentAssignment::where('user_id', $approver->user_id)
                ->where('individual_status', 'pending')
                ->where('document_id', '!=', $document->document_id)
                ->count())
            ->max() ?? 0;

        // The trained model only ever covers ONE specific (category,
        // department) combo — only usable when the next stage's eligible
        // pool resolves to exactly one department; a stage jointly owned
        // by multiple departments, or with no department set at all,
        // keeps using the plain average for every remaining stage
        // (including the next one), same as before ML existed.
        $mlPrediction = $departments->count() === 1
            ? $this->timeMl->predictNextDecision($document->ml_category, $departments->first(), $eligibleApprovers)
            : null;

        $totalSeconds = $mlPrediction !== null
            // ML's own prediction replaces the average ONLY for the
            // immediate next stage; stages beyond that, plus the queue-depth
            // padding, still use the plain average — ML doesn't know about
            // either of those, so there's nothing to double-count.
            ? $mlPrediction + $avgSecondsPerStage * (($remainingStageCount - 1) + $queueDepth)
            : $avgSecondsPerStage * ($remainingStageCount + $queueDepth);

        return CarbonInterval::seconds((int) round($totalSeconds));
    }

    /**
     * A custom-routed document's "estimate" is just its real deadline —
     * approval needs every hand-picked approver to agree, so the
     * relevant date is whichever of their SLA windows runs longest, not
     * an average of anyone else's. Null while still awaiting the
     * originator's own approver pick (no assignments exist yet — see
     * WorkflowService::routeOrAwaitApproverSelection()) or once every
     * seat has already been decided (nothing left to wait on).
     */
    private function customRoutingDeadline(DocumentRepository $document): ?CarbonInterval
    {
        $latestDeadline = $document->assignments()->where('individual_status', 'pending')->max('sla_expires_at');
        if (!$latestDeadline) {
            return null;
        }

        $seconds = now()->diffInSeconds(\Carbon\Carbon::parse($latestDeadline), false);

        return CarbonInterval::seconds(max(0, $seconds));
    }

    /**
     * The earliest stage (by sequence_order) that still has a pending
     * seat, or the category's first stage if the document hasn't been
     * routed yet at all.
     */
    private function nextUnresolvedStage(DocumentRepository $document, \Illuminate\Support\Collection $stages): ?WorkflowStage
    {
        $pendingStageIds = $document->assignments
            ->where('individual_status', 'pending')
            ->pluck('stage_id');

        if ($pendingStageIds->isNotEmpty()) {
            return $stages->whereIn('stage_id', $pendingStageIds)->sortBy('sequence_order')->first();
        }

        // Not yet routed (no assignments at all) -> the whole pipeline is
        // still ahead of it, starting from the first stage.
        return $document->assignments->isEmpty() ? $stages->sortBy('sequence_order')->first() : null;
    }

    /** Mirrors WorkflowService::eligibleApproversForStage()'s filters. */
    private function eligibleApproversFor(DocumentRepository $document, WorkflowStage $stage): \Illuminate\Support\Collection
    {
        return User::where('role', 'approver')
            ->where('is_active', true)
            ->where('assigned_category', $document->ml_category)
            ->get()
            ->filter(function (User $approver) use ($stage) {
                $assignedStageIds = $approver->workflowStages()->pluck('workflow_stages.stage_id');
                return $assignedStageIds->isEmpty() || $assignedStageIds->contains($stage->stage_id);
            })
            ->values();
    }
}
