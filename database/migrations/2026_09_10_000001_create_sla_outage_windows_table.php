<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records a detected gap in the SLA scheduler's own heartbeat (see
 * SlaService::detectOutage(), called from the sla:check command that
 * already runs every 5 minutes) — inferred proof the system itself was
 * unreachable/not running for that span, not any one user's own weak
 * connection. Used two ways: SlaService recalculates affected approvers'
 * deadlines against it (business_minutes_lost, computed once at
 * detection time via BusinessHoursService::businessMinutesLostToOutage()
 * so every consumer reads the same number rather than recomputing it
 * differently), and it's the audit trail of when this last happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_outage_windows', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('business_minutes_lost');
            $table->timestamp('compensated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_outage_windows');
    }
};
