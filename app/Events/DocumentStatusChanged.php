<?php

namespace App\Events;

use App\Models\DocumentRepository;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a document's global_status actually changes (see
 * DocumentRepository::booted()) — pushes the update to the originator who
 * owns it, to every admin's dashboard, and to every approver (e.g. the
 * Archive page, which any approver can browse) over Reverb, so all three
 * surfaces update the instant it happens instead of on the next poll.
 * Broadcasting to every approver rather than a specific one is the same
 * "broadcast broadly, let the receiving page's own query filter it"
 * pattern admin-dashboard already uses — a signal to re-fetch, not a
 * payload the client trusts for anything itself.
 *
 * ShouldBroadcastNow, not the queued ShouldBroadcast — this fires
 * synchronously in the same request that changed the status, so there's
 * no queue-worker round trip adding latency to what's supposed to be
 * instant.
 */
class DocumentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DocumentRepository $document)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('originator.' . $this->document->originator_id),
            new PrivateChannel('admin-dashboard'),
            new PrivateChannel('approvers'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'document.status-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->document->document_id,
            'status' => $this->document->global_status,
        ];
    }
}
