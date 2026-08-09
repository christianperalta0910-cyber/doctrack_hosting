<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * requires_printing (Feature: originators can flag that a submission needs
 * a physical printed copy — surfaced as a clickable print-triggering badge
 * once approved, on the tracking view and Archive). Purely the
 * originator's own explicit checkbox at upload time — see
 * DocumentController::store() / WorkflowService::ingest().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->boolean('requires_printing')->default(false)->after('is_legacy_import');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn('requires_printing');
        });
    }
};
