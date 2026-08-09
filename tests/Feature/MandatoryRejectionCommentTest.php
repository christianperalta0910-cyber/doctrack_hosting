<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

function pendingAssignmentForRejectionTest(User $approver, string $category = 'Job Order'): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Review'],
        ['sequence_order' => 1]
    );
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'reject-comment-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => $category,
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(3),
    ]);
}

it('rejects an approver decision with no comment', function () {
    $approver = User::factory()->approver()->create();
    $assignment = pendingAssignmentForRejectionTest($approver);

    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'rejected',
    ]);

    $response->assertSessionHasErrors('comments');
    expect($assignment->fresh()->individual_status)->toBe('pending');
});

it('allows an approver decision to approve with no comment', function () {
    $approver = User::factory()->approver()->create();
    $assignment = pendingAssignmentForRejectionTest($approver);

    seedReviewTime($approver, $assignment->document);
    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertSessionDoesntHaveErrors('comments');
    expect($assignment->fresh()->individual_status)->toBe('approved');
});

it('allows an approver to reject once a comment is provided', function () {
    $approver = User::factory()->approver()->create();
    $assignment = pendingAssignmentForRejectionTest($approver);

    seedReviewTime($approver, $assignment->document);
    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'rejected',
        'comments' => 'Missing required signature.',
    ]);

    $response->assertSessionDoesntHaveErrors('comments');
    expect($assignment->fresh()->individual_status)->toBe('rejected');
});

it('rejects an admin SLA override with no comment', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver()->create();
    $assignment = pendingAssignmentForRejectionTest($approver);
    $assignment->update(['escalated_to_admin' => true, 'escalation_reason' => 'sla_violation']);

    $response = $this->actingAs($admin)->post(route('admin.sla.override', $assignment), [
        'decision' => 'rejected',
    ]);

    $response->assertSessionHasErrors('comments');
});

it('allows an admin SLA override to approve with no comment', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver()->create();
    $assignment = pendingAssignmentForRejectionTest($approver);
    $assignment->update(['escalated_to_admin' => true, 'escalation_reason' => 'sla_violation']);

    $response = $this->actingAs($admin)->post(route('admin.sla.override', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertSessionDoesntHaveErrors('comments');
});
