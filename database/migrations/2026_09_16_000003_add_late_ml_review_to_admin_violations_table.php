<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a third AdminViolation type — 'late_ml_review' — for a low-
 * confidence classification that sat past its 6-hour review window
 * without Admin confirming/correcting it (see SlaService::
 * trackLateMlReviews(), which mirrors trackLateReviews()'s existing
 * late_review handling exactly, just triggered off DocumentRepository::
 * ml_review_due_at instead of an assignment's review_due_at).
 *
 * Two columns have to relax for this: assignment_id becomes nullable (a
 * late_ml_review violation is about a WHOLE DOCUMENT'S classification,
 * raised before any DocumentAssignment/stage/seat exists for it at all
 * — unlike the other two violation types, which are always tied to one
 * specific assignment), and so does stage_name for the same reason.
 *
 * MySQL: real ENUM/NOT NULL columns there, widened with a raw MODIFY —
 * no doctrine/dbal in this project, so the fluent ->change() API isn't
 * available (same reasoning as 2026_08_08_191247_add_withdrawn_status_to_
 * document_assignments.php).
 *
 * SQLite (the test suite's driver): violation_type has no real ENUM/
 * CHECK constraint there (confirmed the same way as that same prior
 * migration), so the new value needs nothing. assignment_id/stage_name's
 * NOT NULL is real there, though, and SQLite has no in-place "drop NOT
 * NULL" — this recreates the table (the standard workaround in the
 * absence of doctrine/dbal), copies the existing rows across, and swaps
 * it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE admin_violations MODIFY COLUMN violation_type ENUM('missed_approval', 'late_review', 'late_ml_review') NOT NULL");
            DB::statement('ALTER TABLE admin_violations MODIFY COLUMN assignment_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE admin_violations MODIFY COLUMN stage_name VARCHAR(255) NULL');

            return;
        }

        Schema::create('admin_violations_new', function (Blueprint $table) {
            $table->id('violation_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id');
            $table->foreignId('assignment_id')->nullable()->constrained('document_assignments', 'assignment_id');
            $table->string('violation_type');
            $table->string('stage_name')->nullable();
            $table->timestamp('first_violated_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['violation_type', 'resolved_at']);
        });

        DB::statement('INSERT INTO admin_violations_new SELECT * FROM admin_violations');
        Schema::drop('admin_violations');
        Schema::rename('admin_violations_new', 'admin_violations');
    }

    public function down(): void
    {
        DB::table('admin_violations')->where('violation_type', 'late_ml_review')->delete();

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE admin_violations MODIFY COLUMN assignment_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE admin_violations MODIFY COLUMN stage_name VARCHAR(255) NOT NULL');
            DB::statement("ALTER TABLE admin_violations MODIFY COLUMN violation_type ENUM('missed_approval', 'late_review') NOT NULL");

            return;
        }

        Schema::create('admin_violations_new', function (Blueprint $table) {
            $table->id('violation_id');
            $table->foreignId('document_id')->constrained('document_repository', 'document_id');
            $table->foreignId('assignment_id')->constrained('document_assignments', 'assignment_id');
            $table->string('violation_type');
            $table->string('stage_name');
            $table->timestamp('first_violated_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['violation_type', 'resolved_at']);
        });

        DB::statement('INSERT INTO admin_violations_new SELECT * FROM admin_violations');
        Schema::drop('admin_violations');
        Schema::rename('admin_violations_new', 'admin_violations');
    }
};
