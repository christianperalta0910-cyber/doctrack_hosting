<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DocumentAnnotation
 * -------------------
 * Request Revision — an approver highlights a specific passage of a
 * document's extracted text (DocumentRepository::ocr_text) and leaves a
 * comment on it, instead of rejecting the whole document outright (see
 * WorkflowService's majority-vote reject redesign, which this
 * complements — Request Revision is the non-destructive alternative a
 * blocked or hesitant Reject vote gets pointed toward).
 *
 * start_offset/end_offset are character positions into ocr_text at the
 * time this was raised. selected_text is a snapshot of what was actually
 * highlighted, kept alongside the offsets so the flagged passage still
 * reads correctly even after the originator edits the surrounding text.
 *
 * Stays open (resolved_at null) until the originator addresses it.
 */
class DocumentAnnotation extends Model
{
    protected $primaryKey = 'annotation_id';

    protected $fillable = [
        'document_id', 'assignment_id', 'raised_by',
        'start_offset', 'end_offset', 'selected_text', 'comment', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    public function assignment()
    {
        return $this->belongsTo(DocumentAssignment::class, 'assignment_id', 'assignment_id');
    }

    public function raisedBy()
    {
        return $this->belongsTo(User::class, 'raised_by', 'user_id');
    }
}
