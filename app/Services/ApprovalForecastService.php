<?php

namespace App\Services;

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Carbon\CarbonInterval;

/**
 * A statistical estimate, not a trained model — there isn't enough
 * historical throughput yet in this system for a genuine regression to
 * mean anything more than noise dressed up as a number. This averages past
 * decision times for the document's category and scales by how many
 * stages are left plus how backed up the next stage's approvers already
 * are. Upgradeable to a real php-ai/php-ml regression later once there's
 * enough historical volume to train on.
 */
class ApprovalForecastService
{
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

        // Averaged in PHP rather than via a DB-side date-diff function —
        // MySQL's TIMESTAMPDIFF has no portable equivalent on the sqlite
        // driver the test suite runs on, and the historical dataset this
        // averages over is small enough that pulling it into PHP costs
        // nothing.
        // Selected as rows, not plucked into a created_at-keyed map — sibling
        // seats on the same stage batch share an identical created_at (see
        // WorkflowService::assignStage()), and a keyed pluck would silently
        // collapse those duplicates down to one.
        $decisions = DocumentAssignment::query()
            ->join('document_repository', 'document_assignments.document_id', '=', 'document_repository.document_id')
            ->where('document_repository.ml_category', $document->ml_category)
            ->whereNotNull('document_assignments.acted_at')
            ->get(['document_assignments.created_at', 'document_assignments.acted_at']);

        if ($decisions->isEmpty()) {
            return null; // no historical decisions for this category yet
        }

        $avgSecondsPerStage = $decisions->avg(
            fn ($row) => \Carbon\Carbon::parse($row->created_at)->diffInSeconds(\Carbon\Carbon::parse($row->acted_at))
        );

        $stages = WorkflowStage::forCategory($document->ml_category)->where('is_archived', false)->get();
        if ($stages->isEmpty()) {
            return null;
        }

        $nextStage = $this->nextUnresolvedStage($document, $stages);
        if (!$nextStage) {
            return null; // every stage already resolved
        }

        $remainingStageCount = $stages->where('sequence_order', '>=', $nextStage->sequence_order)->count();

        // Unanimous approval means the stage waits on its SLOWEST eligible
        // approver, not its fastest — so the queue-depth padding uses the
        // most backed-up approver among the next stage's eligible pool.
        $queueDepth = $this->eligibleApproversFor($document, $nextStage)
            ->map(fn (User $approver) => DocumentAssignment::where('user_id', $approver->user_id)
                ->where('individual_status', 'pending')
                ->where('document_id', '!=', $document->document_id)
                ->count())
            ->max() ?? 0;

        $totalSeconds = $avgSecondsPerStage * ($remainingStageCount + $queueDepth);

        return CarbonInterval::seconds((int) round($totalSeconds));
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
