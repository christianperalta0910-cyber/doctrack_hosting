<?php

use App\Models\DocumentRepository;
use App\Models\User;

function unrelatedDisplayDoc(User $originator, bool $pending = false): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'weird-memo.txt', 'file_path' => 'documents/weird.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'ml_confidence' => 42, 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'unrelated',
        'pending_custom_routing_at' => $pending ? now() : null,
        'custom_routed' => !$pending,
    ]);
}

test('display_category shows Unclassified for a document flagged unrelated, hiding the classifier guess', function () {
    $originator = User::factory()->originator()->create();
    $unrelated = unrelatedDisplayDoc($originator);

    $normal = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'real.txt', 'file_path' => 'documents/real.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'auto',
    ]);

    expect($unrelated->display_category)->toBe('Unclassified')
        ->and($unrelated->ml_category)->toBe('Job Order') // still stored underneath, just not displayed
        ->and($normal->display_category)->toBe('Job Order');
});

test('the submissions table shows Unclassified, not the raw guess, for an unrelated document', function () {
    $originator = User::factory()->originator()->create();
    unrelatedDisplayDoc($originator, pending: true);

    $response = $this->actingAs($originator)->get(route('originator.dashboard'));

    // Not assertDontSee('Job Order') here — the page's own "All
    // Categories" filter dropdown legitimately lists every real category
    // by name regardless of this document, so that string appearing
    // somewhere on the page isn't itself meaningful; what matters is
    // that THIS document's own row shows Unclassified.
    $response->assertOk()->assertSee('Unclassified');
});

test('the tracking page shows Unclassified and hides the confidence score for an unrelated document', function () {
    $originator = User::factory()->originator()->create();
    $document = unrelatedDisplayDoc($originator, pending: true);

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk()
        ->assertSee('Unclassified')
        ->assertDontSee('Job Order')
        ->assertDontSee('Confidence:');
});

test('the upload confirmation message says "marked as Unclassified" for an unrelated upload, not "classified as Job Order"', function () {
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(App\Services\ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => 42, 'model_id' => null]);
    app()->instance(App\Services\ClassificationService::class, $mock);

    $content = str_repeat('This is a perfectly ordinary internal memo about scheduling and staffing. ', 5);

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [\Illuminate\Http\UploadedFile::fake()->createWithContent('memo.txt', $content)],
        'due_date' => now()->addHours(4)->format('Y-m-d\TH:i'), // within business hours, comfortably past the 1-hour minimum
        'routing_mode' => 'unrelated',
    ]);

    $response->assertRedirect(route('originator.dashboard'));
    $response->assertSessionHas('status', function ($status) {
        return str_contains($status, 'marked as Unclassified') && !str_contains($status, "classified as 'Job Order'");
    });
});
