<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether the escalated "this auto-approved stage still hasn't been
 * reviewed and its document's due date is approaching" reminder has already
 * been sent (see SlaService::remindUnreviewedAutoApprovals()) — without
 * this, the periodic sweep would re-send the same urgent reminder on every
 * single cycle for as long as it stays unreviewed, instead of once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->timestamp('review_reminder_sent_at')->nullable()->after('admin_review_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropColumn('review_reminder_sent_at');
        });
    }
};
