<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SYSTEM_SETTINGS — singleton row (enforced at the application layer via
 * SystemSetting::current(), not a DB constraint) holding system-wide
 * behavioral toggles that don't warrant their own dedicated table. Starts
 * with just enforce_business_hours_decisions (Feature: block an approver's
 * Approve/Reject outside business hours, so waiting out the workday can't
 * be used to dodge deciding during paid hours — see
 * ApprovalController::decide()). Defaults OFF: nothing should suddenly
 * start blocking decisions on an existing install until an Admin
 * deliberately opts in from the Workflow Config toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enforce_business_hours_decisions')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
