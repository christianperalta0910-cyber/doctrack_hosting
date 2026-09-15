{{--
    The full Notifications page's list body — split out from index.blade.php
    so the same markup renders both the initial page load and the fragment
    NotificationController::listRefresh() returns for the live-refresh JS
    to swap in place (see index.blade.php's script), same pattern as every
    other live-refreshed page in this app.

    Expects: $notifications (paginator).
--}}
{{-- Whole row is the click target — clicking a notification marks just
     that one read and navigates to the document it's about (see
     NotificationRecord::targetUrl()), rather than a separate "Mark read"
     button next to inert text. --}}
<ul class="divide-y divide-surface-100">
    @forelse($notifications as $n)
        <li class="{{ $n->is_read ? '' : 'bg-primary-50/40' }}">
            <form method="POST" action="{{ route('notifications.read', $n) }}">
                @csrf
                <button type="submit" class="w-full text-left px-6 py-4 flex items-start justify-between gap-4 hover:bg-surface-50 transition-colors">
                    <span class="min-w-0">
                        <span class="block text-sm text-surface-800 {{ $n->priority === 'high' ? 'font-semibold text-rejected-700' : '' }}">{{ $n->message_body }}</span>
                        <span class="block text-xs text-surface-400 mt-1">{{ $n->created_at->format('M j, Y g:i A') }}</span>
                    </span>
                    @unless($n->is_read)
                        <span class="mt-1.5 w-2 h-2 rounded-full bg-primary-500 flex-shrink-0 shadow-[0_0_0_3px] shadow-primary-500/15"></span>
                    @endunless
                </button>
            </form>
        </li>
    @empty
        <li class="px-6 py-10 text-center text-sm text-surface-400">No notifications yet.</li>
    @endforelse
</ul>
<div class="px-6 py-4 border-t border-surface-200">{{ $notifications->links() }}</div>
