<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    // 2026-08-12 is a Wednesday, comfortably within business hours — pins
    // "now" so business-hours-aware SLA math (addBusinessMinutes, etc.)
    // behaves the same no matter when this suite actually runs.
    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 10:00:00'));
});

function classifiedJobOrderDueIn(User $originator, int $minutesUntilDue): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'urgent-test.txt',
        'file_path' => 'documents/urgent-test.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addMinutes($minutesUntilDue),
        'global_status' => 'classified_validated',
    ]);
}

function escalatableAssignment(int $minutesUntilDue = 60 * 24): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'grace-test.txt',
        'file_path' => 'documents/grace-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addMinutes($minutesUntilDue),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
    ]);
}

// --- Approver "born Urgent" notification (WorkflowService::assignStage()) ---

test('an approver gets a separate URGENT notification when their new assignment is born with a very short window', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    // Just over the 1-hour minimum due-date buffer -> the tiered SLA
    // formula gives a flat 15-minute window, which is always <= the
    // 30-minute Urgent threshold.
    $document = classifiedJobOrderDueIn($originator, 61);
    app(WorkflowService::class)->routeToWorkflow($document);

    expect(NotificationRecord::where('recipient_id', $approver->user_id)
        ->where('priority', 'high')
        ->where('message_body', 'like', '%URGENT%very short window to act%')
        ->exists())->toBeTrue();
});

test('an approver does NOT get the extra URGENT notification when their new assignment has a comfortable window', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $document = classifiedJobOrderDueIn($originator, 60 * 24 * 3); // 3 days out
    app(WorkflowService::class)->routeToWorkflow($document);

    expect(NotificationRecord::where('recipient_id', $approver->user_id)
        ->where('message_body', 'like', '%very short window to act%')
        ->exists())->toBeFalse();
});

// --- Admin "short grace window" notification (SlaService::escalate()) ---

test('admins get a separate URGENT notification when the computed grace window is already short', function () {
    $admin = User::factory()->admin()->create();
    // due_date only 2 hours out -> the flat 6-hour grace would blow past
    // it, so adminGraceExpiresAt() halves the remainder (~1 hour) — well
    // under the 2-hour short-grace threshold.
    $assignment = escalatableAssignment(120);

    app(SlaService::class)->escalate($assignment);

    expect(NotificationRecord::where('recipient_id', $admin->user_id)
        ->where('priority', 'high')
        ->where('message_body', 'like', '%very short grace window%')
        ->exists())->toBeTrue();
});

test('admins do NOT get the short-grace notification when the flat 6-hour grace window comfortably fits before due_date', function () {
    $admin = User::factory()->admin()->create();
    $assignment = escalatableAssignment(60 * 24 * 3); // 3 days out -> flat 6h grace applies

    app(SlaService::class)->escalate($assignment);

    expect(NotificationRecord::where('recipient_id', $admin->user_id)
        ->where('message_body', 'like', '%very short grace window%')
        ->exists())->toBeFalse();
});

test('escalate() no longer sends any email — SLA escalation is in-app/notification only now', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $assignment = escalatableAssignment();

    app(SlaService::class)->escalate($assignment);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

// --- Two-stage auto-approval review reminder (SlaService::remindUnreviewedAutoApprovals()) ---

function unreviewedAutoApproval(int $minutesUntilDue): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'unreviewed-auto-approval.txt',
        'file_path' => 'documents/unreviewed-auto-approval.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addMinutes($minutesUntilDue),
        'global_status' => 'auto_approved',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'auto_approved' => true,
        'acted_at' => now()->subHour(),
        'sla_expires_at' => now()->subHours(13),
    ]);
}

test('an unreviewed auto-approval with an approaching due date triggers one reminder to every admin', function () {
    $admin = User::factory()->admin()->create();
    $assignment = unreviewedAutoApproval(20); // due in 20 minutes, under the 30-minute reminder threshold

    $sent = app(SlaService::class)->sweep()['review_reminders_sent'];

    expect($sent)->toBe(1)
        ->and($assignment->fresh()->review_reminder_sent_at)->not->toBeNull()
        ->and(NotificationRecord::where('recipient_id', $admin->user_id)
            ->where('priority', 'high')
            ->where('message_body', 'like', '%auto-approved%still hasn\'t been reviewed%')
            ->exists())->toBeTrue();
});

test('an unreviewed auto-approval with a due date still far away does not trigger a reminder yet', function () {
    unreviewedAutoApproval(60 * 5); // due in 5 hours — well outside the 30-minute window

    $sent = app(SlaService::class)->sweep()['review_reminders_sent'];

    expect($sent)->toBe(0);
});

test('the reminder only fires once — a second sweep does not re-notify', function () {
    User::factory()->admin()->create();
    unreviewedAutoApproval(20);

    $first = app(SlaService::class)->sweep()['review_reminders_sent'];
    $second = app(SlaService::class)->sweep()['review_reminders_sent'];

    expect($first)->toBe(1)->and($second)->toBe(0);
});

test('a reviewed auto-approval never triggers the reminder, regardless of due date', function () {
    $assignment = unreviewedAutoApproval(20);
    $assignment->update(['admin_reviewed_at' => now(), 'admin_review_outcome' => 'confirmed']);

    $sent = app(SlaService::class)->sweep()['review_reminders_sent'];

    expect($sent)->toBe(0);
});
