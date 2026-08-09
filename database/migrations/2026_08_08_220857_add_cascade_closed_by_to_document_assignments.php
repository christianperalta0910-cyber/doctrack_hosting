<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cascade_closed_by (Feature: Decision History attribution) — when a
 * rejection cascade auto-closes a sibling's still-pending seat (see
 * WorkflowService::completeStage()'s rejection branch), that sibling's own
 * assignment row gets individual_status='rejected' too, but with nothing
 * recording WHO actually made that call. Without this, the sibling
 * approver's Decision History shows a bare "Rejected" as if it were their
 * own decision, when it was really a co-approver's. This records the
 * ACTUAL deciding approver so it can be attributed correctly wherever
 * cascade-closed rows are shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->foreignId('cascade_closed_by')->nullable()->after('reassignment_reason')
                ->constrained('users', 'user_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cascade_closed_by');
        });
    }
};
