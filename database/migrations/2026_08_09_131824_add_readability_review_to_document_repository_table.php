<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            // The real-word ratio ValidationService computed at ingest time —
            // the frozen "first percentage" a readability review shows
            // alongside the recomputed score after an admin confirms it (see
            // AdminController::confirmReadabilityReview()). Null whenever the
            // check didn't run at all (category had too few staged training
            // samples yet — see ValidationService::categoryVocabulary()).
            $table->unsignedTinyInteger('readability_score')->nullable()->after('validation_errors');

            // 'pending'|'confirmed'|'dismissed' — same string-not-ENUM
            // reasoning as ml_review_status (see that column's migration).
            // Set to 'pending' only when the readability check was the SOLE
            // reason validation failed (required sections present, word
            // count met) — see WorkflowService::ingest(). A document failing
            // for an objective reason too (missing section, too short) is
            // just a hard block, no review offered, since there's nothing
            // for an admin's judgment call to resolve until that's fixed.
            $table->string('readability_review_status')->nullable()->after('readability_score');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn(['readability_score', 'readability_review_status']);
        });
    }
};
