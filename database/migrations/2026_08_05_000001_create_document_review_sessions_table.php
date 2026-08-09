<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCUMENT_REVIEW_SESSIONS
 * Tracks who has a document open right now (closed_at IS NULL — drives the
 * "currently reviewing" presence icon) and how long a review took
 * (duration_seconds, computed on close). A session with an existing
 * CLOSED session for the same (document_id, user_id) pair is a
 * `follow_up` review, not `initial` — e.g. an approver re-reviewing a
 * document after the originator resubmitted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_review_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('document_repository', 'document_id')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->enum('session_type', ['initial', 'follow_up'])->default('initial');
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'closed_at']);
            $table->index(['document_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_review_sessions');
    }
};
