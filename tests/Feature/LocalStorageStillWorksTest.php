<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Storage;

it('verifies viewFile() returns the exact uploaded bytes on the local disk (default disk resolution)', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $content = "REAL CONTENT CHECK " . uniqid();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'local-disk-verify.txt',
        'file_path' => 'documents/local-disk-verify-' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
    // Write via the DEFAULT disk (no explicit ->disk('local')) — exactly
    // what WorkflowService::ingest() now does after the fix.
    Storage::put($document->file_path, $content);

    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    $response = $this->actingAs($approver)->get(route('documents.file', $document));

    $response->assertOk();
    expect($response->streamedContent())->toBe($content);
    expect($response->headers->get('Content-Type'))->toContain('text/plain');
});
