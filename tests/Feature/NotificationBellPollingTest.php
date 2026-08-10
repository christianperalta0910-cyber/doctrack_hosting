<?php

use App\Models\NotificationRecord;
use App\Models\User;

it('reports the current unread count', function () {
    $user = User::factory()->originator()->create();
    NotificationRecord::create(['recipient_id' => $user->user_id, 'message_body' => 'msg 1', 'priority' => 'normal', 'is_read' => false, 'created_at' => now()]);
    NotificationRecord::create(['recipient_id' => $user->user_id, 'message_body' => 'msg 2', 'priority' => 'normal', 'is_read' => true, 'created_at' => now()]);

    $response = $this->actingAs($user)->getJson(route('notifications.poll'));

    $response->assertOk()->assertJson(['unread_count' => 1]);
});

it('does not count another user\'s notifications', function () {
    $user = User::factory()->originator()->create();
    $other = User::factory()->originator()->create();
    NotificationRecord::create(['recipient_id' => $other->user_id, 'message_body' => 'msg', 'priority' => 'normal', 'is_read' => false, 'created_at' => now()]);

    $response = $this->actingAs($user)->getJson(route('notifications.poll'));

    $response->assertOk()->assertJson(['unread_count' => 0]);
});

it('renders the bell fragment with the unread notification', function () {
    $user = User::factory()->originator()->create();
    NotificationRecord::create(['recipient_id' => $user->user_id, 'message_body' => 'You have a new assignment', 'priority' => 'normal', 'is_read' => false, 'created_at' => now()]);

    $response = $this->actingAs($user)->get(route('notifications.refresh'));

    $response->assertOk()->assertSee('You have a new assignment');
});

it('does not include the outer <details> tag in the refresh fragment, preserving open/closed state', function () {
    $user = User::factory()->originator()->create();

    $response = $this->actingAs($user)->get(route('notifications.refresh'));

    $response->assertOk();
    expect($response->getContent())->not->toContain('<details');
});

it('serves the full notifications list fragment for the live-refresh JS on the Notifications page', function () {
    $user = User::factory()->originator()->create();
    NotificationRecord::create(['recipient_id' => $user->user_id, 'message_body' => 'A full-list-page notification', 'priority' => 'normal', 'is_read' => false, 'created_at' => now()]);

    $response = $this->actingAs($user)->get(route('notifications.listRefresh'));

    $response->assertOk()->assertSee('A full-list-page notification');
});

it('does not leak another user\'s notifications into the list-refresh fragment', function () {
    $user = User::factory()->originator()->create();
    $other = User::factory()->originator()->create();
    NotificationRecord::create(['recipient_id' => $other->user_id, 'message_body' => 'Someone else\'s notification', 'priority' => 'normal', 'is_read' => false, 'created_at' => now()]);

    $response = $this->actingAs($user)->get(route('notifications.listRefresh'));

    $response->assertOk();
    expect($response->getContent())->not->toContain("Someone else's notification");
});
