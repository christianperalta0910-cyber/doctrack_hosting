<?php

namespace App\Policies;

use App\Models\DocumentAnnotation;
use App\Models\User;

class DocumentAnnotationPolicy
{
    /**
     * Only the approver who raised this exact flag, and only while it's
     * still open — once the originator has already addressed it
     * (resolved_at set), withdrawing it no longer means anything.
     */
    public function withdraw(User $user, DocumentAnnotation $annotation): bool
    {
        return $annotation->raised_by === $user->user_id && $annotation->resolved_at === null;
    }
}
