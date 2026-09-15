<?php
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

function documentInCategory(string $category, User $originator, string $suffix = ''): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => "{$category}-doc{$suffix}.txt",
        'file_path' => "documents/{$category}-doc{$suffix}.txt",
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => $category,
    ]);
}

function breachFor(User $approver, string $category, User $originator, string $suffix = ''): void
{
    $document = documentInCategory($category, $originator, $suffix);
    $stage = WorkflowStage::firstOrCreate(['document_category' => $category, 'stage_name' => 'Review', 'sequence_order' => 1]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
    ]);
    SlaViolation::create([
        'document_id' => $assignment->document_id,
        'assignment_id' => $assignment->assignment_id,
        'approver_id' => $approver->user_id,
        'violation_timestamp' => now(),
        'duration_overdue' => 30,
        'stage_name' => 'Review',
    ]);
}

test('an approver reassigned between categories has their popup scoped to only the current category, not lumped with other-category history', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    // Currently assigned to Purchase Requisition, but has breach history from
    // back when they were in Job Order — simulating a category reassignment.
    $approver = User::factory()->approver('Purchase Requisition')->create(['full_name' => 'Reassigned Approver']);

    breachFor($approver, 'Job Order', $originator, '-1');
    breachFor($approver, 'Job Order', $originator, '-2');
    breachFor($approver, 'Purchase Requisition', $originator);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations.approver', [
        'approver' => $approver->user_id,
        'category' => 'Job Order',
    ]));

    $response->assertOk()
        ->assertSee('Job Order-doc-1.txt')
        ->assertSee('Job Order-doc-2.txt')
        ->assertSee('Review') // the stage each violation happened on
        ->assertDontSee('Purchase Requisition-doc.txt');
});

test('the same approver\'s popup scoped to their other category shows only that category\'s document', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Purchase Requisition')->create(['full_name' => 'Reassigned Approver']);

    breachFor($approver, 'Job Order', $originator, '-1');
    breachFor($approver, 'Purchase Requisition', $originator);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations.approver', [
        'approver' => $approver->user_id,
        'category' => 'Purchase Requisition',
    ]));

    $response->assertOk()
        ->assertSee('Purchase Requisition-doc.txt')
        ->assertDontSee('Job Order-doc-1.txt');
});
