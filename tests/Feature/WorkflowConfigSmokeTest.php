<?php

use App\Models\User;

it('renders the workflow config page with the new business-hours toggle', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.workflow.config'));

    $response->assertOk();
    $response->assertSee('Approver Decision Restriction');
    $response->assertSee('data-poll-url', false);
});
