<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Carbon\Carbon;

/**
 * Covers AdminController::reviewAutoApproval()'s late-review logging —
 * the Admin-side counterpart to SlaViolation, attributed to the Admin
 * role (not a specific person — this queue has no single assigned owner
 * the way an approver's seat does) when a review lands after
 * review_due_at (set at auto-approval time — see SlaService::
 * autoApproveOne()).
 */
function autoApprovedAwaitingReview(Carbon $reviewDueAt): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'review-violation-test.txt',
        'file_path' => 'documents/review-violation-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDays(5),
        'global_status' => 'auto_approved',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'auto_approved' => true,
        'acted_at' => now()->subHours(7),
        'sla_expires_at' => now()->subHours(13),
        'review_due_at' => $reviewDueAt,
    ]);
}

it('logs no violation when an Admin reviews an auto-approval within its review window', function () {
    $admin = User::factory()->admin()->create();
    $assignment = autoApprovedAwaitingReview(now()->addHours(2)); // still 2 hours of window left

    $this->actingAs($admin)->post(route('admin.sla.review', $assignment->document), [
        'outcome' => 'confirmed',
    ])->assertSessionHas('status');

    expect(AdminViolation::count())->toBe(0);
    expect($assignment->fresh()->admin_reviewed_at)->not->toBeNull();
});

it('logs a resolved late_review violation when the review window already passed', function () {
    $admin = User::factory()->admin()->create();
    $assignment = autoApprovedAwaitingReview(now()->subHours(3)); // window closed 3 hours ago

    $this->actingAs($admin)->post(route('admin.sla.review', $assignment->document), [
        'outcome' => 'confirmed',
    ])->assertSessionHas('status');

    $violation = AdminViolation::where('violation_type', 'late_review')->first();
    expect($violation)->not->toBeNull()
        ->and($violation->assignment_id)->toBe($assignment->assignment_id)
        ->and($violation->resolved_at)->not->toBeNull()
        ->and($violation->hoursOverdue())->toBeGreaterThanOrEqual(2)
        ->and($violation->hoursOverdue())->toBeLessThanOrEqual(3);
});

it('still logs a violation on a late dispute, not just a late confirm', function () {
    $admin = User::factory()->admin()->create();
    $assignment = autoApprovedAwaitingReview(now()->subHour());

    $this->actingAs($admin)->post(route('admin.sla.review', $assignment->document), [
        'outcome' => 'disputed',
        'note' => 'Found a real problem with this one.',
    ])->assertSessionHas('status');

    expect(AdminViolation::where('assignment_id', $assignment->assignment_id)->where('violation_type', 'late_review')->exists())->toBeTrue();
});

it('does not log a violation for an assignment with no review_due_at at all', function () {
    $admin = User::factory()->admin()->create();
    $assignment = autoApprovedAwaitingReview(now()->subHours(3));
    $assignment->update(['review_due_at' => null]);

    $this->actingAs($admin)->post(route('admin.sla.review', $assignment->document), [
        'outcome' => 'confirmed',
    ])->assertSessionHas('status');

    expect(AdminViolation::count())->toBe(0);
});

it('resolves the existing open violation SlaService::trackLateReviews() already logged, rather than creating a duplicate', function () {
    $admin = User::factory()->admin()->create();
    $assignment = autoApprovedAwaitingReview(now()->subHours(5));

    // Simulates the periodic sweep already having noticed and opened one.
    AdminViolation::create([
        'document_id' => $assignment->document_id,
        'assignment_id' => $assignment->assignment_id,
        'violation_type' => 'late_review',
        'stage_name' => $assignment->stage->stage_name,
        'first_violated_at' => $assignment->review_due_at,
        'last_notified_at' => now()->subHours(4),
        'notification_count' => 1,
    ]);

    $this->actingAs($admin)->post(route('admin.sla.review', $assignment->document), [
        'outcome' => 'confirmed',
    ])->assertSessionHas('status');

    expect(AdminViolation::where('assignment_id', $assignment->assignment_id)->count())->toBe(1);
    expect(AdminViolation::first()->resolved_at)->not->toBeNull();
});
