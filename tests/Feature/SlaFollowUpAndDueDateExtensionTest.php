<?php

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Carbon\Carbon;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    // 2026-08-12 is a Wednesday, comfortably within business hours.
    $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));
});

// --- Gap 1a: WorkflowService::extendDueDateIfReviewQueueAteTheBuffer() ---

function heldDocumentDueIn(User $originator, int $minutesUntilDue): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'held-in-review-queue.txt',
        'file_path' => 'documents/held-in-review-queue.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addMinutes($minutesUntilDue),
        'global_status' => 'classified_validated',
    ]);
}

test('routing a document that sat too long in the review queue extends its due date back to the minimum buffer', function () {
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();
    // Only 5 real minutes left before due_date — well under the 60-minute
    // minimum buffer — simulating an Admin only just now clearing this
    // document out of the ML/readability review queue.
    $document = heldDocumentDueIn($originator, 5);

    app(WorkflowService::class)->routeToWorkflow($document);

    $fresh = $document->fresh();
    expect($fresh->due_date->equalTo(Carbon::parse('2026-08-12 11:00:00')))->toBeTrue()
        ->and(AuditLog::where('document_id', $document->document_id)->where('action_type', 'due_date_extended')->exists())->toBeTrue()
        ->and(NotificationRecord::where('recipient_id', $originator->user_id)
            ->where('message_body', 'like', '%had its due date extended%')
            ->exists())->toBeTrue();
});

test('routing a document with plenty of runway left does not touch its due date', function () {
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();
    $document = heldDocumentDueIn($originator, 60 * 3); // 3 hours out — comfortably over the buffer
    $originalDueDate = $document->due_date;

    app(WorkflowService::class)->routeToWorkflow($document);

    expect($document->fresh()->due_date->equalTo($originalDueDate))->toBeTrue()
        ->and(AuditLog::where('document_id', $document->document_id)->where('action_type', 'due_date_extended')->exists())->toBeFalse();
});

// --- Gap 1b: DocumentRepository::dueDateUrgencyRank()/Label() ---

test('dueDateUrgencyRank() classifies a near due date as Urgent and a distant one as Low', function () {
    $originator = User::factory()->originator()->create();
    $urgent = heldDocumentDueIn($originator, 10); // 10 min left
    $low = heldDocumentDueIn($originator, 60 * 24); // a day out

    expect($urgent->dueDateUrgencyRank())->toBe(1)
        ->and($urgent->dueDateUrgencyLabel())->toBe('Urgent')
        ->and($low->dueDateUrgencyRank())->toBe(3)
        ->and($low->dueDateUrgencyLabel())->toBe('Low');
});

test('dueDateUrgencyRank() returns null, not an error, when a document has no due date yet', function () {
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'no-due-date.txt', 'file_path' => 'documents/no-due-date.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'global_status' => 'classified_validated',
    ]);

    expect($document->dueDateUrgencyRank())->toBeNull()
        ->and($document->dueDateUrgencyLabel())->toBeNull();
});

// --- Gap 2a: SlaService::remindStillUrgentApprovers() ---

function pendingSeatDueIn(User $originator, User $approver, int $minutesUntilDue): DocumentAssignment
{
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'followup-test.txt', 'file_path' => 'documents/followup-test.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addMinutes($minutesUntilDue),
        'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addMinutes(15), // 15 real minutes left -> Urgent
    ]);
}

test('a still-pending Urgent assignment gets exactly one final-call reminder', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $assignment = pendingSeatDueIn($originator, $approver, 60 * 24);

    $sent = app(SlaService::class)->sweep()['urgent_approver_reminders_sent'];

    expect($sent)->toBe(1)
        ->and($assignment->fresh()->urgent_reminder_sent_at)->not->toBeNull()
        ->and(NotificationRecord::where('recipient_id', $approver->user_id)
            ->where('priority', 'high')
            ->where('message_body', 'like', '%FINAL CALL%breach its SLA window%')
            ->exists())->toBeTrue();
});

test('a pending assignment with a comfortable window gets no final-call reminder', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'comfortable.txt', 'file_path' => 'documents/comfortable.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(5), // comfortably not urgent
    ]);

    $sent = app(SlaService::class)->sweep()['urgent_approver_reminders_sent'];

    expect($sent)->toBe(0);
});

test('the approver final-call reminder only fires once — a second sweep does not re-notify', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    pendingSeatDueIn($originator, $approver, 60 * 24);

    $first = app(SlaService::class)->sweep()['urgent_approver_reminders_sent'];
    $second = app(SlaService::class)->sweep()['urgent_approver_reminders_sent'];

    expect($first)->toBe(1)->and($second)->toBe(0);
});

// --- Gap 2b: SlaService::remindShortGraceWindows() ---

function escalatedSeatDueIn(User $originator, int $minutesUntilDue, int $escalatedMinutesAgo): DocumentAssignment
{
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'grace-followup-test.txt', 'file_path' => 'documents/grace-followup-test.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addMinutes($minutesUntilDue),
        'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
        'escalated_to_admin' => true, 'escalated_at' => now()->subMinutes($escalatedMinutesAgo),
    ]);
}

test('an escalated assignment about to be auto-approved gets exactly one grace final-call reminder', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    // due_date only 20 minutes out -> the flat 6h grace would blow past
    // it, so adminGraceExpiresAt() halves the remainder to ~10 minutes —
    // well under the 30-minute follow-up threshold.
    $assignment = escalatedSeatDueIn($originator, 20, 1);

    $sent = app(SlaService::class)->sweep()['grace_reminders_sent'];

    expect($sent)->toBe(1)
        ->and($assignment->fresh()->grace_reminder_sent_at)->not->toBeNull()
        ->and(NotificationRecord::where('recipient_id', $admin->user_id)
            ->where('priority', 'high')
            ->where('message_body', 'like', '%FINAL CALL%auto-approved by the system very soon%')
            ->exists())->toBeTrue();
});

test('an escalated assignment with a distant grace deadline gets no grace final-call reminder', function () {
    User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    escalatedSeatDueIn($originator, 60 * 24 * 3, 5); // 3 days out -> flat 6h grace applies, well beyond 30 min

    $sent = app(SlaService::class)->sweep()['grace_reminders_sent'];

    expect($sent)->toBe(0);
});

test('the grace final-call reminder only fires once — a second sweep does not re-notify', function () {
    User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    escalatedSeatDueIn($originator, 20, 1);

    $first = app(SlaService::class)->sweep()['grace_reminders_sent'];
    $second = app(SlaService::class)->sweep()['grace_reminders_sent'];

    expect($first)->toBe(1)->and($second)->toBe(0);
});
