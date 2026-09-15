<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Superseded by admin_violations (violation_type = 'late_review') — never had a UI surface and had zero rows at replacement time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('admin_review_violations');
    }

    public function down(): void
    {
        Schema::create('admin_review_violations', function (Blueprint $table) {
            $table->id('violation_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id');
            $table->foreignId('assignment_id')->constrained('document_assignments', 'assignment_id');
            $table->foreignId('admin_id')->constrained('users', 'user_id');
            $table->timestamp('violation_timestamp');
            $table->unsignedInteger('duration_overdue');
            $table->string('stage_name');
            $table->index('admin_id');
            $table->index('violation_timestamp');
        });
    }
};
