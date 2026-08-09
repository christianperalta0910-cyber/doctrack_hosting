<?php

use App\Services\ClassificationService;

/**
 * Regression coverage for the train()/classify() disk round trip — the
 * model file and its sidecar now go through Storage::put()/Storage::get()
 * (config('filesystems.default')) instead of a hardcoded 'local' disk /
 * raw storage_path(), so this survives a Railway redeploy when that's
 * S3-compatible object storage instead of local disk. No prior test
 * actually called classify() at all, so this is the first real proof the
 * full write-then-read round trip works, not just that train() alone
 * doesn't throw.
 */
function roundTripSamples(string $keyword, int $count): array
{
    return collect(range(1, $count))->map(
        fn ($i) => "{$keyword} reference number {$i}. This document concerns {$keyword} matters exclusively, "
            . "filed on a different date with different details each time, sample variant {$i} of {$count}."
    )->all();
}

test('classify() correctly reloads a model trained and persisted via Storage::put()/get()', function () {
    $service = app(ClassificationService::class);

    $model = $service->train([
        'Job Order' => roundTripSamples('job order work request', 8),
        'Purchase Requisition' => roundTripSamples('purchase requisition budget item', 8),
        'Service Report' => roundTripSamples('service report technician findings', 8),
    ]);

    // A fresh piece of text closely matching one trained category —
    // never seen during training, proving this is real inference through
    // the disk-persisted model, not a memorized sample.
    $result = $service->classify(
        'This is a new job order work request document concerning routine matters, filed today.'
    );

    expect($result['category'])->toBe('Job Order');
    expect($result['model_id'])->toBe($model->model_id);
    expect($result['confidence'])->toBeGreaterThan(0.0);
});

test('model_file_path is stored as a disk-relative path, not an absolute local filesystem path', function () {
    $service = app(ClassificationService::class);

    $model = $service->train([
        'Job Order' => roundTripSamples('job order work request', 6),
        'Purchase Requisition' => roundTripSamples('purchase requisition budget item', 6),
    ]);

    expect($model->model_file_path)->toStartWith('ml_models/');
    expect($model->model_file_path)->not->toStartWith('/'); // not an absolute path
    expect(\Illuminate\Support\Facades\Storage::exists($model->model_file_path))->toBeTrue();
});
