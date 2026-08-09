<?php

use App\Events\SystemSettingsChanged;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Event;

/**
 * Coverage for the optional business-hours restriction on approver
 * decisions (SystemSetting::enforce_business_hours_decisions, off by
 * default — Admin opts in via the Workflow Config toggle). The point isn't
 * the SLA clock (already business-hours-aware and unaffected either way);
 * it's closing the gap where an approver could sit on an assignment all
 * through the paid workday and then decide it that same evening, off the
 * clock, always safely before the deadline.
 */
function restrictionAssignment(User $approver, DocumentRepository $document, WorkflowStage $stage): DocumentAssignment
{
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addDays(3),
    ]);
    seedReviewTime($approver, $document);

    return $assignment;
}

function restrictionDoc(User $originator, string $title): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => $title, 'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDays(5), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
}

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
});

it('defaults to OFF — a fresh install never blocks decisions outside business hours', function () {
    expect(SystemSetting::current()->enforce_business_hours_decisions)->toBeFalse();
});

it('does not block a decision outside business hours when the toggle is off (the default)', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-08-16 20:00:00')); // Sunday evening — non-working

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = restrictionDoc($originator, 'off-toggle.txt');
    $assignment = restrictionAssignment($approver, $document, WorkflowStage::first());

    $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ])->assertRedirect();

    expect($assignment->fresh()->individual_status)->toBe('approved');
});

it('blocks a decision outside business hours once an Admin turns the toggle on', function () {
    SystemSetting::current()->update(['enforce_business_hours_decisions' => true]);
    $this->travelTo(\Carbon\Carbon::parse('2026-08-16 20:00:00')); // Sunday evening — non-working

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = restrictionDoc($originator, 'blocked.txt');
    $assignment = restrictionAssignment($approver, $document, WorkflowStage::first());

    $response = $this->actingAs($approver)->postJson(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertStatus(403);
    expect($response->json('message'))->toContain('business hours');
    expect($assignment->fresh()->individual_status)->toBe('pending');
});

it('still allows a decision during business hours even when the toggle is on', function () {
    SystemSetting::current()->update(['enforce_business_hours_decisions' => true]);
    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 12:00:00')); // Wednesday noon — working

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = restrictionDoc($originator, 'allowed.txt');
    $assignment = restrictionAssignment($approver, $document, WorkflowStage::first());

    $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ])->assertRedirect();

    expect($assignment->fresh()->individual_status)->toBe('approved');
});

it('blocks decideBatch() outside business hours when the toggle is on', function () {
    SystemSetting::current()->update(['enforce_business_hours_decisions' => true]);
    $this->travelTo(\Carbon\Carbon::parse('2026-08-16 20:00:00'));

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = restrictionDoc($originator, 'batch-blocked.txt');
    $assignment = restrictionAssignment($approver, $document, WorkflowStage::first());

    $response = $this->actingAs($approver)->postJson(route('approver.assignments.decideBatch'), [
        'assignment_ids' => [$assignment->assignment_id],
        'decision' => 'approved',
    ]);

    $response->assertStatus(403);
    expect($assignment->fresh()->individual_status)->toBe('pending');
});

it('renders the queue with Approve/Reject disabled and an explanatory note when blocked', function () {
    SystemSetting::current()->update(['enforce_business_hours_decisions' => true]);
    $this->travelTo(\Carbon\Carbon::parse('2026-08-16 20:00:00'));

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = restrictionDoc($originator, 'view-blocked.txt');
    restrictionAssignment($approver, $document, WorkflowStage::first());

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));

    $response->assertOk();
    $response->assertSee('Decisions are currently restricted to business hours');
    $response->assertSee('data-business-hours-blocked="1"', false);
});

it('lets an Admin toggle the restriction on and off', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.systemSettings.businessHoursToggle'), [
        'enforce_business_hours_decisions' => '1',
    ])->assertRedirect();
    expect(SystemSetting::current()->enforce_business_hours_decisions)->toBeTrue();

    // Native HTML checkboxes send nothing at all when unchecked — the
    // controller must treat an absent field as "off", not validate-fail.
    $this->actingAs($admin)->post(route('admin.systemSettings.businessHoursToggle'), [])
        ->assertRedirect();
    expect(SystemSetting::current()->enforce_business_hours_decisions)->toBeFalse();
});

it('broadcasts SystemSettingsChanged so an approver with the queue already open updates instantly', function () {
    Event::fake([SystemSettingsChanged::class]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.systemSettings.businessHoursToggle'), [
        'enforce_business_hours_decisions' => '1',
    ])->assertRedirect();

    Event::assertDispatched(SystemSettingsChanged::class);
});

it('exposes valid business-hours config for the client-side ticker', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.workflow.config'));

    $response->assertOk();
    preg_match('/<meta name="business-hours" content="([^"]+)">/', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    $decoded = json_decode(html_entity_decode($matches[1]), true);
    expect($decoded)->toHaveKeys(['startMinutes', 'endMinutes', 'workingDays', 'holidays']);
    expect($decoded['startMinutes'])->toBe(9 * 60);
    expect($decoded['endMinutes'])->toBe(17 * 60);
    expect($decoded['workingDays'])->toBe([1, 2, 3, 4, 5, 6]);
});
