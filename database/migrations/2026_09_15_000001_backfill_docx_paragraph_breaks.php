<?php

use App\Models\DocumentRepository;
use App\Services\TextExtractionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * Backfills ocr_text for every already-uploaded .docx document —
 * TextExtractionService::extractFromDocx() used to collapse Word's
 * paragraph breaks into a single space instead of a newline, so every
 * .docx uploaded before this fix has its stored text as one run-on
 * paragraph with no line breaks anywhere. New uploads get it right
 * automatically now; this re-extracts the same already-stored file for
 * every existing one so they read correctly too, in both the "editable
 * document" view (see WorkflowService::requestRevision()) and anywhere
 * else ocr_text is shown.
 *
 * Deliberately does NOT touch ml_category, readability_score, or
 * anything else that already drove this document's real routing
 * decision — see AdminController::recheckFlaggedDocument()'s docblock
 * for why re-classifying/re-validating an already-routed document based
 * on a later text change is a real, separate feature, not something a
 * silent backfill should ever do on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $extractor = app(TextExtractionService::class);

        DocumentRepository::query()
            ->where('mime_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->whereNotNull('ocr_text')
            ->chunkById(50, function ($documents) use ($extractor) {
                foreach ($documents as $document) {
                    if (!Storage::exists($document->file_path)) {
                        continue; // legacy/imported row with no real file behind it — nothing to re-extract
                    }

                    $tempPath = tempnam(sys_get_temp_dir(), 'docx-backfill-');
                    try {
                        file_put_contents($tempPath, Storage::get($document->file_path));
                        $text = trim($extractor->extractFromDocx($tempPath));

                        // Only overwrite if re-extraction actually produced
                        // something usable — an empty/failed result here
                        // (corrupt file, unexpected structure) must never
                        // wipe out the perfectly good text already stored.
                        if ($text !== '') {
                            $document->ocr_text = $text;
                            $document->saveQuietly();
                        }
                    } finally {
                        @unlink($tempPath);
                    }
                }
            }, 'document_id');
    }

    public function down(): void
    {
        // Not reversible — the pre-backfill text (paragraphs glued
        // together with spaces) isn't worth restoring, and there's no
        // way to distinguish a backfilled row from one that happened to
        // already look the same.
    }
};
