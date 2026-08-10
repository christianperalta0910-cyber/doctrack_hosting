<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalForecastService;
use App\Services\WorkflowService;

function forecastDoc(User $originator, string $category = 'Job Order'): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'forecast.txt',
        'file_path' => 'documents/forecast.txt',
        'mime_type' => 'text/plain',
        'ml_category' => $category,
        'is_validated' => true,
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
    ]);
}

test('returns null when the category has no historical decisions yet', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();

    $document = forecastDoc($originator);
    app(WorkflowService::class)->routeToWorkflow($document);

    expect(app(ApprovalForecastService::class)->estimateFor($document))->toBeNull();
});

test('returns null for a document with no category yet', function () {
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'unclassified.txt', 'file_path' => 'documents/unclassified.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'processing',
    ]);

    expect(app(ApprovalForecastService::class)->estimateFor($document))->toBeNull();
});

test('returns null once the document is already fully resolved', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = forecastDoc($originator);
    $workflow->routeToWorkflow($document);
    $workflow->decide(DocumentAssignment::where('document_id', $document->document_id)->first(), $approver, 'approved');

    expect(app(ApprovalForecastService::class)->estimateFor($document->fresh()))->toBeNull();
});

test('produces a non-null estimate once historical decisions exist for the category, scaled by remaining stages', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage One', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage Two', 'sequence_order' => 2]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // A fully-decided prior document establishes historical decision times
    // for this category — without this, there's nothing to average.
    $history = forecastDoc($originator);
    $workflow->routeToWorkflow($history);
    foreach (DocumentAssignment::where('document_id', $history->document_id)->get() as $assignment) {
        $workflow->decide($assignment, $approver, 'approved');
    }

    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);

    $estimate = app(ApprovalForecastService::class)->estimateFor($current);

    expect($estimate)->not->toBeNull()
        ->and($estimate->totalSeconds)->toBeGreaterThanOrEqual(0);
});

test('pads the estimate for a next-stage approver who already has a deep pending queue', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);
    $forecast = app(ApprovalForecastService::class);

    // History so avg-per-stage isn't null AND non-zero — decided a
    // simulated hour after routing, so the queue-depth padding below has
    // something non-trivial to multiply against.
    $history = forecastDoc($originator);
    $workflow->routeToWorkflow($history);
    $this->travel(1)->hours();
    $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');
    $this->travelBack();

    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);
    $baseline = $forecast->estimateFor($current);

    // Stack a bunch of unrelated pending work onto the sole eligible
    // approver, then measure the same document's estimate again — the
    // padded estimate should come back strictly higher than the baseline.
    for ($i = 0; $i < 5; $i++) {
        $busyDoc = forecastDoc($originator);
        $workflow->routeToWorkflow($busyDoc);
    }
    $padded = $forecast->estimateFor($current->fresh());

    expect($baseline)->not->toBeNull()
        ->and($padded)->not->toBeNull()
        ->and($padded->totalSeconds)->toBeGreaterThan($baseline->totalSeconds);
});

test('the rendered "Est. Approval by" respects business hours instead of raw wall-clock addition', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // Friday, 15 minutes before closing — a big enough estimate (below)
    // spills well past 5 PM, which is exactly what a plain
    // now()->addSeconds() would have shown before this was fixed.
    $this->travelTo(\Carbon\Carbon::parse('2026-08-14 16:45:00'));

    // 10-hour historical decision time so avgSecondsPerStage is large
    // enough to guarantee the estimate crosses out of today's window.
    $history = forecastDoc($originator);
    $workflow->routeToWorkflow($history);
    $this->travel(10)->hours();
    $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');

    $this->travelTo(\Carbon\Carbon::parse('2026-08-14 16:45:00'));

    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));
    $response->assertOk();

    preg_match('/Est\. Approval by:<\/span>\s*([^<]+)</', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    $renderedMoment = \Carbon\Carbon::parse(trim($matches[1]));

    expect(app(\App\Services\BusinessHoursService::class)->isWithinWorkingWindow($renderedMoment))->toBeTrue();
});

test('the "Est. Approval by" estimate never exceeds the document\'s own due date', function () {
    // Regression: ApprovalForecastService averages REAL historical
    // decision times — a sparse/slow decision history (e.g. early on,
    // before real usage settles into a consistent pace) can inflate that
    // average enough that the raw forecast lands weeks or months past the
    // document's own due date, which is a nonsensical thing to show next
    // to a due date that's only a day or two away.
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 10:00:00')); // Wednesday, business hours

    // A wildly slow historical decision (30 days) — inflates
    // avgSecondsPerStage enough that the raw forecast would land weeks
    // past the current document's own due date if left unclamped.
    $history = forecastDoc($originator);
    $workflow->routeToWorkflow($history);
    $this->travel(30)->days();
    $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');

    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 10:00:00'));

    // Due in 1 day (forecastDoc()'s default) — the raw (unclamped)
    // forecast would blow well past this.
    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));
    $response->assertOk();

    preg_match('/Est\. Approval by:<\/span>\s*([^<]+)</', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    $renderedMoment = \Carbon\Carbon::parse(trim($matches[1]));

    expect($renderedMoment->lessThanOrEqualTo($current->fresh()->due_date))->toBeTrue();
});
