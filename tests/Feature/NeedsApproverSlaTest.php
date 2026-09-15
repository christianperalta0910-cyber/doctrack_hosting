<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Queue;

/**
 * Regression coverage for giving a needs_approver seat its own admin-facing
 * SLA deadline (see WorkflowService::markNeedsApprover()) — so a stage
 * nobody's eligible for can't sit unresolved forever, while still never
 * misattributing the resulting escalation to whoever used to hold the seat
 * (see SlaService::escalate()'s needs_approver branch).
 */
function orphanedAssignment(string $category = 'Purchase Requisition'): array
{
    Queue::fake(); // markNeedsApprover() dispatches EscalateAssignmentJob — sync driver would fire it immediately otherwise.

    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Review'],
        ['sequence_order' => 1]
    );
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'needs-approver-sla-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => $category,
    ]);
    $oldApprover = User::factory()->approver($category)->create();
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $oldApprover->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(3),
    ]);

    app(WorkflowService::class)->markNeedsApprover($assignment, $oldApprover, 'left the company');

    return [$admin, $oldApprover, $assignment->fresh()];
}

test('markNeedsApprover() gives the seat its own SLA deadline instead of leaving it open-ended', function () {
    [, , $assignment] = orphanedAssignment();

    expect($assignment->sla_expires_at)->not->toBeNull()
        ->and($assignment->sla_expires_at->isFuture())->toBeTrue()
        ->and($assignment->escalated_to_admin)->toBeFalse(); // not yet — only once that deadline actually lapses
});

test('once the deadline lapses, the seat auto-approves immediately, logging an Admin violation instead of blaming the deactivated approver', function () {
    [, $oldApprover, $assignment] = orphanedAssignment();
    $assignment->update(['sla_expires_at' => now()->subMinutes(5)]);

    app(SlaService::class)->escalate($assignment->fresh());

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved')
        ->and($fresh->auto_approved)->toBeTrue()
        // The whole point: this must never count against the old approver's record.
        ->and(SlaViolation::where('assignment_id', $fresh->assignment_id)->exists())->toBeFalse()
        ->and(AdminViolation::where('assignment_id', $fresh->assignment_id)->where('violation_type', 'missed_approval')->exists())->toBeTrue();

    // markNeedsApprover() already sent its own "needs an approver"
    // notification when first flagged; the auto-approval itself sends the
    // Originator/Admin notifications autoApproveOne() always sends —
    // neither should ever name the deactivated old approver as at fault.
    $notification = NotificationRecord::where('document_id', $fresh->document_id)->where('priority', 'high')->get();
    expect($notification->contains(fn ($n) => str_contains($n->message_body, 'approver: ' . $oldApprover->full_name)))->toBeFalse();
});

test('once auto-approved, the seat disappears from Unassigned Documents and appears in Auto-Approved — Awaiting Review', function () {
    [$admin, , $assignment] = orphanedAssignment();
    $assignment->update(['sla_expires_at' => now()->subMinutes(5)]);
    app(SlaService::class)->escalate($assignment->fresh());

    $unassigned = $this->actingAs($admin)->get(route('admin.unassigned.index'));
    $unassigned->assertViewHas('containers', fn ($paginator) => $paginator->total() === 0);

    $slaQueue = $this->actingAs($admin)->get(route('admin.sla.queue'));
    $slaQueue->assertViewHas('reviewContainers', fn ($containers) => $containers->total() === 1);
});

test('the Unassigned Documents view shows only the earliest pending stage as actionable, one panel not several', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $category = 'Service Report';
    WorkflowStage::create(['document_category' => $category, 'stage_name' => 'First Stage', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => $category, 'stage_name' => 'Second Stage', 'sequence_order' => 2]);
    $oldApprover = User::factory()->approver($category)->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'multi-stage-orphan.txt', 'file_path' => 'documents/multi-stage-orphan.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'ml_category' => $category,
    ]);

    $workflow = app(WorkflowService::class);
    foreach (WorkflowStage::where('document_category', $category)->orderBy('sequence_order')->get() as $stage) {
        $a = DocumentAssignment::create([
            'document_id' => $document->document_id, 'user_id' => $oldApprover->user_id, 'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
            'sla_expires_at' => now()->addHours(3),
        ]);
        $workflow->markNeedsApprover($a, $oldApprover, 'left the company');
    }

    $response = $this->actingAs($admin)->get(route('admin.unassigned.index'));

    // Only ONE "Stage:" actionable label should render for this document —
    // not one per orphaned stage.
    $response->assertOk();
    preg_match_all('/Stage: /', $response->getContent(), $matches);
    expect(count($matches[0]))->toBe(1);
});
