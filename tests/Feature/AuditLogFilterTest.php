<?php

use App\Models\AuditLog;
use App\Models\User;

/**
 * Regression coverage for session-event visibility on the admin audit log
 * page. Login/logout (web and API) used to be hidden by default as
 * "routine noise" — reversed per explicit request, since the admin wants
 * session activity visible in the audit trail after all.
 */
it('shows both web and API session events in the audit trail', function () {
    $admin = User::factory()->admin()->create();
    AuditLog::record($admin->user_id, null, 'login', 'User admin logged in.');
    AuditLog::record($admin->user_id, null, 'logout', 'User admin logged out.');
    AuditLog::record($admin->user_id, null, 'api_login', 'User admin authenticated via API.');
    AuditLog::record($admin->user_id, null, 'api_logout', 'User admin revoked their API token.');
    AuditLog::record($admin->user_id, null, 'ml_train', 'Trained a model.');

    $response = $this->actingAs($admin)->get(route('admin.audit.logs'));

    $response->assertOk();
    $response->assertSee('User admin logged in.');
    $response->assertSee('User admin logged out.');
    $response->assertSee('User admin authenticated via API.');
    $response->assertSee('User admin revoked their API token.');
    $response->assertSee('Trained a model.');
});
