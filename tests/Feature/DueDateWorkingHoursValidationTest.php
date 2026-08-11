<?php

use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Http\UploadedFile;

/**
 * Regression coverage for the switch from "silently shift a non-working
 * due date to the next working day" to "reject it outright at submission
 * time" — see WorkflowService::isDueDateWithinWorkingHours() and its call
 * sites in DocumentController::store()/resubmit() and the API equivalent.
 */
beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    // 2026-08-12 is a Wednesday, comfortably within business hours.
    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 10:00:00'));
});

it('rejects a due date that falls on a non-working day (Sunday)', function () {
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('doc.txt', 'legitimate content here')],
        'due_date' => '2026-08-16 10:00', // Sunday
    ]);

    $response->assertSessionHasErrors('due_date');
    expect(session('errors')->getBag('default')->first('due_date'))->toContain('working hours');
    expect(DocumentRepository::count())->toBe(0);
});

it('rejects a due date that falls outside working hours on an otherwise working day', function () {
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('doc.txt', 'legitimate content here')],
        'due_date' => '2026-08-12 20:00', // Wednesday, but 8 PM — after the 5 PM cutoff
    ]);

    $response->assertSessionHasErrors('due_date');
    expect(DocumentRepository::count())->toBe(0);
});

it('accepts a due date within working hours with no due-date error', function () {
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('doc.txt', 'legitimate content here')],
        'due_date' => now()->addHours(4)->format('Y-m-d\TH:i'), // Wednesday 2 PM
    ]);

    $response->assertSessionDoesntHaveErrors('due_date');
    expect(DocumentRepository::count())->toBe(1);
});

it('rejects a non-working due date on resubmission the same way it does on first submission', function () {
    $originator = User::factory()->originator()->create();
    $rejected = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'rejected.txt', 'file_path' => 'documents/rejected.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'rejected',
        'ml_category' => 'Job Order',
    ]);

    $response = $this->actingAs($originator)->post(route('originator.documents.resubmit', $rejected), [
        'file' => UploadedFile::fake()->createWithContent('revised.txt', 'Revised content.'),
        'due_date' => '2026-08-16 10:00', // Sunday
    ]);

    $response->assertSessionHasErrors('due_date');
    expect(DocumentRepository::where('previous_version_id', $rejected->document_id)->exists())->toBeFalse();
});

it('rejects a non-working due date via the API with the same validation', function () {
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator, 'sanctum')->postJson('/api/v1/documents', [
        'files' => [UploadedFile::fake()->createWithContent('doc.txt', 'some document content ' . str_repeat('word ', 20))],
        'due_date' => '2026-08-16 10:00', // Sunday
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('due_date');
    expect(DocumentRepository::count())->toBe(0);
});
