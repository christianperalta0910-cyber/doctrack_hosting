{{--
    Generic "are you sure?" confirmation popup — styled like the rest of
    the app instead of the browser's native confirm() dialog (which shows
    the raw hostname and can't be restyled or themed). Reusable:
    openConfirmModal() takes the copy and a callback, so any future
    destructive action can call this instead of reaching for confirm()
    again. Same fixed-overlay pattern as components/kpi-drilldown-modal.
    blade.php, just a small fixed-size card instead of a large fetched
    panel — flat tint, not backdrop-blur, for the same reason documented
    on that component (measurably lags on weaker graphics hardware).
--}}
<div id="confirm-modal-overlay" class="hidden fixed inset-0 z-[60] bg-surface-900/70 flex items-center justify-center p-4" onclick="if(event.target === this) closeConfirmModal()">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden" onclick="event.stopPropagation()">
        <div class="px-6 py-5">
            <h3 id="confirm-modal-title" class="text-sm font-semibold text-surface-900 mb-2"></h3>
            <p id="confirm-modal-message" class="text-sm text-surface-600 leading-relaxed"></p>
        </div>
        <div class="px-6 py-4 bg-surface-50 border-t border-surface-200 flex items-center justify-end gap-3">
            <button type="button" onclick="closeConfirmModal()" class="text-sm font-medium text-surface-600 hover:text-surface-800 px-3 py-2">Cancel</button>
            <button type="button" id="confirm-modal-confirm-btn" class="text-sm font-semibold text-white px-4 py-2 rounded-lg"></button>
        </div>
    </div>
</div>

<script>
    let __confirmModalOnConfirm = null;

    function closeConfirmModal() {
        document.getElementById('confirm-modal-overlay').classList.add('hidden');
        __confirmModalOnConfirm = null;
    }

    /**
     * @param {Object} opts
     * @param {string} opts.title
     * @param {string} [opts.message]
     * @param {string} [opts.confirmLabel] - default 'Confirm'
     * @param {string} [opts.confirmClass] - Tailwind classes for the confirm
     *   button's color; default destructive red (withdrawing/deleting
     *   something). Pass a neutral/primary one for a non-destructive
     *   confirmation.
     * @param {Function} opts.onConfirm - called once, only if Confirm is clicked
     */
    function openConfirmModal(opts) {
        document.getElementById('confirm-modal-title').textContent = opts.title || 'Are you sure?';
        document.getElementById('confirm-modal-message').textContent = opts.message || '';

        const confirmBtn = document.getElementById('confirm-modal-confirm-btn');
        confirmBtn.textContent = opts.confirmLabel || 'Confirm';
        confirmBtn.className = 'text-sm font-semibold text-white px-4 py-2 rounded-lg ' +
            (opts.confirmClass || 'bg-rejected-600 hover:bg-rejected-700');

        __confirmModalOnConfirm = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
        document.getElementById('confirm-modal-overlay').classList.remove('hidden');
    }

    document.getElementById('confirm-modal-confirm-btn').addEventListener('click', function () {
        const callback = __confirmModalOnConfirm;
        closeConfirmModal();
        if (callback) callback();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeConfirmModal();
    });
</script>
