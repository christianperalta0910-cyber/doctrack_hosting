<?php

namespace App\Models;

use App\Events\NotificationBroadcast;
use Illuminate\Database\Eloquent\Model;

class NotificationRecord extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'notification_id';
    public $timestamps = false;

    protected $fillable = [
        'recipient_id', 'document_id', 'message_body', 'priority', 'is_read', 'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id', 'user_id');
    }

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    /**
     * Where clicking this notification should navigate to. $viewer is
     * always the recipient themselves in practice (every caller reads
     * from $user->notifications()), passed explicitly instead of via the
     * recipient() relation so rendering a list of these never N+1s.
     * Approver has no per-document tracking page (they work a shared
     * queue on their dashboard, not a document detail route — see
     * routes/web.php's approver group), so that case just goes to the
     * queue rather than a document-specific URL.
     */
    public function targetUrl(User $viewer): ?string
    {
        if (!$this->document_id) {
            return null;
        }

        return match (true) {
            $viewer->isOriginator() => route('originator.documents.show', $this->document_id),
            $viewer->isAdmin() => route('documents.track', $this->document_id),
            $viewer->isApprover() => route('approver.dashboard'),
            default => null,
        };
    }

    public static function send(int $recipientId, ?int $documentId, string $message, string $priority = 'normal'): self
    {
        $notification = static::create([
            'recipient_id' => $recipientId,
            'document_id' => $documentId,
            'message_body' => $message,
            'priority' => $priority,
            'is_read' => false,
            'created_at' => now(),
        ]);

        event(new NotificationBroadcast($recipientId));

        return $notification;
    }
}
