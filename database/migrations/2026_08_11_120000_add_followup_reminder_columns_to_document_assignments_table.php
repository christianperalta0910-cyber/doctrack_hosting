<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether the ONE follow-up reminder for each of two "already sent
 * once, never again" urgent notifications has already gone out — without
 * these, the periodic sweep would either never re-notify (leaving the
 * original one-shot alert as the only chance anyone sees it) or, if it
 * simply re-checked the same condition every cycle, would spam the same
 * reminder every 5 minutes instead of sending it once as a final call.
 *
 * - urgent_reminder_sent_at: the approver's "your assignment is about to
 *   breach its SLA window" follow-up (see SlaService::
 *   remindStillUrgentApprovers()) — the FIRST urgent ping happens the
 *   moment an assignment is born with a short window (see WorkflowService::
 *   assignStage()); this is the one-time follow-up right before it
 *   actually escalates.
 * - grace_reminder_sent_at: the Admin's "this will auto-approve very soon"
 *   follow-up (see SlaService::remindShortGraceWindows()) — the FIRST
 *   urgent ping happens at the moment of escalation if the grace window is
 *   already short (see SlaService::escalate()); this is the one-time
 *   follow-up right before the system actually auto-approves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->timestamp('urgent_reminder_sent_at')->nullable()->after('review_reminder_sent_at');
            $table->timestamp('grace_reminder_sent_at')->nullable()->after('urgent_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropColumn(['urgent_reminder_sent_at', 'grace_reminder_sent_at']);
        });
    }
};
