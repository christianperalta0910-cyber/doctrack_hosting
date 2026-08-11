<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

function violationIn(string $category, string $stageName = 'Review'): SlaViolation
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver($category)->create();
    $stage = WorkflowStage::create(['document_category' => $category, 'stage_name' => $stageName, 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => "{$category}-" . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'upload_date' => now(),
        'global_status' => 'auto_approved',
        'ml_category' => $category,
    ]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'auto_approved' => true,
        'acted_at' => now(),
        'sla_expires_at' => now()->subHours(13),
    ]);

    return SlaViolation::create([
        'document_id' => $document->document_id,
        'assignment_id' => $assignment->assignment_id,
        'approver_id' => $approver->user_id,
        'violation_timestamp' => now(),
        'duration_overdue' => 30,
        'stage_name' => $stageName,
    ]);
}

test('visiting the bare SLA violations URL shows category folders, not the results list', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations'));

    $response->assertOk();
    $response->assertSee('Browse by Category');
    $response->assertSee('Job Order');
    $response->assertDontSee('Search document');
});

test('the stat cards and approver roster stay hidden on the folder-grid screen, so nothing looks pre-filtered before a category is picked', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations'));

    $response->assertOk();
    $response->assertDontSee('Total Violations');
    $response->assertDontSee('Top Category');
    $response->assertDontSee('Avg. Minutes Overdue');
    $response->assertDontSee('View All Approvers');
});

test('the stat cards and approver roster appear once a category is picked, scoped to it', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');
    violationIn('Service Report');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Total Violations');
    $response->assertSee('Top Category');
    $response->assertSee('View All Approvers');
    $response->assertSee('Disputed');
});

test('clicking into a category folder shows the search panel and scoped results', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');
    violationIn('Service Report');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Search document');
    $response->assertDontSee('Browse by Category');
});

test('the Clear link keeps the selected category instead of bouncing back to the folder grid', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    // The Clear link must only drop document/date_from/date_to — dropping
    // category too would flip $showFolders back to true and silently
    // bounce the admin out of the category they were looking at.
    $response->assertSee('?category=Job+Order" class="text-xs font-medium text-surface-500', false);
});

test('the Top Category card reflects the category with the most breaches', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');
    violationIn('Job Order');
    violationIn('Service Report');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    $byCategory = $response->viewData('byCategory');
    expect($byCategory->ml_category)->toBe('Job Order')
        ->and($byCategory->total)->toBe(2);
});

test('the Disputed card counts violations whose document was later disputed', function () {
    $admin = User::factory()->admin()->create();
    $violation = violationIn('Job Order');
    violationIn('Job Order'); // a second, non-disputed one

    $violation->document->update(['disputed_at' => now()]);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    expect($response->viewData('disputedCount'))->toBe(1);
});

test('the refresh endpoint returns only the results fragment', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order', 'Findable Stage Name');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations.refresh', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Findable Stage Name');
    $response->assertDontSee('<html', false);
    $response->assertDontSee('Browse by Category');
    $response->assertDontSee('Total Violations');
});
