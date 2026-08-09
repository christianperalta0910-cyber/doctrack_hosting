<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SystemSetting
 * -------------
 * Application-enforced singleton (no DB constraint) holding system-wide
 * behavioral toggles that don't warrant their own dedicated table — start
 * with just enforce_business_hours_decisions. Read by ApprovalController
 * to decide whether Approve/Reject is gated to business hours.
 */
class SystemSetting extends Model
{
    protected $table = 'system_settings';

    protected $fillable = [
        'enforce_business_hours_decisions', 'updated_by',
    ];

    protected $casts = [
        'enforce_business_hours_decisions' => 'boolean',
    ];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by', 'user_id');
    }

    /** Self-healing singleton: creates the row with its default (off) on first access. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'enforce_business_hours_decisions' => false,
        ]);
    }
}
