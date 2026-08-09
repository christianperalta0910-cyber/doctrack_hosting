@extends('layouts.app')
@section('title', 'Review Queue')
@section('page-title', 'Review Queue')

@section('content')
<div class="space-y-6">
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-4">
        <form method="GET" class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[140px]">
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input type="text" id="document-search" name="document" value="{{ request('document') }}"
                    placeholder="Search document" autocomplete="off"
                    class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            </div>
            <select name="priority" onchange="this.form.submit()" class="rounded-lg border-surface-300 text-xs px-3 py-2">
                <option value="">All Priorities</option>
                @foreach(['Urgent', 'Normal', 'Low', 'Expired'] as $p)
                    <option value="{{ $p }}" {{ request('priority') === $p ? 'selected' : '' }}>{{ $p }}</option>
                @endforeach
            </select>
            <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg shadow-sm transition-colors">Filter</button>
            @if(request('document') || request('priority'))
                <a href="{{ route('approver.dashboard') }}" class="text-xs font-medium text-surface-500 hover:underline">Clear</a>
            @endif
        </form>
    </div>

    {{-- Tiny, auto-fading acknowledgment that a live update just happened —
         intentionally not a banner requiring a click; see the JS below for
         why (feels live, not "obviously refreshed"). Deliberately generic
         copy, not "N new" — this fires from two different triggers (an
         instant WebSocket push or the slow fallback poll) whose payloads
         don't share the same shape, so there isn't always a reliable count
         to report. --}}
    <div id="live-update-toast" class="hidden text-xs text-approved-700 font-medium transition-opacity duration-700 flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        <span id="live-update-toast-text">Queue updated</span>
    </div>

    <div id="review-queue" class="space-y-6" data-user-id="{{ auth()->id() }}" data-poll-url="{{ route('approver.assignments.poll') }}" data-refresh-url="{{ route('approver.assignments.refresh') }}" data-initial-count="{{ $initialPendingCount }}">
        @include('approver.partials.queue')
    </div>
</div>

<script>
    // Real-time, client-side filter over the containers already rendered on
    // this page — instant, no round trip. Pressing Enter still submits the
    // surrounding <form> normally, running the "document" filter
    // server-side (see ApprovalController::dashboard()) across every page.
    // Re-run after every live swap too (see below), since fresh markup
    // needs the same filter re-applied — otherwise a swap would silently
    // undo an active search.
    function applyDocumentFilter() {
        const input = document.getElementById('document-search');
        const containers = Array.from(document.querySelectorAll('.review-container'));
        const noMatches = document.getElementById('review-no-matches');
        const noMatchesTerm = document.getElementById('review-no-matches-term');
        if (!input || containers.length === 0 || !noMatches) return;

        const term = input.value.trim().toLowerCase();
        let visibleCount = 0;

        containers.forEach((el) => {
            const matches = term === '' || el.dataset.documentTitles.includes(term);
            el.classList.toggle('hidden', !matches);
            if (matches) visibleCount++;
        });

        const showNoMatches = term !== '' && visibleCount === 0;
        noMatches.classList.toggle('hidden', !showNoMatches);
        if (showNoMatches) noMatchesTerm.textContent = input.value.trim();
    }

    document.getElementById('document-search')?.addEventListener('input', applyDocumentFilter);

    // Live-updates the queue without a full page reload — instant via
    // Reverb (see startLiveChannel in resources/js/app.js) when a new
    // assignment is routed to this approver, a co-approver decides their
    // own seat on a shared stage, or a co-approver opens/closes the
    // viewer (see DocumentAssignment::booted() and
    // DocumentReviewSession::booted()) — the "N of M approved" progress,
    // "opened X ago" / "Reviewed ... total" lines all depend on someone
    // else's action, not just this approver's own. The slow poll behind
    // it is only a fallback in case the WebSocket connection is down.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE: this is a plain inline
    // script, which runs immediately as the browser parses this point in
    // the page — but app.js (which defines startLiveChannel/startLivePoll)
    // is loaded via the Vite-injected module script tag in the layout's
    // <head>, and module scripts are always deferred until after the page
    // finishes parsing. Without this wrapper, this code runs BEFORE those
    // functions exist and silently throws, and nothing below the throw
    // ever executes — which is exactly why live updates looked completely
    // wired up but never actually ran.
    // Minimum-review-time countdown (Feature: can't Approve/Reject without
    // actually having opened the document first — see config('review.php')
    // and ApprovalController::decide()'s server-side gate, which is the
    // real enforcement regardless of what this shows). Server-rendered
    // data-review-remaining is the source of truth for "how long left";
    // this just ticks it down live once the viewer is actually open,
    // rather than making the approver refresh the page to see it update.
    let reviewCountdownIntervals = {};

    function clearReviewCountdowns() {
        Object.values(reviewCountdownIntervals).forEach(clearInterval);
        reviewCountdownIntervals = {};
    }

    function startReviewCountdown(documentId) {
        if (reviewCountdownIntervals[documentId]) return; // already ticking

        const form = document.querySelector(`.review-decide-form[data-document-id="${documentId}"]`);
        if (!form) return;

        let remaining = parseInt(form.dataset.reviewRemaining, 10);
        if (!remaining || remaining <= 0) return;

        const label = form.querySelector('.review-countdown-label');
        const buttons = form.querySelectorAll('.review-decide-btn');

        reviewCountdownIntervals[documentId] = setInterval(() => {
            remaining -= 1;
            form.dataset.reviewRemaining = remaining;

            if (remaining <= 0) {
                clearInterval(reviewCountdownIntervals[documentId]);
                delete reviewCountdownIntervals[documentId];
                if (label) label.remove();
                // The review-time floor is met, but a separate gate (Admin's
                // business-hours toggle, see requireBusinessHoursIfEnforced())
                // can still be the reason these need to stay disabled — the
                // "SLA expires" note above already explains that case, so
                // this doesn't need its own countdown of its own.
                if (form.dataset.businessHoursBlocked !== '1') {
                    buttons.forEach((btn) => { btn.disabled = false; });
                }
            } else if (label) {
                label.textContent = `Reviewing… ${remaining}s remaining before you can decide.`;
            }
        }, 1000);
    }

    // Added once, on `window` — never torn down by a live swap (only the
    // #review-queue subtree gets replaced), so this doesn't need to be
    // re-attached in onSwap the way DOM-scoped listeners would.
    window.addEventListener('documentviewer:opened', (e) => startReviewCountdown(e.detail.documentId));

    document.addEventListener('DOMContentLoaded', function () {
        const queueEl = document.getElementById('review-queue');
        if (!queueEl) return;

        const toast = document.getElementById('live-update-toast');
        const toastText = document.getElementById('live-update-toast-text');

        // message/isError let the decide AJAX handler below reuse this same
        // toast for its own result — "Decision recorded: Approved." in the
        // normal success color, or a genuine error (e.g. the review-time
        // gate) in red — instead of a plain generic "Queue updated" every
        // time.
        const showToast = (message, isError = false) => {
            if (toastText) toastText.textContent = message || 'Queue updated';
            toast.classList.toggle('text-approved-700', !isError);
            toast.classList.toggle('text-rejected-700', isError);
            toast.classList.remove('hidden');
            toast.style.opacity = '1';
            setTimeout(() => { toast.style.opacity = '0'; }, 2500);
            setTimeout(() => { toast.classList.add('hidden'); }, 3200);
        };

        const opts = {
            refreshUrl: queueEl.dataset.refreshUrl,
            target: queueEl,
            preserveQueryString: true,
            // If the approver is actively typing a comment (a textarea has
            // focus or unsaved text), skip this update rather than wiping
            // out whatever they were about to submit — the next poll cycle
            // (or the approver's own next action) will catch it up.
            isBusy: () => Array.from(document.querySelectorAll('#review-queue textarea')).some(
                (el) => el === document.activeElement || el.value.trim() !== ''
            ),
            onSwap: () => {
                applyDocumentFilter();
                showToast();
                // The fresh markup came with its own server-computed
                // data-review-remaining (already reflecting real elapsed
                // time on any still-open session) — any interval ticking
                // against the OLD, now-replaced form nodes is stale.
                clearReviewCountdowns();
            },
        };

        startLiveChannel(`approver.${queueEl.dataset.userId}`, '.assignment.routed', opts);
        startLiveChannel(`approver.${queueEl.dataset.userId}`, '.document.review-activity', opts);
        // Admin's business-hours-restriction toggle (Workflow Config) — a
        // shared channel every approver listens on, not a per-user one,
        // since this setting affects everyone's queue at once. Without
        // this, someone with the queue already open would only pick up a
        // toggle flip on the next background poll instead of instantly.
        startLiveChannel('approvers', '.system-settings.changed', opts);
        startLivePoll({ ...opts, pollUrl: queueEl.dataset.pollUrl });

        // Approve/Reject, AJAX-ified (Feature: no more scroll-jump-to-top).
        // The form used to submit natively, which redirected back to this
        // page — a full navigation that always reloads scrolled to the top,
        // no matter where in a long queue the approver was. Intercepting
        // the submit and swapping just the queue fragment in place (same
        // applyLiveRefresh() the poll/channel updates above already use)
        // keeps the page — and the approver's scroll position — exactly
        // where it was.
        queueEl.addEventListener('submit', function (e) {
            const form = e.target.closest('.review-decide-form');
            if (!form) return;
            e.preventDefault();

            // Browser-native `required` validation (toggled per-button, see
            // the queue partial) already ran before this handler fires — a
            // blocked/invalid form never reaches here at all.
            fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form, e.submitter),
            })
                .then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'Something went wrong — please try again.');
                    return data;
                })
                .then((data) => {
                    window.applyLiveRefresh({
                        refreshUrl: queueEl.dataset.refreshUrl,
                        target: queueEl,
                        preserveQueryString: true,
                        onSwap: () => {
                            applyDocumentFilter();
                            clearReviewCountdowns();
                            showToast(data.status);
                        },
                    });
                })
                .catch((err) => showToast(err.message, true));
        });
    });
</script>
@endsection
