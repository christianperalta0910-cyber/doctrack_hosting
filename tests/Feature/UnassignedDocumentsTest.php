<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Queue;

/**
 * Queue::fake() — see ApproverDeactivationWithdrawTest.php's docblock: the
 * test env's sync queue driver would otherwise run EscalateAssignmentJob
 * immediately on routing and pre-escalate every seat before deactivation
 * even runs.
 */
beforeEach(fn () => Queue::fake());

// $testCase is passed in explicitly (Pest's $this is only bound inside the
// it()/test() closure itself, not inside a plain top-level helper function
// like this one — calling $this->actingAs() directly in here would throw
// "Using $this when not in object context").
function unassignedDocsSetup($testCase): array
{
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    WorkflowStage::create(['document_category' => 'Service Report', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $onlyApprover = User::factory()->approver('Service Report')->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'unassigned-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Service Report',
    ]);

    app(\App\Services\WorkflowService::class)->routeToWorkflow($document);

    // Deactivate the only eligible approver — nobody left, so this lands in
    // the Unassigned Documents queue, not the SLA Override Queue.
    $testCase->actingAs($admin)->post(route('admin.users.toggle', $onlyApprover), ['reason' => 'left the company']);

    $assignment = DocumentAssignment::where('document_id', $document->document_id)->first();

    return [$admin, $document, $assignment, $onlyApprover];
}

it('lists a document with no eligible approver in the Unassigned Documents module', function () {
    [$admin, $document] = unassignedDocsSetup($this);

    $response = $this->actingAs($admin)->get(route('admin.unassigned.index'));

    $response->assertOk();
    $response->assertSee($document->title);
    $response->assertSee('Needs Approver');
});

it('never puts a needs_approver seat in the SLA Override Queue', function () {
    // Note: can't assert on page text here — the notification bell
    // (rendered on every page) legitimately shows the "needs an approver"
    // notification's text, which embeds the document title too. Assert on
    // the queue's actual underlying data instead.
    [$admin, $document, $assignment] = unassignedDocsSetup($this);

    $response = $this->actingAs($admin)->get(route('admin.sla.queue'));

    $response->assertOk();
    $response->assertViewHas('assignments', fn ($paginator) => $paginator->total() === 0);
    expect($assignment->fresh()->escalated_to_admin)->toBeFalse();
});

it('lets an admin decide a needs_approver seat directly with no SlaViolation created', function () {
    [$admin, $document, $assignment] = unassignedDocsSetup($this);

    seedReviewTime($admin, $document);
    $this->actingAs($admin)->post(route('admin.unassigned.decide', $assignment), [
        'decision' => 'approved',
    ])->assertRedirect();

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved');
    expect($fresh->needs_approver)->toBeFalse();
    expect($fresh->admin_override_by)->toBe($admin->user_id);
    expect(SlaViolation::where('assignment_id', $fresh->assignment_id)->exists())->toBeFalse();
    expect($document->fresh()->global_status)->toBe('approved');
});

it('requires comments when an admin rejects a needs_approver seat directly', function () {
    [$admin, $document, $assignment] = unassignedDocsSetup($this);

    $response = $this->actingAs($admin)->post(route('admin.unassigned.decide', $assignment), [
        'decision' => 'rejected',
    ]);

    $response->assertSessionHasErrors('comments');
    expect($assignment->fresh()->individual_status)->toBe('pending');
});
