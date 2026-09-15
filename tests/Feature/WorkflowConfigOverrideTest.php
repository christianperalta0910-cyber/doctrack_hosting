<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

function overrideTestSetup(): array
{
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Held-Up Approver']);
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'override-test.txt', 'file_path' => 'documents/override.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
    ]);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    return compact('admin', 'originator', 'approver', 'document', 'assignment');
}

test('an admin can approve a pending assignment directly from Workflow Config, even though a real approver is still holding it', function () {
    ['admin' => $admin, 'document' => $document, 'assignment' => $assignment] = overrideTestSetup();
    seedReviewTime($admin, $document);

    $response = $this->actingAs($admin)->post(route('admin.sla.override', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertRedirect();

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved')
        ->and($fresh->admin_override_by)->toBe($admin->user_id)
        ->and($document->fresh()->global_status)->toBe('approved');
});

test('rejecting via Workflow Config override requires comments, same as every other admin reject', function () {
    ['admin' => $admin, 'document' => $document, 'assignment' => $assignment] = overrideTestSetup();
    seedReviewTime($admin, $document);

    $response = $this->actingAs($admin)->post(route('admin.sla.override', $assignment), [
        'decision' => 'rejected',
    ]);

    $response->assertSessionHasErrors('comments');
    expect($assignment->fresh()->individual_status)->toBe('pending');
});

test('the override audit entry is worded distinctly from the unassigned-seat fallback, naming the approver being overridden', function () {
    ['admin' => $admin, 'document' => $document, 'assignment' => $assignment] = overrideTestSetup();
    seedReviewTime($admin, $document);

    $this->actingAs($admin)->post(route('admin.sla.override', $assignment), [
        'decision' => 'approved',
    ])->assertRedirect();

    $log = \App\Models\AuditLog::where('document_id', $document->document_id)->where('action_type', 'admin_override')->first();
    expect($log)->not->toBeNull()
        ->and($log->description)->toContain('Held-Up Approver')
        ->and($log->description)->not->toContain('no approver was eligible');
});

test('an already-decided assignment cannot be overridden again', function () {
    ['admin' => $admin, 'assignment' => $assignment] = overrideTestSetup();
    $assignment->update(['individual_status' => 'approved']);

    $response = $this->actingAs($admin)->post(route('admin.sla.override', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertStatus(409);
});
