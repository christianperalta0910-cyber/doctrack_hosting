import './echo';

// Browsers that support the View Transitions API also honor the
// @view-transition CSS rule (see app.css) and handle the entire old-page
// -> new-page crossfade natively on every navigation — no JS needed, and
// nothing here should fight it. The manual opacity fade below is only a
// fallback for browsers that don't support it yet, so every place it's
// used is gated on this same check.
const supportsViewTransitions = 'startViewTransition' in document;

// Fade-in on load for a subtle, professional page-transition feel —
// fallback only; browsers with native View Transitions already crossfade
// the incoming page on their own, so doing this too would just layer a
// second, redundant fade on top of it.
if (!supportsViewTransitions) {
    document.addEventListener('DOMContentLoaded', () => {
        document.body.style.opacity = 0;
        requestAnimationFrame(() => {
            document.body.style.transition = 'opacity 150ms ease';
            document.body.style.opacity = 1;
        });
    });

    // Fade the current page out the instant an internal link is clicked,
    // so leaving a page feels like an intentional transition rather than
    // the browser abruptly blanking out mid-navigation. Only for plain,
    // same-origin link clicks — modified clicks (new tab, download,
    // external links, in-page anchors) navigate immediately as normal.
    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        const link = e.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch {
            return;
        }
        if (url.origin !== window.location.origin) return;
        // Same-page anchor (e.g. "#section") — no navigation happens, so
        // there's nothing to fade for.
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;

        e.preventDefault();
        document.body.style.transition = 'opacity 150ms ease';
        document.body.style.opacity = 0;
        setTimeout(() => {
            window.location.href = link.href;
        }, 150);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Approver dashboard SLA countdown ticker.
    const countdownEls = document.querySelectorAll('[data-countdown]');
    if (countdownEls.length) {
        const tick = () => {
            const now = Math.floor(Date.now() / 1000);
            countdownEls.forEach((el) => {
                const target = parseInt(el.dataset.countdown, 10);
                if (!target) return;
                const diff = target - now;
                if (diff <= 0) {
                    el.textContent = 'Overdue';
                    el.classList.add('text-rejected-700');
                    return;
                }
                const h = Math.floor(diff / 3600);
                const m = Math.floor((diff % 3600) / 60);
                el.textContent = `${h}h ${m}m remaining`;
                if (diff < 3600) el.classList.add('text-rejected-700');
            });
        };
        tick();
        setInterval(tick, 30000);
    }

    // Collapsible sidebar (hamburger menu) — a fixed off-canvas drawer,
    // shared by every role's dashboard since they all extend this one
    // layout. Toggled via `transform: translateX()`, not `width` — a
    // width-collapsing flex-sibling version used to live here, but that
    // technique turned out unreliable enough across mobile browsers that
    // the sidebar sometimes wouldn't visibly close at all. Same toggle,
    // same button, identical behavior at every screen width — this is a
    // different, more robust MECHANISM, not a breakpoint-dependent branch.
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarClose = document.getElementById('sidebar-close');
    const sidebarBackdrop = document.getElementById('sidebar-backdrop');
    if (sidebar && sidebarToggle) {
        const isOpen = () => sidebar.classList.contains('translate-x-0');

        const openSidebar = () => {
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');
            sidebarBackdrop?.classList.remove('opacity-0', 'pointer-events-none');
            sidebarBackdrop?.classList.add('opacity-100');
            sidebarToggle.setAttribute('aria-expanded', 'true');
        };
        const closeSidebar = () => {
            sidebar.classList.remove('translate-x-0');
            sidebar.classList.add('-translate-x-full');
            sidebarBackdrop?.classList.add('opacity-0', 'pointer-events-none');
            sidebarBackdrop?.classList.remove('opacity-100');
            sidebarToggle.setAttribute('aria-expanded', 'false');
        };
        const toggleSidebar = () => (isOpen() ? closeSidebar() : openSidebar());

        sidebarToggle.addEventListener('click', toggleSidebar);
        sidebarClose?.addEventListener('click', closeSidebar);
        sidebarBackdrop?.addEventListener('click', closeSidebar);
    }

    // Click-outside-to-close for transient <details> popovers/menus
    // (notification bell, the deactivation-reason form, etc.) — native
    // <details> only toggles via clicking its own <summary> again, which
    // isn't how a dropdown menu is expected to behave. Deliberately opt-in
    // via a data-popover marker rather than applying to every <details> on
    // the page — plenty of others (violation breach lists, the approver
    // roster, stage edit forms) are "expand to read/edit," not transient
    // menus, and closing those just because you clicked elsewhere on the
    // page would be actively annoying while reading or filling one in.
    document.addEventListener('click', (e) => {
        document.querySelectorAll('details[data-popover][open]').forEach((details) => {
            if (!details.contains(e.target)) {
                details.open = false;
            }
        });
    });

    // Notification bell — live unread count/list, present on every page.
    // Instant via Reverb (see startLiveChannel below); the slow poll is
    // just a safety net in case the WebSocket connection is down. Swaps
    // skip while the dropdown is currently open (the user is actively
    // reading it), preserving whatever they're looking at instead of
    // reshuffling it mid-read.
    const bell = document.getElementById('notification-bell');
    if (bell) {
        const bellOpts = {
            refreshUrl: bell.dataset.refreshUrl,
            target: bell,
            isBusy: () => bell.open,
        };
        startLiveChannel(`user.${bell.dataset.userId}`, '.notification.created', bellOpts);
        startLivePoll({ ...bellOpts, pollUrl: bell.dataset.pollUrl, minDelay: 45, maxDelay: 75 });

        // Account deactivation — same already-open per-user channel as the
        // bell above, just a second event on it. Logs the session out
        // server-side (not just a client-side redirect) so it can't be
        // bypassed by navigating back; reuses the existing logout route
        // rather than a bespoke endpoint.
        if (window.Echo) {
            window.Echo.private(`user.${bell.dataset.userId}`).listen('.account.deactivated', () => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                fetch('/logout', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                }).finally(() => {
                    window.location.href = '/login';
                });
            });
        }
    }
});

/**
 * Fetches `opts.refreshUrl`, swaps the result into `opts.target`, and runs
 * `opts.onSwap`. Shared by both trigger mechanisms below — a poll cycle
 * that noticed something changed, or an instant WebSocket push — so a
 * live update behaves identically no matter which one triggered it.
 *
 * @param {Object} opts
 * @param {string} opts.refreshUrl - returns an HTML fragment to swap into `target`
 * @param {Element} opts.target - element whose innerHTML gets replaced
 * @param {Function} [opts.isBusy] - return true to skip this update (e.g. user mid-input); caller decides whether to retry
 * @param {Function} [opts.onSwap] - called after a successful swap, e.g. to re-apply a client-side filter
 * @param {boolean} [opts.preserveQueryString] - forward the current page's ?query to refreshUrl (for filtered lists)
 * @param {*} [signalData] - passed through to onSwap (e.g. the poll payload that triggered this)
 */
function applyLiveRefresh(opts, signalData) {
    if (opts.isBusy && opts.isBusy()) return;

    const url = opts.preserveQueryString ? opts.refreshUrl + window.location.search : opts.refreshUrl;

    fetch(url, { headers: { Accept: 'text/html' } })
        .then((res) => (res.ok ? res.text() : Promise.reject(res)))
        .then((html) => {
            opts.target.innerHTML = html;
            if (opts.onSwap) opts.onSwap(signalData);
        })
        .catch(() => {});
}
// Exposed for callers outside this module (e.g. an inline page script that
// wants to trigger the exact same fragment-swap after its own action
// succeeds — a form submit, for instance — instead of only reacting to a
// poll/channel signal).
window.applyLiveRefresh = applyLiveRefresh;

/**
 * Real-time, primary update mechanism: subscribes to a private Reverb
 * channel and applies a live refresh the instant the given event fires —
 * no polling delay. Used for everything that can change from another
 * user's action (a new document routed to an approver, a status change,
 * a new notification) so it shows up genuinely live, not on the next
 * check.
 *
 * @param {string} channelName - without the leading "private-" (Echo adds it)
 * @param {string} eventName - e.g. '.document.status-changed' (leading dot = no namespace prefix)
 * @param {Object} opts - same shape as applyLiveRefresh's opts
 * @param {Function} [opts.filter] - (eventData) => bool; skip the refresh entirely
 *   if this returns false. For a channel that carries events for more than
 *   one thing (e.g. an originator's channel covers ALL of their documents),
 *   this is how a single-document page ignores events about other documents.
 */
function startLiveChannel(channelName, eventName, opts) {
    if (!window.Echo) return;
    window.Echo.private(channelName).listen(eventName, (data) => {
        if (opts.filter && !opts.filter(data)) return;
        applyLiveRefresh(opts, data);
    });
}
window.startLiveChannel = startLiveChannel;

/**
 * Fallback safety net, not the primary mechanism — mirrors this app's own
 * SLA architecture (EscalateAssignmentJob fires instantly via the queue,
 * with a slow periodic sweep behind it in case that ever misses). If the
 * WebSocket connection drops, this still eventually catches up instead of
 * silently going stale forever.
 *
 * @param {Object} opts - same shape as applyLiveRefresh's opts, plus:
 * @param {string} opts.pollUrl - returns JSON to compare against the last-seen value
 * @param {number} [opts.minDelay] - seconds, default 45
 * @param {number} [opts.maxDelay] - seconds, default 75
 */
function startLivePoll(opts) {
    let lastSignal = null; // null = "not established yet", first poll just primes it, never swaps
    const minDelay = opts.minDelay ?? 45;
    const maxDelay = opts.maxDelay ?? 75;

    const scheduleNext = () => setTimeout(poll, (minDelay + Math.random() * (maxDelay - minDelay)) * 1000);

    const poll = () => {
        fetch(opts.pollUrl, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                const signal = JSON.stringify(data);
                if (lastSignal === null) {
                    lastSignal = signal; // establish baseline on first successful poll, don't swap
                    return;
                }
                if (signal === lastSignal) return;
                if (opts.isBusy && opts.isBusy()) return; // leave lastSignal stale so we retry next cycle

                applyLiveRefresh(opts, data);
                lastSignal = signal;
            })
            .catch(() => {})
            .finally(scheduleNext);
    };

    scheduleNext();
}
window.startLivePoll = startLivePoll;

/**
 * Intercepts clicks on pagination links (see vendor/pagination/custom.blade.php
 * — the app-wide custom pagination view) inside `container`, fetching
 * `opts.refreshUrl` with the CLICKED link's own query string instead of
 * letting the browser navigate. Fixes the same scroll-jump-to-top problem
 * decide()/decideBatch() had (see approver/dashboard.blade.php): a plain
 * pagination `<a>` causes a full page navigation, which always reloads
 * scrolled to the top.
 *
 * Swaps `container`'s innerHTML (reusing the exact same fragment route
 * every poll/channel refresh on that page already uses — both paginated
 * sections on a page like SLA Queue or ML Training render inside the SAME
 * shared container, and the clicked link's own href already carries BOTH
 * pagination params correctly merged — see AdminController::
 * paginateContainers()), then updates the visible URL via
 * history.pushState so back/forward/refresh/bookmark all still resolve to
 * the real page + that exact page number, not the fragment route. A
 * `popstate` listener (registered once, shared by every container that
 * calls this) re-syncs content on browser back/forward, since pushState
 * alone only changes the address bar, not the DOM.
 *
 * @param {Element} container - swapped via innerHTML, same as applyLiveRefresh's opts.target
 * @param {Object} opts
 * @param {string} opts.refreshUrl - returns an HTML fragment (this page's own .../refresh route)
 * @param {Function} [opts.onSwap] - called after a successful swap
 */
const __ajaxPaginationContainers = [];

function __refreshAjaxPaginationContainer(container, opts, search) {
    fetch(opts.refreshUrl + search, { headers: { Accept: 'text/html' } })
        .then((res) => (res.ok ? res.text() : Promise.reject(res)))
        .then((html) => {
            container.innerHTML = html;
            if (opts.onSwap) opts.onSwap();
        })
        .catch(() => { window.location.reload(); }); // fetch failed — fall back to a real navigation rather than leave stale content up
}

function enableAjaxPagination(container, opts) {
    __ajaxPaginationContainers.push({ container, opts });

    container.addEventListener('click', function (e) {
        const link = e.target.closest('a[rel="prev"], a[rel="next"], a[aria-label^="Go to page"]');
        if (!link || !link.closest('nav[aria-label="Pagination Navigation"]')) return;

        e.preventDefault();
        const url = new URL(link.href, window.location.href);
        // link.href's pathname is already the real page route, not the
        // fragment route — every paginator in this app is built with the
        // real route() as its `path` specifically so this holds.
        history.pushState({}, '', url.pathname + url.search);
        __refreshAjaxPaginationContainer(container, opts, url.search);
    });
}
window.enableAjaxPagination = enableAjaxPagination;

window.addEventListener('popstate', () => {
    __ajaxPaginationContainers.forEach(({ container, opts }) => {
        __refreshAjaxPaginationContainer(container, opts, window.location.search);
    });
});
