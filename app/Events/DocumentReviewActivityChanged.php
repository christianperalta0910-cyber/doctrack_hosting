<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired over Reverb whenever a DocumentReviewSession opens or closes (see
 * DocumentReviewSession::booted()), so a co-approver's queue reflects
 * "opened X ago" / "Reviewed ... total" the instant it happens rather than
 * on the next poll cycle — mirrors AssignmentRouted's shape exactly (one
 * event per approver who has any stake in the document), just triggered by
 * review activity instead of a decision.
 */
class DocumentReviewActivityChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $documentId, public int $targetApproverId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('approver.' . $this->targetApproverId)];
    }

    public function broadcastAs(): string
    {
        return 'document.review-activity';
    }

    public function broadcastWith(): array
    {
        return ['document_id' => $this->documentId];
    }
}
