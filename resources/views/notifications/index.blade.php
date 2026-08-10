@extends('layouts.app')
@section('title', 'Notifications')
@section('page-title', 'Notifications')

@section('content')
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-surface-900">All Notifications</h2>
        <form method="POST" action="{{ route('notifications.readAll') }}">
            @csrf
            <button class="text-xs font-medium text-primary-700 hover:underline">Mark all read</button>
        </form>
    </div>
    <div id="notifications-list" data-refresh-url="{{ route('notifications.listRefresh') }}" data-poll-url="{{ route('notifications.poll') }}" data-user-id="{{ auth()->id() }}">
        @include('notifications.partials.list')
    </div>
</div>

<script>
    // Realtime (Feature: a new notification while you're sitting on this
    // page appears without a manual reload) — same startLiveChannel/
    // startLivePoll pattern used everywhere else in this app (see
    // resources/js/app.js), reusing the exact channel/event the
    // notification bell already listens to (NotificationBroadcast), just
    // targeting this page's full list instead of the dropdown. Wrapped in
    // DOMContentLoaded since app.js's module script (which defines these
    // functions) loads deferred — see the matching comment in
    // admin/dashboard.blade.php for why that ordering matters.
    document.addEventListener('DOMContentLoaded', function () {
        const listEl = document.getElementById('notifications-list');
        if (!listEl) return;

        const opts = {
            refreshUrl: listEl.dataset.refreshUrl,
            target: listEl,
            preserveQueryString: true, // keeps pagination (?page=N) intact across a live swap
        };

        startLiveChannel(`user.${listEl.dataset.userId}`, '.notification.created', opts);
        startLivePoll({ ...opts, pollUrl: listEl.dataset.pollUrl });
    });
</script>
@endsection
