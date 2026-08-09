<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Regression coverage for a real reported confusion: uploading several
 * files at once with one bad file produced "The files.0 field must be a
 * file of type: ..." — a raw array index meaningless to a user picking
 * many files, giving no way to tell which one actually failed. See
 * DocumentController::store()'s $fileAttributeNames.
 */
it('names the actual failing file in a multi-file upload validation error, not a raw array index', function () {
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [
            UploadedFile::fake()->create('bad-file.exe', 10),
            UploadedFile::fake()->createWithContent('good-file.txt', 'legitimate content here'),
        ],
        'due_date' => now()->addHour()->format('Y-m-d\TH:i'),
    ]);

    $response->assertSessionHasErrors();
    $errors = session('errors')->getBag('default')->toArray();
    $message = collect($errors)->flatten()->first();

    expect($message)->toContain("'bad-file.exe'");
    expect($message)->not->toContain('files.0');
});
