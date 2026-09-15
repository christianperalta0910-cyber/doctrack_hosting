<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a workflow_stages row belong to exactly ONE document instead of a
 * whole category — see WorkflowService::routeToCustomApprovers(), which
 * creates one of these per originator-directed document instead of using
 * one of the admin-configured, category-wide stages every other document
 * routes through. Reuses the entire existing DocumentAssignment/
 * completeStage()/majority-vote/SLA/notification machinery unchanged —
 * from that machinery's point of view this is just an ordinary stage
 * with one seat (or several, for multiple hand-picked approvers), it
 * just happens to belong to a single document rather than being shared.
 *
 * Null (the default, and the case for every existing row) means "a real,
 * admin-configured, category-wide stage" — the normal, unchanged
 * meaning. Every query that lists/manages the ADMIN-CONFIGURED pipeline
 * (Workflow Config, approver stage assignment, category routing lookups)
 * must filter to null explicitly (see WorkflowStage::scopeConfigured())
 * so a one-off document-scoped stage never shows up there or gets reused
 * for some OTHER document's auto-routing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('stage_id')
                ->constrained('document_repository', 'document_id')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
