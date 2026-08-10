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
// session every time, since there's nothing left for anyone to "review".
// Session tracking is now gated on the DOCUMENT as a whole having been
// judged (see DocumentController::countsAsActiveReviewer()), not on this
// one seat specifically — so the document's own global_status has to be
// genuinely finalized here, not just the assignment row, to accurately
// simulate what a real fully-resolved document looks like.
it('still allows viewing the file after the document is fully resolved, but opens no new session', function () {
    [$approver, $document] = reviewSessionTestSetup();
    DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)
        ->update(['individual_status' => 'approved', 'acted_at' => now()]);
    $document->update(['global_status' => 'approved']);

    $this->actingAs($approver)->get(route('documents.file', $document))->assertOk();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeFalse();
});

it('does not heartbeat or list the viewer in presence once the document is fully resolved', function () {
    [$approver, $document] = reviewSessionTestSetup();
    DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)
        ->update(['individual_status' => 'rejected', 'acted_at' => now()]);
    $document->update(['global_status' => 'rejected']);

    $response = $this->actingAs($approver)->get(route('documents.presence', $document));

    $response->assertOk();
    $response->assertJson(['viewers' => []]);
    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeFalse();
});

it('does not close a session that was never opened for an already-resolved document', function () {
    [$approver, $document] = reviewSessionTestSetup();
    DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)
        ->update(['individual_status' => 'approved', 'acted_at' => now()]);
    $document->update(['global_status' => 'approved']);

    $this->actingAs($approver)->post(route('documents.presence.leave', $document))->assertNoContent();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeFalse();
});

it('still opens a review session for a co-approver seat that is genuinely still pending, even if this approver has another already-decided seat on the same document', function () {
    [$approver, $document] = reviewSessionTestSetup();
    $stage = \App\Models\WorkflowStage::where('document_category', 'Job Order')->first();

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

// Regression coverage for the actual reported bug: opening the same
// undecided document in multiple approver accounts (and an admin) only
// ever showed ONE of them in the "currently reviewing" presence cluster,
// because a session only opened for whoever's OWN seat happened to be
// the pending one right then. Fixed by gating on the document as a whole
// not yet being judged (see DocumentController::countsAsActiveReviewer()),
// not on "is it specifically this person's turn."
it('opens a session for an approver whose own seat is already decided, as long as the document overall is still undecided', function () {
    [$approver, $document] = reviewSessionTestSetup();
    DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)
        ->update(['individual_status' => 'approved', 'acted_at' => now()]);
    // Document itself stays 'classified_validated' — other approvers/
    // stages (not modeled here) are still pending, so it's genuinely NOT
    // judged yet, unlike the "fully resolved" tests above.

    $this->actingAs($approver)->get(route('documents.file', $document))->assertOk();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $approver->user_id)->exists())->toBeTrue();
});

it('opens a session for an admin merely browsing an undecided document, even though nothing has escalated to them', function () {
    [, $document] = reviewSessionTestSetup();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('documents.file', $document))->assertOk();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $admin->user_id)->exists())->toBeTrue();
});

it('shows every simultaneous viewer in the presence feed — two approvers and an admin all on the same undecided document', function () {
    [$approver, $document] = reviewSessionTestSetup();
    $secondApprover = User::factory()->approver('Job Order')->create();
    $admin = User::factory()->admin()->create();

    // Needs its own assignment row to pass the plain view-authorization
    // check (any assignment ever, decided or not — see
    // DocumentController::approverAccessFor()) — a stage can have more
    // than one eligible approver under the parallel-approval model.
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $secondApprover->user_id,
        'stage_id' => \App\Models\WorkflowStage::where('document_category', 'Job Order')->first()->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    $this->actingAs($approver)->get(route('documents.file', $document));
    $this->actingAs($secondApprover)->get(route('documents.file', $document));
    $this->actingAs($admin)->get(route('documents.file', $document));

    $response = $this->actingAs($approver)->get(route('documents.presence', $document));

    $response->assertOk();
    $viewerNames = collect($response->json('viewers'))->pluck('name');
    expect($viewerNames)->toHaveCount(3)
        ->and($viewerNames)->toContain($approver->full_name, $secondApprover->full_name, $admin->full_name);
});
