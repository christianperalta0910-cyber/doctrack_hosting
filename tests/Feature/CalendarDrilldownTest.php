<?php

use App\Models\DocumentRepository;
use App\Models\User;

/**
 * Regression coverage for clicking a date on the Admin Calendar — shows
 * that day's documents live via the shared drill-down modal
 * (AdminController::documentsOnDate()). Verified live during development
 * but never left with a permanent test.
 */
it('shows only documents uploaded on the clicked date', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $onDate = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'on-date.txt', 'file_path' => 'documents/on-date.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'processing', 'ml_category' => null,
    ]);
    $onDate->upload_date = '2026-08-05 10:00:00';
    $onDate->saveQuietly();

    $otherDate = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'other-date.txt', 'file_path' => 'documents/other-date.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'processing', 'ml_category' => null,
    ]);
    $otherDate->upload_date = '2026-08-06 10:00:00';
    $otherDate->saveQuietly();

    $response = $this->actingAs($admin)->get(route('admin.calendar.documentsOnDate', '2026-08-05'));

    $response->assertOk();
    $response->assertSee('on-date.txt');
    $response->assertDontSee('other-date.txt');
});

it('renders the calendar page with the poll/refresh wiring scoped to the viewed month', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.calendar', ['month' => '2026-08']));

    $response->assertOk();
    $response->assertSee('calendar-wrapper', false);
    $response->assertSee(route('admin.calendar.poll', ['month' => '2026-08']), false);
});
