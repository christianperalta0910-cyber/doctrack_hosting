<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request Revision (Feature: an approver highlights a specific passage of
 * a document's extracted text and leaves a comment on it, instead of
 * rejecting the whole document outright — see WorkflowService's majority-
 * vote reject redesign, which this complements). start_offset/end_offset
 * are character positions into DocumentRepository::ocr_text — the same
 * plain text every document already has, regardless of original file
 * type. selected_text is a snapshot of what was actually highlighted at
 * the time, kept alongside the offsets so the flagged passage still
 * reads correctly even after the originator edits the surrounding text
 * (a future edit could shift what those offsets point at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_annotations', function (Blueprint $table) {
            $table->id('annotation_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('document_assignments', 'assignment_id')->cascadeOnDelete();
            $table->foreignId('raised_by')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->unsignedInteger('start_offset');
            $table->unsignedInteger('end_offset');
            $table->text('selected_text');
            $table->text('comment');
            // Null while the flagged passage is still outstanding — set
            // once the originator addresses it (Step 3 of this feature).
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_annotations');
    }
};
