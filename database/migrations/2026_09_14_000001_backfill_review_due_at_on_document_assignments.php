<?php

use App\Models\DocumentAssignment;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfills review_due_at for auto-approved assignments still awaiting
 * Admin review whose review_due_at was never set — these predate
 * SlaService::autoApproveOne() populating this column (added in the
 * 2026_09_10_000002 migration), so without it they're invisible both to
 * the Auto-Approval Review page's countdown badge AND to SlaService::
 * trackLateReviews(), which requires review_due_at to be non-null before
 * it'll flag a late review at all. Same formula autoApproveOne() itself
 * uses: acted_at + 6 hours (SlaService::ADMIN_REVIEW_WINDOW_HOURS),
 * capped at the assignment's own due_date if that's earlier.
 */
return new class extends Migration
{
    private const ADMIN_REVIEW_WINDOW_HOURS = 6;

    public function up(): void
    {
        DocumentAssignment::query()
            ->where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->whereNull('review_due_at')
            ->whereNotNull('acted_at')
            ->get()
            ->each(function (DocumentAssignment $assignment) {
                $flatReviewDeadline = $assignment->acted_at->copy()->addHours(self::ADMIN_REVIEW_WINDOW_HOURS);
                $assignment->review_due_at = ($assignment->due_date && $flatReviewDeadline->greaterThan($assignment->due_date))
                    ? $assignment->due_date->copy()
                    : $flatReviewDeadline;
                $assignment->saveQuietly();
            });
    }

    public function down(): void
    {
        // Not reversible — there's no way to tell a backfilled value apart
        // from one autoApproveOne() would have set naturally, so rolling
        // back would risk blanking out legitimately-set rows too.
    }
};
