<?php

use App\Events\DocumentStatusChanged;
use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Event;

function revisionTestDoc(User $originator, string $category = 'Job Order'): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'revision-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => $category,
        'ocr_text' => 'The quick brown fox jumps over the lazy dog. This sentence has a problem in it.',
    ]);
}

test('an approver can flag a passage for revision without changing their own decision status', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionTestDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    seedReviewTime($approver, $document);

    $response = $this->actingAs($approver)->postJson(route('approver.assignments.requestRevision', $assignment), [
        'start_offset' => 60,
        'end_offset' => 82,
        'selected_text' => 'a problem in it',
        'comment' => 'This wording is unclear, please rephrase.',
    ]);

    $response->assertOk();

    expect($assignment->fresh()->individual_status)->toBe('pending');

    $annotation = DocumentAnnotation::where('document_id', $document->document_id)->first();
    expect($annotation)->not->toBeNull()
        ->and($annotation->raised_by)->toBe($approver->user_id)
        ->and($annotation->comment)->toBe('This wording is unclear, please rephrase.')
        ->and($annotation->resolved_at)->toBeNull();
});

test('flagging a passage broadcasts DocumentStatusChanged so the originator tracking page and any open approver popup update live', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionTestDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    seedReviewTime($approver, $document);
    Event::fake([DocumentStatusChanged::class]);

    $this->actingAs($approver)->postJson(route('approver.assignments.requestRevision', $assignment), [
        'start_offset' => 60,
        'end_offset' => 82,
        'selected_text' => 'a problem in it',
        'comment' => 'This wording is unclear, please rephrase.',
    ])->assertOk();

    Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->document_id === $document->document_id);
});

test('the originator is notified with the flagged passage and comment', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Flagging Approver']);
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionTestDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    seedReviewTime($approver, $document);

    $this->actingAs($approver)->postJson(route('approver.assignments.requestRevision', $assignment), [
        'start_offset' => 60,
        'end_offset' => 82,
        'selected_text' => 'a problem in it',
        'comment' => 'This wording is unclear, please rephrase.',
    ])->assertOk();

    $notification = \App\Models\NotificationRecord::where('recipient_id', $originator->user_id)
        ->where('document_id', $document->document_id)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->message_body)->toContain('Flagging Approver')
        ->and($notification->message_body)->toContain('This wording is unclear, please rephrase.')
        ->and($notification->message_body)->toContain('a problem in it');
});

test('requesting revision requires end_offset to be after start_offset', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionTestDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    seedReviewTime($approver, $document);

    $response = $this->actingAs($approver)->postJson(route('approver.assignments.requestRevision', $assignment), [
        'start_offset' => 82,
        'end_offset' => 60,
        'selected_text' => 'a problem in it',
        'comment' => 'This wording is unclear.',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['end_offset']);
});

test('another approver cannot request revision through someone else\'s assignment', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $otherApprover = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionTestDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    $response = $this->actingAs($otherApprover)->postJson(route('approver.assignments.requestRevision', $assignment), [
        'start_offset' => 0, 'end_offset' => 5, 'selected_text' => 'The q', 'comment' => 'test',
    ]);

    $response->assertForbidden();
});

test('the review-time gate applies before a revision can be flagged', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionTestDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    // No review session seeded — hasn't actually opened the document yet.
    $response = $this->actingAs($approver)->postJson(route('approver.assignments.requestRevision', $assignment), [
        'start_offset' => 0, 'end_offset' => 5, 'selected_text' => 'The q', 'comment' => 'test',
    ]);

    $response->assertStatus(422);
    expect(DocumentAnnotation::count())->toBe(0);
});

test('the annotations panel shows open annotations from any approver on the document', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create(['full_name' => 'Approver A']);
    $approverB = User::factory()->approver('Job Order')->create(['full_name' => 'Approver B']);
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionTestDoc($originator);

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

    DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignmentA->assignment_id, 'raised_by' => $approverA->user_id,
        'start_offset' => 60, 'end_offset' => 82, 'selected_text' => 'a problem in it', 'comment' => 'Unclear wording here.',
    ]);

    // Approver B, who never raised it themselves, should still see it.
    $response = $this->actingAs($approverB)->get(route('approver.assignments.annotations', $assignmentB));

    $response->assertOk()
        ->assertSee('a problem in it')
        ->assertSee('Unclear wording here.')
        ->assertSee('Approver A');
});

test('the rendered panel text is byte-for-byte identical to ocr_text, with and without annotations', function () {
    // Regression test: the panel used to render this loop with a Blade
    // @foreach/@if directly in the template body — a more "readable"
    // multi-line version of that leaked its own indentation/newlines
    // into the output, and a compact single-line version silently
    // failed to compile at all in one spot. Either failure mode breaks
    // the "select a passage" JS, which computes character offsets
    // against the DOM's actual text content and sends them straight to
    // the server to be looked up against ocr_text — any mismatch means
    // every highlight lands at the wrong place, or the request 500s.
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = revisionTestDoc($originator);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    $extractPanelText = function () use ($assignment) {
        $response = $this->actingAs($assignment->approver)->get(route('approver.assignments.annotations', $assignment));
        preg_match('/<div id="annotation-text"[^>]*>(.*?)<\/div>\s*<div id="annotation-form-area"/s', $response->getContent(), $m);

        return html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5);
    };

    expect($extractPanelText())->toBe($document->ocr_text);

    DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id, 'raised_by' => $approver->user_id,
        'start_offset' => 4, 'end_offset' => 9, 'selected_text' => 'quick', 'comment' => 'test',
    ]);

    expect($extractPanelText())->toBe($document->ocr_text);
});
