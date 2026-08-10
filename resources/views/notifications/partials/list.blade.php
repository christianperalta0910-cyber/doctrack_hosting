{{--
    The full Notifications page's list body — split out from index.blade.php
    so the same markup renders both the initial page load and the fragment
    NotificationController::listRefresh() returns for the live-refresh JS
    to swap in place (see index.blade.php's script), same pattern as every
    other live-refreshed page in this app.

    Expects: $notifications (paginator).
--}}
<ul class="divide-y divide-surface-100">
    @forelse($notifications as $n)
        <li class="px-6 py-4 flex items-start justify-between gap-4 {{ $n->is_read ? '' : 'bg-primary-50/40' }}">
            <div class="min-w-0">
                <p class="text-sm text-surface-800 {{ $n->priority === 'high' ? 'font-semibold text-rejected-700' : '' }}">{{ $n->message_body }}</p>
                <p class="text-xs text-surface-400 mt-1">{{ $n->created_at->format('M j, Y g:i A') }}</p>
            </div>
            @unless($n->is_read)
                <form method="POST" action="{{ route('notifications.read', $n) }}">
                    @csrf
                    <button class="text-xs font-medium text-primary-700 hover:underline whitespace-nowrap">Mark read</button>
                </form>
            @endunless
        </li>
    @empty
        <li class="px-6 py-10 text-center text-sm text-surface-400">No notifications yet.</li>
    @endforelse
</ul>
<div class="px-6 py-4 border-t border-surface-200">{{ $notifications->links() }}</div>
