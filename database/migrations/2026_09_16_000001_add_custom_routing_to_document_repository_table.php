<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature: originator-directed approval routing — an alternative to the
 * automatic, ML-category-driven pipeline every document has gone through
 * until now. An originator can bypass that pipeline and hand-pick the
 * approver(s) for a specific document instead (see WorkflowService::
 * routeToCustomApprovers()), for two distinct reasons, both recorded in
 * desired_routing:
 *   - 'custom': the document IS a real, known category, but doesn't need
 *     the full pipeline for this particular submission (e.g. "just needs
 *     the head's sign-off") — classification and validation still run
 *     and matter exactly as they do for an 'auto' document; only routing
 *     changes.
 *   - 'unrelated': the document genuinely doesn't belong to any of the
 *     trained categories at all. ml_category still holds the
 *     classifier's best guess (kept for reference/reporting — the
 *     classifier is closed-set, it always returns SOME known category),
 *     but that guess stops being authoritative for validation (see
 *     ValidationService::validateGeneric()) and never gates/drives
 *     routing.
 *
 * desired_routing is the originator's choice, made at upload time and
 * kept permanently (audit trail / reporting) — separate from
 * pending_custom_routing_at (see below), which is only about whether
 * that choice has actually been acted on yet.
 *
 * Kept as separate nullable columns rather than a new global_status enum
 * value — see the disputed_at/security_blocked migrations' docblocks for
 * why: global_status is a hard DB-level ENUM, and "awaiting the
 * originator's approver pick" is an orthogonal, temporary in-between
 * state layered on top of the normal classified_validated status, not a
 * replacement for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->string('desired_routing', 20)->default('auto')->after('global_status');

            // Set once classification/validation finish and this document
            // is ready to route, but is waiting on the originator to pick
            // approver(s) instead of being auto-routed. Cleared the moment
            // WorkflowService::routeToCustomApprovers() actually runs.
            $table->timestamp('pending_custom_routing_at')->nullable()->after('desired_routing');

            // Permanent record that this document WAS routed by an
            // originator's own approver pick rather than the automatic
            // pipeline — for the audit trail / a "Custom Routed" badge,
            // kept even after routing completes (unlike the pending
            // timestamp above, which only covers the waiting window).
            $table->boolean('custom_routed')->default(false)->after('pending_custom_routing_at');

            // How long Admin has to confirm/correct a low-confidence
            // classification before it's logged as a late review (see
            // SlaService::trackLateMlReviews()) — mirrors the existing
            // 6-hour admin review window already used for late auto-
            // approval reviews (SlaService::ADMIN_REVIEW_WINDOW_HOURS),
            // not a separately invented number.
            $table->timestamp('ml_review_due_at')->nullable()->after('ml_review_status');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn(['desired_routing', 'pending_custom_routing_at', 'custom_routed', 'ml_review_due_at']);
        });
    }
};
