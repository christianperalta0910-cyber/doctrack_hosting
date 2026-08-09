<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data backfill (Feature: Decision History attribution) — rows
 * cascade-closed BEFORE the cascade_closed_by column existed still carry
 * the old generic "Auto-closed — document rejected during approval."
 * placeholder and a null cascade_closed_by, so Decision History shows
 * them as an unattributed/"by You" rejection with no real reason. For
 * each affected document, finds the ONE assignment that holds the actual
 * rejection (comments distinct from the generic placeholder — a document
 * only ever has one genuine reject, since a reject immediately cascades
 * and finalizes it) and copies its user_id/comments onto every
 * generic-text sibling. Deliberately irreversible: the original generic
 * text carried no information worth restoring.
 */
return new class extends Migration
{
    private const GENERIC_TEXT = 'Auto-closed — document rejected during approval.';

    public function up(): void
    {
        $affectedDocumentIds = DB::table('document_assignments')
            ->where('comments', self::GENERIC_TEXT)
            ->whereNull('cascade_closed_by')
            ->pluck('document_id')
            ->unique();

        foreach ($affectedDocumentIds as $documentId) {
            $realDecision = DB::table('document_assignments')
                ->where('document_id', $documentId)
                ->where('individual_status', 'rejected')
                ->where(function ($q) {
                    $q->where('comments', '!=', self::GENERIC_TEXT)->orWhereNull('comments');
                })
                ->first();

            if (!$realDecision) {
                continue; // can't determine the actual decider — leave as-is rather than guess
            }

            DB::table('document_assignments')
                ->where('document_id', $documentId)
                ->where('comments', self::GENERIC_TEXT)
                ->whereNull('cascade_closed_by')
                ->update([
                    'comments' => $realDecision->comments,
                    'cascade_closed_by' => $realDecision->user_id,
                ]);
        }
    }

    public function down(): void
    {
        // Not reversible — the original generic placeholder text is gone
        // and not worth restoring even if it were recoverable.
    }
};
