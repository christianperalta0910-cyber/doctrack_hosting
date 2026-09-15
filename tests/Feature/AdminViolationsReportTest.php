<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

function violationDoc(User $originator, string $category, string $title): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => $title, 'file_path' => 'documents/'.$title,
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'auto_approved', 'ml_category' => $category,
    ]);
}

test('the approver popup only shows that approver\'s own documents', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $approverA = User::factory()->approver('Job Order')->create(['full_name' => 'Approver A']);
    $approverB = User::factory()->approver('Job Order')->create(['full_name' => 'Approver B']);

    foreach ([[$approverA, 'a-doc.txt'], [$approverB, 'b-doc.txt']] as [$approver, $title]) {
        $document = violationDoc($originator, 'Job Order', $title);
        $assignment = DocumentAssignment::create([
            'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
            'sla_expires_at' => now()->subHour(),
        ]);
        SlaViolation::create([
            'document_id' => $assignment->document_id, 'assignment_id' => $assignment->assignment_id,
            'approver_id' => $approver->user_id, 'violation_timestamp' => now(), 'duration_overdue' => 30, 'stage_name' => 'Review',
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.violations.approver', ['approver' => $approverA->user_id, 'category' => 'Job Order']));

    $response->assertOk()->assertSee('a-doc.txt')->assertDontSee('b-doc.txt');
});

test('the Admin Violations section is hidden on the bare landing screen, same as the approver table', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $missedDoc = violationDoc($originator, 'Job Order', 'missed-approval-doc.txt');
    $missedAssignment = DocumentAssignment::create([
        'document_id' => $missedDoc->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $missedDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
    ]);
    AdminViolation::create([
        'document_id' => $missedDoc->document_id, 'assignment_id' => $missedAssignment->assignment_id,
        'violation_type' => 'missed_approval', 'stage_name' => 'Review',
        'first_violated_at' => now(), 'resolved_at' => now(),
    ]);

    $bare = $this->actingAs($admin)->get(route('admin.sla.violations'));
    $bare->assertOk()->assertDontSee('Admin Violations')->assertDontSee('missed-approval-doc.txt');
});

test('the Admin Violations table groups by document with stages listed underneath, badge reflects whether the document still needs review, scoped to only the current category', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $jobOrderStage1 = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $jobOrderStage2 = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    $purchaseStage = WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Review', 'sequence_order' => 1]);

    // Still sitting unreviewed in Auto-Approval Review (admin_reviewed_at
    // is null) — must show Open, even though its AdminViolation row is
    // itself already marked resolved_at (that field only logs the
    // approval-stage fact, not whether Admin has reviewed it — see
    // AdminController::adminViolationsData()'s docblock).
    $openDoc = violationDoc($originator, 'Job Order', 'missed-approval-doc.txt');
    $openAssignment = DocumentAssignment::create([
        'document_id' => $openDoc->document_id, 'user_id' => null, 'stage_id' => $jobOrderStage1->stage_id,
        'due_date' => $openDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        'admin_reviewed_at' => null,
    ]);
    AdminViolation::create([
        'document_id' => $openDoc->document_id, 'assignment_id' => $openAssignment->assignment_id,
        'violation_type' => 'missed_approval', 'stage_name' => 'Technical Review',
        'first_violated_at' => now(), 'resolved_at' => now(),
    ]);

    // Already reviewed by Admin (admin_reviewed_at is set) — must show
    // Resolved.
    $resolvedDoc = violationDoc($originator, 'Job Order', 'late-review-doc.txt');
    $resolvedAssignment = DocumentAssignment::create([
        'document_id' => $resolvedDoc->document_id, 'user_id' => null, 'stage_id' => $jobOrderStage2->stage_id,
        'due_date' => $resolvedDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        'admin_reviewed_at' => now(),
    ]);
    AdminViolation::create([
        'document_id' => $resolvedDoc->document_id, 'assignment_id' => $resolvedAssignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => 'Final Approval',
        'first_violated_at' => now()->subHours(2), 'resolved_at' => now(),
    ]);

    // A second category's own Admin violation — must never leak into the
    // Job Order folder's section below.
    $otherDoc = violationDoc($originator, 'Purchase Requisition', 'other-category-doc.txt');
    $otherAssignment = DocumentAssignment::create([
        'document_id' => $otherDoc->document_id, 'user_id' => null, 'stage_id' => $purchaseStage->stage_id,
        'due_date' => $otherDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
    ]);
    AdminViolation::create([
        'document_id' => $otherAssignment->document_id, 'assignment_id' => $otherAssignment->assignment_id,
        'violation_type' => 'missed_approval', 'stage_name' => 'Review',
        'first_violated_at' => now(), 'resolved_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));
    $response->assertOk()
        ->assertSee('Admin Violations')
        ->assertSee('missed-approval-doc.txt')
        ->assertSee('late-review-doc.txt')
        ->assertSee('Technical Review')
        ->assertSee('Final Approval')
        ->assertSee('Open')
        ->assertSee('Resolved')
        ->assertDontSee('other-category-doc.txt')
        ->assertDontSee('Missed by Admin')
        ->assertDontSee('Late Review');

    $items = $response->viewData('adminViolations')->keyBy(fn ($item) => $item->document->title);
    expect($items['missed-approval-doc.txt']->isOpen)->toBeTrue()
        ->and($items['late-review-doc.txt']->isOpen)->toBeFalse();
});
