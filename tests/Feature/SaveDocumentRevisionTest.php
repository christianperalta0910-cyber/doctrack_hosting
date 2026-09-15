<?php

use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;

function saveRevisionDoc(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'save-revision-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
        'ocr_text' => 'The original wording has a problem in it.',
    ]);
}

test('the originator can edit the text and resolve only the annotations they check', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create(['full_name' => 'Approver A']);
    $approverB = User::factory()->approver('Job Order')->create(['full_name' => 'Approver B']);
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = saveRevisionDoc($originator);

    $assignmentA = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approverA->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);
    $assignmentB = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approverB->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    $annotationA = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignmentA->assignment_id, 'raised_by' => $approverA->user_id,
        'start_offset' => 21, 'end_offset' => 40, 'selected_text' => 'has a problem in it', 'comment' => 'Unclear wording here.',
    ]);
    $annotationB = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignmentB->assignment_id, 'raised_by' => $approverB->user_id,
        'start_offset' => 0, 'end_offset' => 3, 'selected_text' => 'The', 'comment' => 'A separate, unrelated concern.',
    ]);

    $newText = 'The original wording is now much clearer.';

    $response = $this->actingAs($originator)->post(route('originator.documents.saveRevision', $document), [
        'text' => $newText,
        'resolved_annotation_ids' => [$annotationA->annotation_id],
    ]);

    $response->assertRedirect();

    expect($document->fresh()->ocr_text)->toBe($newText)
        ->and($annotationA->fresh()->resolved_at)->not->toBeNull()
        ->and($annotationB->fresh()->resolved_at)->toBeNull();

    $notificationA = NotificationRecord::where('recipient_id', $approverA->user_id)
        ->where('document_id', $document->document_id)->latest('created_at')->first();
    expect($notificationA)->not->toBeNull()
        ->and($notificationA->message_body)->toContain('please re-review');

    $notificationB = NotificationRecord::where('recipient_id', $approverB->user_id)
        ->where('document_id', $document->document_id)->latest('created_at')->first();
    expect($notificationB)->toBeNull();
});

test('an admin viewing someone else\'s document cannot save a revision', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $document = saveRevisionDoc($originator);

    $response = $this->actingAs($admin)->post(route('originator.documents.saveRevision', $document), [
        'text' => 'Tampered text.',
    ]);

    $response->assertForbidden();
    expect($document->fresh()->ocr_text)->not->toBe('Tampered text.');
});

test('another originator cannot save a revision on someone else\'s document', function () {
    $originator = User::factory()->originator()->create();
    $otherOriginator = User::factory()->originator()->create();
    $document = saveRevisionDoc($originator);

    $response = $this->actingAs($otherOriginator)->post(route('originator.documents.saveRevision', $document), [
        'text' => 'Tampered text.',
    ]);

    $response->assertForbidden();
});

test('the tracking page shows open annotations to the originator with edit controls', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Flagging Approver']);
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = saveRevisionDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);
    DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id, 'raised_by' => $approver->user_id,
        'start_offset' => 21, 'end_offset' => 40, 'selected_text' => 'has a problem in it', 'comment' => 'Unclear wording here.',
    ]);

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk()
        ->assertSee('Revision Requests')
        ->assertSee('has a problem in it')
        ->assertSee('Unclear wording here.')
        ->assertSee('Flagging Approver')
        ->assertSee('Save Revision');
});

test('the tracking page shows open annotations read-only to an admin, without edit controls', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Flagging Approver']);
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = saveRevisionDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);
    DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id, 'raised_by' => $approver->user_id,
        'start_offset' => 21, 'end_offset' => 40, 'selected_text' => 'has a problem in it', 'comment' => 'Unclear wording here.',
    ]);

    $response = $this->actingAs($admin)->get(route('documents.track', $document));

    $response->assertOk()
        ->assertSee('has a problem in it')
        ->assertDontSee('Save Revision');
});
