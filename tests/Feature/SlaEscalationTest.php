<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use Carbon\Carbon;

function pendingAssignment(array $overrides = []): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'test.txt',
        'file_path' => 'documents/test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create(array_merge([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
    ], $overrides));
}

test('escalating a breached assignment flags it, logs a violation, and notifies admins', function () {
    $admin = User::factory()->admin()->create();
    $assignment = pendingAssignment();

    app(SlaService::class)->escalate($assignment);

    expect($assignment->fresh()->escalated_to_admin)->toBeTrue()
        ->and(SlaViolation::where('assignment_id', $assignment->assignment_id)->exists())->toBeTrue()
        ->and(NotificationRecord::where('recipient_id', $admin->user_id)->where('priority', 'high')->exists())->toBeTrue();
});

test('an admin override resolves the assignment directly, bypassing the original approver', function () {
    $admin = User::factory()->admin()->create();
    $assignment = pendingAssignment();
    app(SlaService::class)->escalate($assignment);

    app(SlaService::class)->adminOverride($assignment, $admin, 'approved', 'Resolved on the approver\'s behalf.');

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved')
        ->and($fresh->admin_override_by)->toBe($admin->user_id)
        ->and($fresh->admin_override_at)->not->toBeNull();
});

test('an assignment still unresolved past the admin grace window is auto-approved', function () {
    // adminGraceExpiresAt() (the single source of truth the backstop sweep
    // now reads — see SlaService::autoApproveUnresolved()) is driven by
    // escalated_at, not sla_expires_at — a real escalated row always has
    // both set together by SlaService::escalate(), so this needs both too.
    $assignment = pendingAssignment([
        'sla_expires_at' => now()->subHours(7),
        'escalated_to_admin' => true,
        'escalated_at' => now()->subHours(7), // past the 6-hour grace window (config('sla.admin_grace_hours'))
    ]);

    $count = app(SlaService::class)->sweep()['auto_approved'];

    expect($count)->toBe(1)
        ->and($assignment->fresh()->individual_status)->toBe('approved')
        ->and($assignment->fresh()->auto_approved)->toBeTrue();
});

test('an escalated assignment still within the grace window is left alone', function () {
    $assignment = pendingAssignment([
        'sla_expires_at' => now()->subHours(2),
        'escalated_to_admin' => true,
        'escalated_at' => now()->subHours(2), // well within the 6-hour grace window
    ]);

    $count = app(SlaService::class)->sweep()['auto_approved'];

    expect($count)->toBe(0)
        ->and($assignment->fresh()->individual_status)->toBe('pending');
});

// Regression coverage for the admin grace period no longer using ALL the
// remaining time before due_date when the flat 6-hour window would exceed
// it — only HALF of it, deliberately reserving the other half as a real
// window for the post-auto-approval admin review to happen before
// due_date arrives (see DocumentAssignment::adminGraceExpiresAt()'s
// docblock for the full reasoning).
test('adminGraceExpiresAt() uses HALF the remaining time, not all of it, when the flat 6-hour window would exceed due_date', function () {
    $assignment = pendingAssignment();
    $assignment->document->update(['due_date' => now()->addHours(2)]); // sooner than the flat 6-hour window
    app(SlaService::class)->escalate($assignment->fresh());

    $graceExpiresAt = $assignment->fresh()->adminGraceExpiresAt();

    // 2 hours remaining, halved -> ~1 hour, not the full 2.
    expect($graceExpiresAt->diffInMinutes(now()->addHours(1), true))->toBeLessThan(1)
        ->and($graceExpiresAt->lessThan(now()->addHours(2)))->toBeTrue()
        ->and($graceExpiresAt->lessThan(now()->addHours(6)))->toBeTrue();
});

test('adminGraceExpiresAt() uses the full flat window when due_date is comfortably later', function () {
    $assignment = pendingAssignment();
    $assignment->document->update(['due_date' => now()->addDays(3)]); // well past the flat 6-hour window
    app(SlaService::class)->escalate($assignment->fresh());

    $graceExpiresAt = $assignment->fresh()->adminGraceExpiresAt();

    expect($graceExpiresAt->diffInMinutes(now()->addHours(6), true))->toBeLessThan(1);
});

test('the backstop sweep auto-approves once the document due_date passes, even before the flat grace window elapses', function () {
    $assignment = pendingAssignment([
        'sla_expires_at' => now()->subHours(2),
        'escalated_to_admin' => true,
        'escalated_at' => now()->subHours(2), // NOT past the flat 6-hour grace window on its own
    ]);
    $assignment->document->update(['due_date' => now()->subMinutes(5)]); // but due_date already passed

    $count = app(SlaService::class)->sweep()['auto_approved'];

    expect($count)->toBe(1)
        ->and($assignment->fresh()->individual_status)->toBe('approved');
});
