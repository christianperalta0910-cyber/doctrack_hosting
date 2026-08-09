<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever an Admin changes a system-wide setting that affects every
 * approver's queue at once (currently just enforce_business_hours_decisions
 * — see AdminController::updateBusinessHoursEnforcement()). Carries no
 * payload — every listener just re-fetches its own queue fragment, which
 * recomputes the current setting fresh, so there's nothing worth
 * serializing per event. Without this, an approver who already had their
 * queue open would only see the new restriction on their next background
 * poll (up to ~75s later) instead of instantly.
 */
class SystemSettingsChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function broadcastOn(): array
    {
        return [new PrivateChannel('approvers')];
    }

    public function broadcastAs(): string
    {
        return 'system-settings.changed';
    }
}
