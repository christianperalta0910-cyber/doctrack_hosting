<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Regression coverage: workflow-stage-list.blade.php (shared by the
 * Originator tracking page, Approver Queue, Admin SLA Queue, and Admin
 * Document Tracking) used to bury a rejection/decision comment as a small
 * italic quoted string tacked onto the stage line — easy to miss. Now
 * shown in its own labeled box, same treatment as Decision History —
 * labeled "Rejected Due To:" for a rejection specifically (the common
 * case, and the one this whole fix was about), "Comments:" otherwise.
 */
it('labels a rejection reason "Rejected Due To:" on the originator tracking page', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'comment-visibility-test.txt', 'file_path' => 'documents/comment-vis.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'rejected', 'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'rejected',
        'comments' => 'Missing required signature.', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk();
    $response->assertSee('Rejected Due To:');
    $response->assertSee('Missing required signature.');
    $response->assertDontSee('Comments:');
});

it('keeps the neutral "Comments:" label for a comment on an approved decision', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'approved-comment-test.txt', 'file_path' => 'documents/approved-comment.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
        'comments' => 'Looks good, minor formatting note for next time.', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk();
    $response->assertSee('Comments:');
    $response->assertDontSee('Rejected Due To:');
});
