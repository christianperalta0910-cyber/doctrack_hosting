<?php

use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;

function clickableNotifDoc(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'notif-doc-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
}

test('clicking a notification marks only that one read and redirects to the document', function () {
    $originator = User::factory()->originator()->create();
    $document = clickableNotifDoc($originator);

    $clicked = NotificationRecord::create([
        'recipient_id' => $originator->user_id, 'document_id' => $document->document_id,
        'message_body' => 'about this document', 'priority' => 'normal', 'is_read' => false, 'created_at' => now(),
    ]);
    $other = NotificationRecord::create([
        'recipient_id' => $originator->user_id, 'document_id' => null,
        'message_body' => 'unrelated', 'priority' => 'normal', 'is_read' => false, 'created_at' => now(),
    ]);

    $response = $this->actingAs($originator)->post(route('notifications.read', $clicked));

    $response->assertRedirect(route('originator.documents.show', $document));
    expect($clicked->fresh()->is_read)->toBeTrue()
        ->and($other->fresh()->is_read)->toBeFalse();
});

test('an admin clicking a document notification is redirected to the admin tracking page', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $document = clickableNotifDoc($originator);

    $notification = NotificationRecord::create([
        'recipient_id' => $admin->user_id, 'document_id' => $document->document_id,
        'message_body' => 'flagged for admin', 'priority' => 'normal', 'is_read' => false, 'created_at' => now(),
    ]);

    $response = $this->actingAs($admin)->post(route('notifications.read', $notification));

    $response->assertRedirect(route('documents.track', $document));
});

test('a user cannot mark another user\'s notification read', function () {
    $originator = User::factory()->originator()->create();
    $other = User::factory()->originator()->create();
    $notification = NotificationRecord::create([
        'recipient_id' => $other->user_id, 'document_id' => null,
        'message_body' => 'not yours', 'priority' => 'normal', 'is_read' => false, 'created_at' => now(),
    ]);

    $response = $this->actingAs($originator)->post(route('notifications.read', $notification));

    $response->assertForbidden();
    expect($notification->fresh()->is_read)->toBeFalse();
});

test('opening the bell marks everything read without removing anything from it', function () {
    $user = User::factory()->originator()->create();
    NotificationRecord::create(['recipient_id' => $user->user_id, 'message_body' => 'first', 'priority' => 'normal', 'is_read' => false, 'created_at' => now()]);
    NotificationRecord::create(['recipient_id' => $user->user_id, 'message_body' => 'second', 'priority' => 'normal', 'is_read' => false, 'created_at' => now()]);

    $this->actingAs($user)->post(route('notifications.readAll'))->assertNoContent();

    expect(NotificationRecord::where('recipient_id', $user->user_id)->where('is_read', false)->count())->toBe(0);

    $response = $this->actingAs($user)->get(route('notifications.refresh'));
    $response->assertOk()->assertSee('first')->assertSee('second');
});

test('the bell dropdown shows recent notifications even after they are read', function () {
    $user = User::factory()->originator()->create();
    NotificationRecord::create(['recipient_id' => $user->user_id, 'message_body' => 'already read one', 'priority' => 'normal', 'is_read' => true, 'created_at' => now()]);

    $response = $this->actingAs($user)->get(route('notifications.refresh'));

    $response->assertOk()->assertSee('already read one');
});
