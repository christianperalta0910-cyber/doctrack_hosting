<?php

use App\Events\DocumentStatusChanged;
use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Event;

function withdrawTestSetup(): array
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Flagging Approver']);
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'withdraw-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
        'ocr_text' => 'This sentence has a problem in it.',
    ]);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    $annotation = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id, 'raised_by' => $approver->user_id,
        'start_offset' => 15, 'end_offset' => 33, 'selected_text' => 'a problem in it', 'comment' => 'Please clarify.',
    ]);

    return compact('originator', 'approver', 'stage', 'document', 'assignment', 'annotation');
}

test('the approver who raised a flag can withdraw it before it is resolved', function () {
    ['originator' => $originator, 'approver' => $approver, 'document' => $document, 'annotation' => $annotation] = withdrawTestSetup();

    Event::fake([DocumentStatusChanged::class]);

    $response = $this->actingAs($approver)->postJson(route('approver.annotations.withdraw', $annotation));

    $response->assertOk();
    expect(DocumentAnnotation::find($annotation->annotation_id))->toBeNull();

    $notification = NotificationRecord::where('recipient_id', $originator->user_id)
        ->where('document_id', $document->document_id)->latest('created_at')->first();
    expect($notification)->not->toBeNull()
        ->and($notification->message_body)->toContain('withdrew');

    Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->document_id === $document->document_id);
});

test('withdrawing logs a distinct "Revision Request Withdrawn" audit entry, not a resolution', function () {
    ['approver' => $approver, 'document' => $document, 'annotation' => $annotation] = withdrawTestSetup();

    $this->actingAs($approver)->postJson(route('approver.annotations.withdraw', $annotation))->assertOk();

    $log = \App\Models\AuditLog::where('document_id', $document->document_id)->where('action_type', 'revision_withdrawn')->first();
    expect($log)->not->toBeNull()
        ->and($log->description)->toContain('withdrew');
});

test('another approver cannot withdraw a flag they did not raise', function () {
    ['document' => $document, 'annotation' => $annotation] = withdrawTestSetup();
    $otherApprover = User::factory()->approver('Job Order')->create();

    $response = $this->actingAs($otherApprover)->postJson(route('approver.annotations.withdraw', $annotation));

    $response->assertForbidden();
    expect(DocumentAnnotation::find($annotation->annotation_id))->not->toBeNull();
});

test('an already-resolved flag can no longer be withdrawn', function () {
    ['approver' => $approver, 'annotation' => $annotation] = withdrawTestSetup();
    $annotation->update(['resolved_at' => now()]);

    $response = $this->actingAs($approver)->postJson(route('approver.annotations.withdraw', $annotation));

    $response->assertForbidden();
    expect(DocumentAnnotation::find($annotation->annotation_id))->not->toBeNull();
});

test('the annotations panel offers a Withdraw control only for the viewing approver\'s own flag', function () {
    ['approver' => $approver, 'document' => $document, 'stage' => $stage, 'assignment' => $assignment] = withdrawTestSetup();
    $otherApprover = User::factory()->approver('Job Order')->create();
    // A second seat on the SAME document, so this other approver is
    // actually authorized to view ITS OWN annotations panel — the
    // annotations shown there aren't scoped to one assignment (any
    // approver on the document sees every open flag on it), only which
    // assignment id the route itself is reached through is.
    $otherAssignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $otherApprover->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    $ownView = $this->actingAs($approver)->get(route('approver.assignments.annotations', $assignment));
    $ownView->assertOk()->assertSee('Withdraw');

    $otherView = $this->actingAs($otherApprover)->get(route('approver.assignments.annotations', $otherAssignment));
    $otherView->assertOk()->assertDontSee('Withdraw');
});
