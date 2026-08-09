<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Coverage for the AJAX response path added to decide()/decideBatch() —
 * Feature: Approve/Reject no longer causes a full-page redirect (which was
 * resetting scroll position to the top of the approver's queue on every
 * decision). A JSON-requesting client gets a JSON body instead of a
 * redirect; a plain form post (no JS, Accept: text/html) still gets the
 * original redirect so the feature degrades gracefully.
 */
function ajaxDecideAssignment(User $approver, DocumentRepository $document, WorkflowStage $stage): DocumentAssignment
{
    return DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
}

it('returns JSON instead of a redirect when the client asks for it', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'ajax-decide.txt', 'file_path' => 'documents/ajax-decide.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
    $assignment = ajaxDecideAssignment($approver, $document, $stage);
    seedReviewTime($approver, $document);

    $response = $this->actingAs($approver)->postJson(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertOk()->assertJson(['status' => 'Decision recorded: Approved.']);
    expect($assignment->fresh()->individual_status)->toBe('approved');
});

it('still redirects a plain (non-JSON) form submission, unchanged from before', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'plain-decide.txt', 'file_path' => 'documents/plain-decide.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
    $assignment = ajaxDecideAssignment($approver, $document, $stage);
    seedReviewTime($approver, $document);

    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertRedirect(route('approver.dashboard'));
    expect($assignment->fresh()->individual_status)->toBe('approved');
});

it('returns a JSON error message (not a redirect) when the review-time gate blocks a JSON request', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'blocked-decide.txt', 'file_path' => 'documents/blocked-decide.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
    $assignment = ajaxDecideAssignment($approver, $document, $stage);
    // No seedReviewTime() — the 10s floor hasn't been met yet.

    $response = $this->actingAs($approver)->postJson(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('at least');
    expect($assignment->fresh()->individual_status)->toBe('pending');
});

it('decideBatch() also returns JSON when the client asks for it', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'ajax-batch.txt', 'file_path' => 'documents/ajax-batch.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
    $assignment = ajaxDecideAssignment($approver, $document, $stage);
    seedReviewTime($approver, $document);

    $response = $this->actingAs($approver)->postJson(route('approver.assignments.decideBatch'), [
        'assignment_ids' => [$assignment->assignment_id],
        'decision' => 'approved',
    ]);

    $response->assertOk()->assertJson(['status' => 'Decision recorded: Approved.']);
    expect($assignment->fresh()->individual_status)->toBe('approved');
});
