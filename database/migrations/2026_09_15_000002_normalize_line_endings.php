<?php

use App\Models\DocumentRepository;
use App\Services\TextExtractionService;
use Illuminate\Database\Migrations\Migration;

/**
 * Normalizes every already-stored document's plain text to LF-only line
 * endings (\n) — see TextExtractionService::normalizeLineEndings()'s
 * docblock for the full mechanism this closes: PHP counts \r and \n as
 * two separate characters, but a browser rendering that same text
 * collapses every \r\n pair down to a single character before it's ever
 * part of the DOM. New uploads and new revisions are normalized
 * automatically now; this catches up text that was already stored
 * before that fix shipped.
 *
 * Deliberately does NOT touch any annotation's saved start_offset/
 * end_offset, even though the text they point into is changing length —
 * this looks like it should need the same kind of adjustment the
 * 2026_09_15_000001 docx-paragraph-break backfill needed, but it's the
 * opposite case: an offset was always computed by the BROWSER, walking
 * its own already-HTML-parsed (and therefore already CRLF-collapsed) DOM
 * — never against the raw, still-\r\n-containing string this migration
 * is rewriting. So every existing offset was already correct for the
 * NORMALIZED text the whole time; the bug was that the SERVER kept
 * comparing it against the un-normalized one instead. (This was
 * confirmed the hard way — an earlier version of this migration DID
 * shift offsets here, which silently corrupted the two real annotations
 * it touched by applying a second, redundant shift on top of an offset
 * that needed none. Both were repaired by hand afterward, matched back
 * against their own selected_text snapshots.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DocumentRepository::query()
            ->whereNotNull('ocr_text')
            ->where('ocr_text', 'like', "%\r%")
            ->chunkById(50, function ($documents) {
                foreach ($documents as $document) {
                    $document->ocr_text = TextExtractionService::normalizeLineEndings($document->ocr_text);
                    $document->saveQuietly();
                }
            }, 'document_id');
    }

    public function down(): void
    {
        // Not reversible — the original CRLF-vs-LF split isn't worth
        // restoring, and there's no way to distinguish a normalized row
        // from one that never had \r to begin with.
    }
};
