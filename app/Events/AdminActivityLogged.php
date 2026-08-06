<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever something admin-dashboard-relevant happens that ISN'T
 * already covered by DocumentStatusChanged — logins/logouts, uploads,
 * decisions, SLA escalations, and every other action that writes an
 * AuditLog row (see AuditLog::booted()), plus an approver's availability
 * toggle (see User::booted()), which writes no audit log at all. Carries
 * no payload — every listener just re-fetches the dashboard fragment, so
 * there's nothing worth serializing per event.
 */
class AdminActivityLogged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin-dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'admin.activity-logged';
    }
}
