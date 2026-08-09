<?php

namespace App\Models;

use App\Events\AdminActivityLogged;
use App\Events\DocumentReviewActivityChanged;
use App\Events\DocumentStatusChanged;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks a single open/close window of a user viewing a document — the
 * shared foundation for the "who's currently reviewing this" presence
 * icon and per-review duration tracking (see openFor()/close() below).
 * Cumulative review time is computed on read (sum of duration_seconds
 * across a user's sessions for a document), never stored redundantly.
 */
class DocumentReviewSession extends Model
{
    protected $table = 'document_review_sessions';

    protected $fillable = [
        'document_id', 'user_id', 'opened_at', 'closed_at', 'session_type', 'duration_seconds',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (self $session) {
            event(new AdminActivityLogged());
            static::notifyStakeholders($session);
        });
        static::updated(function (self $session) {
            event(new AdminActivityLogged());
            static::notifyStakeholders($session);
        });
    }

    /**
     * Pushes an instant refresh to everyone whose screen shows this
     * document's review activity — mirrors DocumentAssignment::booted()'s
     * notifyDocumentApprovers() exactly, just triggered by a review
     * session opening/closing instead of a decision:
     *   - every co-approver on the document (Approver dashboard's
     *     "opened X ago" / "Reviewed ... total" lines), via the new
     *     DocumentReviewActivityChanged event
     *   - the originator + admin dashboard (same Approval Stages list,
     *     same data), by reusing DocumentStatusChanged as a generic
     *     "something about my document changed" signal — same reuse this
     *     codebase already does for assignment decisions.
     * Only fires from created()/updated() above, which only run on a
     * real Eloquent save() — heartbeat() deliberately uses a raw query
     * update() precisely so it does NOT hit this and spam a broadcast
     * every ~8 seconds while someone has the viewer open.
     */
    private static function notifyStakeholders(self $session): void
    {
        event(new DocumentStatusChanged($session->document));

        $approverIds = DocumentAssignment::where('document_id', $session->document_id)->pluck('user_id')->unique();
        foreach ($approverIds as $approverId) {
            event(new DocumentReviewActivityChanged($session->document_id, (int) $approverId));
        }
    }

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function scopeStillOpen($query)
    {
        return $query->whereNull('closed_at');
    }

    /**
     * For the "currently reviewing" presence icon specifically — stillOpen()
     * alone with no recency bound, since a session only ever explicitly
     * closes when the approver submits a decision (see closeFor()), an
     * abandoned tab (closed without deciding) would otherwise show as
     * "reviewing" forever. 15 minutes is a generous window for someone to
     * actually be reading a document, not a hard SLA.
     */
    public function scopeActive($query)
    {
        return $query->stillOpen()->where('opened_at', '>=', now()->subMinutes(15));
    }

    /**
     * For the live presence icon inside the document viewer modal
     * specifically — a much tighter window than active(), driven by a
     * heartbeat() call on every poll tick while the modal stays open (see
     * DocumentController::presence()). This is now mostly a crash/dead-tab
     * fallback: a deliberate close goes through closeFor() (see
     * DocumentController::presenceLeave()) and disappears from here
     * instantly since stillOpen() fails the moment closed_at is set — the
     * 40s heartbeat decay only matters if the tab dies without ever
     * sending that close signal.
     */
    public function scopeLive($query)
    {
        return $query->stillOpen()->where('updated_at', '>=', now()->subSeconds(40));
    }

    /**
     * Bumps this user's open session for $document so it stays inside the
     * live() window. Raw query, not ->touch()/->save() — this fires many
     * times a minute while someone has the viewer open, and going through
     * Eloquent would re-fire the AdminActivityLogged broadcast on every
     * tick (booted()'s updated() hook) for no reason; nothing needs to
     * react to a heartbeat, only to real open/close events.
     */
    public static function heartbeat(DocumentRepository $document, User $user): void
    {
        static::stillOpen()
            ->where('document_id', $document->document_id)
            ->where('user_id', $user->user_id)
            ->orderByDesc('opened_at')
            ->limit(1)
            ->update(['updated_at' => now()]);
    }

    /**
     * Opens a new session for $user on $document. `session_type` is
     * 'follow_up' the moment a CLOSED prior session exists for this same
     * (document, user) pair — e.g. an approver closing the viewer and
     * coming back to look again before deciding, or genuinely re-reviewing
     * after the originator resubmitted a corrected version. Every close
     * (whether via a real decision or just closing the viewer — see
     * closeFor()) now finalizes duration_seconds, so a later reopen
     * correctly reads as a second review pass rather than losing the
     * earlier time entirely.
     */
    public static function openFor(DocumentRepository $document, User $user): self
    {
        $hasPriorClosedSession = static::where('document_id', $document->document_id)
            ->where('user_id', $user->user_id)
            ->whereNotNull('closed_at')
            ->exists();

        return static::create([
            'document_id' => $document->document_id,
            'user_id' => $user->user_id,
            'opened_at' => now(),
            'session_type' => $hasPriorClosedSession ? 'follow_up' : 'initial',
        ]);
    }

    /**
     * Closes this user's most recent still-open session for $document, if
     * any (no-ops silently if none — e.g. a decision submitted without
     * ever hitting the "open" hook, which is fine, just means no timing
     * data for that particular decision).
     */
    public static function closeFor(DocumentRepository $document, User $user): void
    {
        $session = static::stillOpen()
            ->where('document_id', $document->document_id)
            ->where('user_id', $user->user_id)
            ->orderByDesc('opened_at')
            ->first();

        if (!$session) {
            return;
        }

        $closedAt = now();
        $session->closed_at = $closedAt;
        // Explicit timestamp subtraction, not ->diffInSeconds() — Carbon's
        // sign convention for that method depends on argument order in a
        // way that's easy to get backwards (confirmed the naive call here
        // silently produced negative durations).
        $session->duration_seconds = max(0, $closedAt->getTimestamp() - $session->opened_at->getTimestamp());
        $session->save();
    }

    /** Cumulative seconds this user has spent reviewing $document, across every session. */
    public static function totalSecondsFor(int $documentId, int $userId): int
    {
        return (int) static::where('document_id', $documentId)
            ->where('user_id', $userId)
            ->whereNotNull('duration_seconds')
            ->sum('duration_seconds');
    }

    /**
     * Real review time so far, INCLUDING a currently-open session — unlike
     * totalSecondsFor() above (closed sessions only), this is what the
     * minimum-review-time gate (config('review.min_review_seconds')) needs
     * to check at decide time, since closeFor() hasn't run yet and the
     * session that's about to be closed shouldn't be invisible to the
     * check that's about to decide whether closing it is even allowed.
     */
    public static function secondsSpentSoFar(int $documentId, int $userId): int
    {
        $closed = static::totalSecondsFor($documentId, $userId);

        $open = static::stillOpen()
            ->where('document_id', $documentId)
            ->where('user_id', $userId)
            ->orderByDesc('opened_at')
            ->first();

        $openSeconds = $open ? max(0, now()->getTimestamp() - $open->opened_at->getTimestamp()) : 0;

        return $closed + $openSeconds;
    }

    /**
     * When $user most recently opened $document — the workflow-stage-list
     * component's "opened X ago" line next to a still-pending approver's
     * name (Approver dashboard, Originator tracking, Admin SLA queue —
     * all share that component).
     */
    public static function openedAtFor(int $documentId, int $userId): ?\Illuminate\Support\Carbon
    {
        return static::where('document_id', $documentId)
            ->where('user_id', $userId)
            ->orderByDesc('opened_at')
            ->first()?->opened_at;
    }

    /**
     * Every finished review pass $user has made on $document, oldest
     * first — each with its own opened_at/closed_at/duration_seconds.
     * Powers the resolved-seat "Reviewed 1:05–1:07 PM (2m), 1:10–1:12 PM
     * (2m) — 4m total" line in workflow-stage-list.blade.php: each pass
     * is listed on its own with its own span, rather than collapsing
     * into one outer from/to range that would misleadingly include the
     * gap between passes as if it were reviewing time.
     */
    public static function closedSessionsFor(int $documentId, int $userId): \Illuminate\Support\Collection
    {
        return static::where('document_id', $documentId)
            ->where('user_id', $userId)
            ->whereNotNull('closed_at')
            ->orderBy('opened_at')
            ->get(['opened_at', 'closed_at', 'duration_seconds']);
    }
}
