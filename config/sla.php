<?php

return [
    // Section 1: minimum buffer between "now" and a submitted due_date.
    // 1 hour, not 15 minutes — anything shorter runs into a hard structural
    // problem: the approver tier's own flat 15-minute floor (see
    // fixed_short_due_sla_minutes below) would consume the ENTIRE window
    // on a document due in ~15 minutes, leaving no realistic time for an
    // admin grace period if the approver misses it. An hour guarantees a
    // real leftover window (the 15-minute approver tier plus whatever's
    // left over for admin grace) no matter how short the rest of the
    // formula makes the approver's own slice.
    'min_due_date_buffer_minutes' => 60,

    // Tiered SLA formula constants.
    'approver_sla_fraction' => 0.25,
    'short_due_threshold_minutes' => 60,
    'fixed_short_due_sla_minutes' => 15,
    'max_approver_sla_minutes' => 360, // 6-hour cap (Tier 2)

    // Hours an Admin has to act after escalation before auto-approval fires.
    // Matches the approver tier's own 6-hour cap (max_approver_sla_minutes
    // above) rather than a separately-chosen number — also clamped to the
    // document's own due_date (see DocumentAssignment::adminGraceExpiresAt()
    // and SlaService::escalate()/autoApproveUnresolved()), so this is a
    // ceiling on the grace window, not a guarantee of the full 6 hours.
    'admin_grace_hours' => 6,

    // Default operational window, seeded into sla_settings on first access.
    'default_working_days' => [1, 2, 3, 4, 5, 6], // Mon-Sat (Carbon: 0=Sun..6=Sat)
    'default_work_start' => '09:00',
    'default_work_end' => '17:00',
];
