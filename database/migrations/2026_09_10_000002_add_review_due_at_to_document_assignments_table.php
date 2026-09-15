<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The soft deadline an Admin has to review a stage the system
 * auto-approved without a human decision — set once, at the moment of
 * auto-approval (see SlaService::autoApproveOne()), to now + 6 hours,
 * capped so it never runs past the document's own due date (same
 * safeguard the old admin-grace calculation already used). Crossing it
 * doesn't block or trigger anything automatically — it's a soft marker,
 * checked only when an Admin actually reviews the item (see
 * AdminController::reviewAutoApproval()), to decide whether that review
 * counts as late.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->timestamp('review_due_at')->nullable()->after('admin_review_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropColumn('review_due_at');
        });
    }
};
