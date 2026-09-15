<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AdminViolation
 * ---------------
 * The Admin-side counterpart to SlaViolation — both cases represent
 * "Admin, as the fallback-responsible party for this seat, missed a
 * deadline," attributed to the Admin role collectively (this queue isn't
 * individually owned the way an approver's own assignment is), not any
 * one person:
 *
 *   - 'missed_approval': a stage had no eligible approver, Admin was the
 *     fallback approver, and its own deadline passed with nobody having
 *     decided it — so it auto-approved immediately (see SlaService::
 *     escalate()). Always created already resolved (resolved_at =
 *     first_violated_at) since the auto-approval happens in the same
 *     instant the violation does — there's nothing further to wait on.
 *
 *   - 'late_review': an auto-approved stage (either kind above, or a
 *     real approver's own miss) sat past its 6-hour review window
 *     without Admin actually reviewing it. Created the moment that
 *     window first lapses, stays open (resolved_at null) while
 *     unreviewed — notification_count/last_notified_at track the
 *     capped hourly follow-ups (see SlaService::trackLateReviews()) —
 *     and resolves the moment Admin actually confirms/disputes it (see
 *     AdminController::reviewAutoApproval()).
 *
 *   - 'late_ml_review': a low-confidence classification (see
 *     DocumentRepository::ml_review_status) sat past its own 6-hour
 *     review window without Admin confirming/correcting its category.
 *     Same open-until-resolved shape as 'late_review' (see SlaService::
 *     trackLateMlReviews()), just triggered off a document's
 *     ml_review_due_at instead of an assignment's review_due_at — has
 *     no assignment_id or stage_name (both null), since this predates
 *     any stage/seat existing for the document at all. Resolves the
 *     moment Admin actually confirms or rejects it (see
 *     AdminController::reviewFlaggedDocument()). Deliberately never
 *     auto-decides the category once the window passes, only escalates
 *     visibility — the whole point of the review gate is that the
 *     guess shouldn't be trusted unsupervised.
 */
class AdminViolation extends Model
{
    protected $primaryKey = 'violation_id';

    protected $fillable = [
        'document_id', 'assignment_id', 'violation_type', 'stage_name',
        'first_violated_at', 'last_notified_at', 'notification_count', 'resolved_at',
    ];

    protected $casts = [
        'first_violated_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    public function assignment()
    {
        return $this->belongsTo(DocumentAssignment::class, 'assignment_id', 'assignment_id');
    }

    /** Hours overdue as of now (if still open) or as of resolution (if closed) — the single number both the badge and the report display. */
    public function hoursOverdue(): int
    {
        $until = $this->resolved_at ?? now();

        return (int) floor($this->first_violated_at->diffInHours($until));
    }
}
