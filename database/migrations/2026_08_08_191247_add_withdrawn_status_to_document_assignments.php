<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 'withdrawn' to individual_status — a seat withdrawn because its
 * holder was deactivated while a sibling approver already covers the same
 * stage independently (see WorkflowService::withdrawAssignment()), distinct
 * from an admin-facing escalation since nobody needs to act on it.
 *
 * MySQL-only: individual_status is a real ENUM column there, so widening
 * the allowed set needs a raw MODIFY (no doctrine/dbal in this project, so
 * the fluent ->change() column-modify API isn't available). SQLite (the
 * test suite's driver) stores this column as a plain varchar with no CHECK
 * constraint — confirmed via the actual CREATE TABLE the schema builder
 * produces there — so no equivalent statement is needed on that driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE document_assignments MODIFY individual_status ENUM('pending', 'approved', 'rejected', 'auto_approved', 'withdrawn') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE document_assignments MODIFY individual_status ENUM('pending', 'approved', 'rejected', 'auto_approved') NOT NULL DEFAULT 'pending'");
        }
    }
};
