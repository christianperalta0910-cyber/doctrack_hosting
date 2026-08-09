<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for the presence icon / review-duration tracking
 * built earlier this project (DocumentController::viewFile()/presence()/
 * presenceLeave() + DocumentReviewSession) — verified live during
 * development but never left with a permanent test, so a real regression
 * here (e.g. the earlier leave()-vs-closeFor() duration-loss bug) could
 * silently return uncaught.
 */
function reviewSessionTestSetup(): array
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'review-session-test.txt',
        'file_path' => 'documents/review-session-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => WorkflowStage::where('document_category', 'Job Order')->first()->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(3),
    ]);
    Storage::disk('local')->put($document->file_path, 'test content');

    return [$approver, $document];
}

it('opens a review session when an assigned approver views the file', function () {
    [$approver, $document] = reviewSessionTestSetup();

    $this->actingAs($approver)->get(route('documents.file', $document))->assertOk();

    $session = DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->first();
    expect($session)->not->toBeNull();
    expect($session->session_type)->toBe('initial');
    expect($session->closed_at)->toBeNull();
});

it('shows the viewer in the live presence feed after a heartbeat', function () {
    [$approver, $document] = reviewSessionTestSetup();
    $this->actingAs($approver)->get(route('documents.file', $document));

    $response = $this->actingAs($approver)->get(route('documents.presence', $document));

    $response->assertOk();
    $response->assertJsonFragment(['name' => $approver->full_name]);
});

it('closing the viewer records a duration and removes the presence entry', function () {
    [$approver, $document] = reviewSessionTestSetup();
    $this->actingAs($approver)->get(route('documents.file', $document));
    $this->travel(5)->seconds();

    $this->actingAs($approver)->post(route('documents.presence.leave', $document))->assertNoContent();

    $session = DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->first();
    expect($session->closed_at)->not->toBeNull();
    expect($session->duration_seconds)->toBeGreaterThanOrEqual(5);

    $presence = $this->actingAs($approver)->get(route('documents.presence', $document));
    $presence->assertJson(['viewers' => []]);
});

it('reopening after a close starts a follow_up session and accumulates total duration', function () {
    [$approver, $document] = reviewSessionTestSetup();

    $this->actingAs($approver)->get(route('documents.file', $document));
    $this->travel(3)->seconds();
    $this->actingAs($approver)->post(route('documents.presence.leave', $document));

    $this->actingAs($approver)->get(route('documents.file', $document));
    $this->travel(4)->seconds();
    $this->actingAs($approver)->post(route('documents.presence.leave', $document));

    $sessions = DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->orderBy('opened_at')->get();
    expect($sessions)->toHaveCount(2);
    expect($sessions[0]->session_type)->toBe('initial');
    expect($sessions[1]->session_type)->toBe('follow_up');

    $total = DocumentReviewSession::totalSecondsFor($document->document_id, $approver->user_id);
    expect($total)->toBeGreaterThanOrEqual(7);
});

it('does not open a review session for an originator merely viewing their own file', function () {
    [$approver, $document] = reviewSessionTestSetup();
    $originator = $document->originator;

    $this->actingAs($originator)->get(route('documents.file', $document))->assertOk();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $originator->user_id)->exists())->toBeFalse();
});

// Feature: revisiting an already-decided document (e.g. from Decision
// History or Archive) still needs to be VIEWABLE — the approver
// legitimately handled it — but shouldn't spin up a fresh tracking
// session every time, since there's nothing left for them to "review".
it('still allows viewing the file after the approver has already decided their seat, but opens no new session', function () {
    [$approver, $document] = reviewSessionTestSetup();
    DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)
        ->update(['individual_status' => 'approved', 'acted_at' => now()]);

    $this->actingAs($approver)->get(route('documents.file', $document))->assertOk();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeFalse();
});

it('does not heartbeat or list the viewer in presence once their seat is already decided', function () {
    [$approver, $document] = reviewSessionTestSetup();
    DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)
        ->update(['individual_status' => 'rejected', 'acted_at' => now()]);

    $response = $this->actingAs($approver)->get(route('documents.presence', $document));

    $response->assertOk();
    $response->assertJson(['viewers' => []]);
    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeFalse();
});

it('does not close a session that was never opened for an already-decided seat', function () {
    [$approver, $document] = reviewSessionTestSetup();
    DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)
        ->update(['individual_status' => 'approved', 'acted_at' => now()]);

    $this->actingAs($approver)->post(route('documents.presence.leave', $document))->assertNoContent();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeFalse();
});

it('still opens a review session for a co-approver seat that is genuinely still pending, even if this approver has another already-decided seat on the same document', function () {
    [$approver, $document] = reviewSessionTestSetup();
    $stage = \App\Models\WorkflowStage::where('document_category', 'Job Order')->first();

    // A second, still-pending stage for the SAME approver on the SAME
    // document — proves the gate is per-seat (any pending row), not an
    // all-or-nothing check against the first row found.
    $secondStage = \App\Models\WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final', 'sequence_order' => 2]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $secondStage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
    DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)->where('stage_id', $stage->stage_id)
        ->update(['individual_status' => 'approved', 'acted_at' => now()]);

    $this->actingAs($approver)->get(route('documents.file', $document))->assertOk();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeTrue();
});
