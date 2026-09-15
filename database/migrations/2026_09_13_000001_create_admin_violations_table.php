<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces admin_review_violations (dropped in the next migration) with a
 * single table covering BOTH admin-side violation types — see
 * App\Models\AdminViolation's docblock for why these are the same table
 * rather than two: both represent "Admin, as the fallback-responsible
 * party, missed a deadline," just at different moments in a document's
 * life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_violations', function (Blueprint $table) {
            $table->id('violation_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id');
            $table->foreignId('assignment_id')->constrained('document_assignments', 'assignment_id');
            $table->enum('violation_type', ['missed_approval', 'late_review']);
            $table->string('stage_name');
            // When this became a violation — for missed_approval, the
            // instant the no-eligible-approver deadline passed (it's
            // already resolved by the time this row exists, see
            // resolved_at); for late_review, the moment the 6-hour
            // review window first lapsed (may still be open).
            $table->timestamp('first_violated_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            // Null while still outstanding (late_review only — a
            // missed_approval row is always created already resolved,
            // since auto-approval happens in the same instant).
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['violation_type', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_violations');
    }
};
