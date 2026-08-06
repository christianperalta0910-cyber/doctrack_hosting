<?php

namespace App\Models;

use App\Events\AdminActivityLogged;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    protected $primaryKey = 'log_id';
    public $timestamps = false; // uses its own `timestamp` column instead

    protected $fillable = [
        'user_id', 'document_id', 'action_type', 'description', 'ip_address', 'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    /**
     * Section 5: Immutability. Enforces "insert-only" at the model layer
     * rather than by convention alone — any save() against an
     * already-persisted row (or any delete()) throws.
     */
    protected static function booted(): void
    {
        static::saving(function (self $log) {
            if ($log->exists) {
                throw new \LogicException('Audit log entries are immutable and cannot be modified after creation.');
            }
        });

        static::deleting(function () {
            throw new \LogicException('Audit log entries are immutable and cannot be deleted.');
        });

        // Nearly every meaningful admin-relevant action writes an audit
        // log row (login, upload, decision, escalation, override, ...) —
        // one hook here keeps the admin dashboard's Recent Activity feed
        // (and everything else in that fragment) live without needing a
        // broadcast call added at every individual call site.
        static::created(function (self $log) {
            event(new AdminActivityLogged());
        });
    }

    /**
     * Convenience factory used everywhere a transition happens (Section 6).
     * Deliberately insert-only: audit rows are never updated or deleted
     * by application code.
     */
    public static function record(?int $userId, ?int $documentId, string $action, ?string $description = null, ?string $ip = null): self
    {
        return static::create([
            'user_id' => $userId,
            'document_id' => $documentId,
            'action_type' => $action,
            'description' => $description,
            'ip_address' => $ip ?? request()->ip(),
            'timestamp' => now(),
        ]);
    }
}
