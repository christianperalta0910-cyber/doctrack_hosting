<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * needs_approver (Feature: Unassigned Documents module) — a pending seat
 * left with genuinely nobody eligible after its approver was deactivated
 * (strict category+stage match, same eligibility rule as normal routing —
 * see WorkflowService::markNeedsApprover()). Deliberately its own flag,
 * separate from escalated_to_admin: that field feeds the SLA Override
 * Queue and, once resolved there, gets recorded as if it were an SLA
 * violation — unfair to an approver who did nothing wrong except get
 * deactivated, and not an SLA concern at all. needs_approver keeps this
 * entirely out of SLA/violation bookkeeping.
 *
 * Also migrates any assignment already stuck in the OLD path (escalated
 * via the since-removed SlaService::escalateForReassignmentFailure(), the
 * former "no_eligible_approver" branch of the SLA Override Queue) over to
 * the new flag, so nothing already-orphaned is left behind in the queue it
 * no longer belongs in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->boolean('needs_approver')->default(false)->after('escalation_reason');
            $table->timestamp('needs_approver_at')->nullable()->after('needs_approver');
        });

        DB::table('document_assignments')
            ->where('escalated_to_admin', true)
            ->where('escalation_reason', 'no_eligible_approver')
            ->whereNull('admin_override_at')
            ->where('individual_status', 'pending')
            ->update([
                'needs_approver' => true,
                'needs_approver_at' => now(),
                'escalated_to_admin' => false,
                'escalation_reason' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropColumn(['needs_approver', 'needs_approver_at']);
        });
    }
};
