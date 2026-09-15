@php
    $recent = auth()->user()->notifications()->limit(6)->get();
    $unreadCount = auth()->user()->notifications()->where('is_read', false)->count();
@endphp
<details class="relative" id="notification-bell" data-popover data-user-id="{{ auth()->id() }}"
    data-poll-url="{{ route('notifications.poll') }}" data-refresh-url="{{ route('notifications.refresh') }}"
    data-mark-all-read-url="{{ route('notifications.readAll') }}">
    @include('notifications.partials.bell')
</details>
