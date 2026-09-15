<?php

use App\Models\AdminViolation;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Services\SlaService;

function pendingMlReviewDoc(int $hoursOverdue = 1): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'low-confidence.txt', 'file_path' => 'documents/lc.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'ml_confidence' => 40, 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_review_status' => 'pending', 'ml_review_due_at' => now()->subHours($hoursOverdue),
    ]);
}

test('a low-confidence classification past its 6-hour window is logged as a late_ml_review violation and admins are notified', function () {
    $admin = User::factory()->admin()->create();
    $document = pendingMlReviewDoc();

    app(SlaService::class)->sweep();

    $violation = AdminViolation::where('document_id', $document->document_id)->where('violation_type', 'late_ml_review')->first();
    expect($violation)->not->toBeNull()
        ->and($violation->assignment_id)->toBeNull()
        ->and($violation->stage_name)->toBeNull()
        ->and($violation->resolved_at)->toBeNull();

    $notification = NotificationRecord::where('recipient_id', $admin->user_id)->where('document_id', $document->document_id)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->message_body)->toContain('classification review');
});

test('a low-confidence classification still within its window is not flagged', function () {
    $document = pendingMlReviewDoc(hoursOverdue: -2); // due 2h from now, not yet overdue

    app(SlaService::class)->sweep();

    expect(AdminViolation::where('document_id', $document->document_id)->count())->toBe(0);
});

test('confirming or rejecting the document resolves its open late_ml_review violation', function () {
    $admin = User::factory()->admin()->create();
    $document = pendingMlReviewDoc();
    seedReviewTime($admin, $document);

    app(SlaService::class)->sweep();
    expect(AdminViolation::where('document_id', $document->document_id)->whereNull('resolved_at')->exists())->toBeTrue();

    $this->actingAs($admin)->post(route('admin.ml.review', $document), [
        'action' => 'confirm',
        'category' => 'Job Order',
    ])->assertRedirect();

    expect(AdminViolation::where('document_id', $document->document_id)->whereNull('resolved_at')->exists())->toBeFalse();
});

test('the reminder does not repeat within the same hour', function () {
    User::factory()->admin()->create();
    $document = pendingMlReviewDoc();

    app(SlaService::class)->sweep();
    $countAfterFirst = NotificationRecord::where('document_id', $document->document_id)->count();

    app(SlaService::class)->sweep();
    $countAfterSecond = NotificationRecord::where('document_id', $document->document_id)->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});
