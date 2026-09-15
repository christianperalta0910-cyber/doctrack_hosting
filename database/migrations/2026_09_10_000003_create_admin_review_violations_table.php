<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors sla_violations (the approver-side equivalent), but for Admin
 * missing their own review_due_at window on an auto-approved document —
 * logged in AdminController::reviewAutoApproval(), attributed to
 * whichever Admin actually performed the (late) review, since Admin's
 * queue is shared rather than individually assigned the way an
 * approver's seat is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_review_violations', function (Blueprint $table) {
            $table->id('violation_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('document_assignments', 'assignment_id')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->timestamp('violation_timestamp');
            $table->unsignedInteger('duration_overdue'); // minutes past review_due_at
            $table->string('stage_name');

            $table->index('admin_id');
            $table->index('violation_timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_review_violations');
    }
};
