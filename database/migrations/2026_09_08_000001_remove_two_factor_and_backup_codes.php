<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor login (emailed code) and Sign In Backup Codes (its fallback)
 * were both removed together — the backup codes existed solely as a
 * fallback for the emailed code and have no purpose without it. See
 * AuthController::login() for the simplified email+password-only flow.
 * Rebuildable later if 2FA is wanted again; this just tears down the
 * schema it needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_backup_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_code', 'two_factor_expires_at', 'backup_codes_viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_code')->nullable()->after('password_hash');
            $table->timestamp('two_factor_expires_at')->nullable()->after('two_factor_code');
            $table->timestamp('backup_codes_viewed_at')->nullable()->after('email_verified_at');
        });

        Schema::create('user_backup_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->text('code');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }
};
