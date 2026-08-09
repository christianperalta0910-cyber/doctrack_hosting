<?php

namespace App\Http\Controllers;

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\SubmissionBatch;
use App\Rules\ReliableMimeType;
use App\Services\ValidationService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(private WorkflowService $workflow)
    {
    }

    /**
     * The originator's filtered/paginated submissions query — shared by
     * dashboard() (full page), refresh() (the AJAX fragment the live-poll
     * JS swaps in), and poll() (the cheap "did anything change" signal),
     * so all three always agree on what's currently visible for a given
     * set of filters.
     */
    private function documentsQuery(Request $request, int $userId)
    {
        $query = DocumentRepository::forOriginator($userId)
            ->with(['currentAssignment.stage', 'assignments.stage', 'batch']);

        if ($request->filled('document')) {
            $query->where('title', 'like', '%' . $request->string('document') . '%');
        }
        if ($request->filled('status')) {
            $query->where('global_status', $request->string('status'));
        }
        if ($request->filled('category')) {
            $query->where('ml_category', $request->string('category'));
        }

        return $query;
    }

    /** Originator dashboard: drag-drop upload + live tracking list (DFD 3.1-3.4, 4.0). */
    public function dashboard(Request $request)
    {
        $documents = $this->documentsQuery($request, $request->user()->user_id)
            ->latest('upload_date')->paginate(5)->withQueryString()
            // Real page route, not the implicit current-request path —
            // this is also built from within refresh() (the live-poll
            // fragment route); see AdminController::paginateContainers()'s
            // docblock for the full reasoning.
            ->withPath(route('originator.dashboard'));

        $categories = ValidationService::knownCategories();

        return view('originator.dashboard', compact('documents', 'categories'));
    }

    /**
     * Renders just the submissions fragment (originator/partials/submissions.blade.php)
     * for the dashboard's live-poll JS to swap in place — see
     * resources/js/app.js's startLivePoll() and dashboard.blade.php for why
     * this beats a full page reload. Respects the same filters as a normal
     * page load (the JS forwards the current query string).
     */
    public function refresh(Request $request)
    {
        $documents = $this->documentsQuery($request, $request->user()->user_id)
            ->latest('upload_date')->paginate(5)->withQueryString()
            // Real page route, not the implicit current-request path —
            // this is also built from within refresh() (the live-poll
            // fragment route); see AdminController::paginateContainers()'s
            // docblock for the full reasoning.
            ->withPath(route('originator.dashboard'));

        return view('originator.partials.submissions', compact('documents'));
    }

    /**
     * Lightweight JSON endpoint the dashboard's JS polls every ~5-10s. A
     * plain row *count* alone would miss an existing document's status
     * changing (processing -> approved, say) without any row being
     * added/removed, so this also reports the newest `updated_at` across
     * the filtered set — either changing is enough to trigger a live swap.
     */
    public function poll(Request $request)
    {
        $query = $this->documentsQuery($request, $request->user()->user_id);

        return response()->json([
            'count' => (clone $query)->count(),
            'latest_update' => $query->max('updated_at'),
        ]);
    }

    /**
     * Accepts one or more files in a single submission. Every file in the
     * request is linked to one new SubmissionBatch and shares the same
     * due date, so Approvers and Admins see them nested together as one
     * approval request instead of as unrelated flat rows (Feature: grouped
     * dashboards).
     */
    public function store(Request $request)
    {
        // Explicit validation on every document metadata input (Section 3).
        // Content-based MIME verification is layered on top of mimes:, but
        // deliberately only for the formats that sniff reliably across
        // OS/Office versions (pdf/png/jpg/txt) — legacy .doc (OLE2) and
        // .docx (zip) sniff inconsistently enough that a strict mimetype
        // check would false-reject legitimate Word files, so those stay
        // protected by the mimes: extension-mapping rule only (with
        // WorkflowService::ingest()'s extraction_failed handling as a
        // downstream backstop for garbage content that slips through).
        // "files.0", "files.1", etc. are meaningless to a user picking 20
        // files at once — swap in each file's own original name so a
        // failure reads "'weird_scan.heic' must be a file of type: ..."
        // instead of "The files.0 field must be...", which gives no way to
        // tell which of the selected files actually failed.
        $fileAttributeNames = [];
        foreach ($request->file('files', []) as $index => $file) {
            if ($file) {
                $fileAttributeNames["files.{$index}"] = "'" . $file->getClientOriginalName() . "'";
            }
        }

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => [
                'file',
                'mimes:pdf,docx,doc,txt,png,jpg,jpeg',
                new ReliableMimeType(),
                'max:20480',
            ],
            'due_date' => ['required', 'date', function ($attribute, $value, $fail) {
                $buffer = config('sla.min_due_date_buffer_minutes', 15);
                if (Carbon::parse($value)->lt(now()->addMinutes($buffer))) {
                    $fail("The due date must be at least {$buffer} minutes from now.");
                }
            }],
            'requires_printing' => ['sometimes', 'boolean'],
        ], [], $fileAttributeNames);

        // Section 1 (extended): a due date landing on a non-working day
        // (weekend/holiday) is auto-shifted forward to the next working
        // day BEFORE the batch/documents are created, so the batch header,
        // every document, and every routed assignment all agree on the
        // same (possibly adjusted) due date instead of drifting apart.
        $requestedDueDate = Carbon::parse($validated['due_date']);
        $effectiveDueDate = $this->workflow->resolveEffectiveDueDate($validated['due_date']);
        // Unchecked means "no explicit override" — WorkflowService::ingest()
        // still applies the category's own default once classification
        // determines it, this only ever adds the requirement, never
        // removes one the category itself calls for.
        $requiresPrinting = $request->boolean('requires_printing');

        $batch = SubmissionBatch::create([
            'originator_id' => $request->user()->user_id,
            'due_date' => $effectiveDueDate,
        ]);

        $documents = collect($validated['files'])
            ->map(fn ($file) => $this->workflow->ingest($file, $request->user(), $effectiveDueDate->toDateTimeString(), $batch->batch_id, null, $requiresPrinting));

        $status = $this->buildSubmissionStatusMessage($documents);
        if (!$effectiveDueDate->equalTo($requestedDueDate)) {
            $status .= " Note: your requested due date ({$requestedDueDate->format('M j, Y g:i A')}) fell on a non-working day, " .
                "so it was automatically moved to {$effectiveDueDate->format('M j, Y g:i A')}.";
        }

        return redirect()
            ->route('originator.dashboard')
            ->with('status', $status);
    }

    private function buildSubmissionStatusMessage($documents): string
    {
        if ($documents->count() === 1) {
            $document = $documents->first();
            return $document->is_validated
                ? "'{$document->title}' uploaded, classified as '{$document->ml_category}', and routed for approval."
                : "'{$document->title}' uploaded but failed validation — see details below.";
        }

        $failedCount = $documents->reject(fn ($d) => $d->is_validated)->count();

        return "{$documents->count()} documents uploaded together and routed as one approval request." .
            ($failedCount > 0 ? " {$failedCount} failed validation — see details below." : '');
    }

    public function show(Request $request, DocumentRepository $document)
    {
        abort_unless($document->originator_id === $request->user()->user_id || $request->user()->isAdmin(), 403);

        $document->load(['assignments.stage', 'assignments.approver', 'auditLogs.user', 'previousVersion', 'nextVersion']);

        return view('originator.tracking', compact('document'));
    }

    /**
     * Renders just the tracking page body (originator/partials/tracking-content.blade.php)
     * for that page's live-poll JS to swap in place — see
     * resources/js/app.js's startLiveChannel()/startLivePoll() and
     * tracking.blade.php for why this beats a full page reload. Reacts to
     * ANY stage on this document being decided, not just the document's
     * overall global_status finalizing — see DocumentAssignment::booted().
     */
    public function trackingRefresh(Request $request, DocumentRepository $document)
    {
        abort_unless($document->originator_id === $request->user()->user_id || $request->user()->isAdmin(), 403);

        $document->load(['assignments.stage', 'assignments.approver', 'auditLogs.user', 'previousVersion', 'nextVersion']);

        return view('originator.partials.tracking-content', compact('document'));
    }

    /**
     * Lightweight JSON endpoint this document's tracking page polls every
     * ~5-10s as a fallback if the WebSocket connection is down. Reports
     * global_status, the newest audit-log timestamp, and a hash of every
     * assignment's individual_status — any of the three changing (a stage
     * being decided mid-pipeline doesn't necessarily change global_status
     * or add an audit-log row alone) is enough to trigger a live swap.
     */
    public function trackingPoll(Request $request, DocumentRepository $document)
    {
        abort_unless($document->originator_id === $request->user()->user_id || $request->user()->isAdmin(), 403);

        return response()->json([
            'status' => $document->global_status,
            'latest_audit' => $document->auditLogs()->max('timestamp'),
            'assignment_statuses' => $document->assignments()->pluck('individual_status', 'assignment_id'),
        ]);
    }

    /**
     * Section 5 (extended): a rejected document was previously a dead end —
     * the only way to try again was uploading an entirely new, unrelated
     * document. This re-runs the same ingest pipeline (extract, classify,
     * validate, route) on a revised file, but links the result back to the
     * rejected document as the next entry in its version chain instead.
     */
    public function resubmit(Request $request, DocumentRepository $document)
    {
        abort_unless($document->originator_id === $request->user()->user_id, 403);
        abort_unless($document->global_status === 'rejected', 409, 'Only a rejected document can be resubmitted.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,doc,txt,png,jpg,jpeg', new ReliableMimeType(), 'max:20480'],
            'due_date' => ['required', 'date', function ($attribute, $value, $fail) {
                $buffer = config('sla.min_due_date_buffer_minutes', 15);
                if (Carbon::parse($value)->lt(now()->addMinutes($buffer))) {
                    $fail("The due date must be at least {$buffer} minutes from now.");
                }
            }],
        ]);

        $effectiveDueDate = $this->workflow->resolveEffectiveDueDate($validated['due_date']);

        $newDocument = $this->workflow->ingest(
            $validated['file'],
            $request->user(),
            $effectiveDueDate->toDateTimeString(),
            null, // resubmissions stand alone, not re-attached to the original's (possibly already-resolved) batch
            $document,
            $document->requires_printing, // carries forward rather than asking again on every resubmission
        );

        $status = $newDocument->is_validated
            ? "Resubmitted as version {$newDocument->version_number}, classified as '{$newDocument->ml_category}', and routed for approval."
            : "Resubmitted as version {$newDocument->version_number}, but failed validation — see details below.";

        return redirect()->route('originator.documents.show', $newDocument)->with('status', $status);
    }

    /**
     * Streams the exact original uploaded file inline (not force-download)
     * for the embedded viewer on the Approver dashboard. Distinct from
     * ArchiveController::download(), which forces a "Save As" download of
     * already-approved documents; this is for reviewing a file still in
     * progress, unaltered from what the originator submitted.
     */
    public function viewFile(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        $isOwner = $document->originator_id === $user->user_id;
        $isAdmin = $user->isAdmin();
        ['isAssignedApprover' => $isAssignedApprover, 'hasPendingApproverSeat' => $hasPendingApproverSeat]
            = $this->approverAccessFor($document, $user);
        $isAdminDeciding = $isAdmin && $this->isAwaitingAdminDecision($document);

        abort_unless($isOwner || $isAdmin || $isAssignedApprover, 403);

        // A review session is only opened for someone about to actually
        // DECIDE this document — an approver with a STILL-PENDING seat on
        // it (not merely any seat ever, which would also match an already-
        // decided one revisited from Decision History/Archive — see
        // approverAccessFor()), or an admin viewing it while it's sitting
        // in one of the queues where THEY are the one who has to act
        // directly (Unassigned Documents, SLA Override, ML/Readability
        // Review — see isAwaitingAdminDecision()). An Originator or Admin
        // merely browsing (Archive, Audit Logs, a document with nothing
        // pending on it) isn't "reviewing" it in that sense, and never
        // hits closeFor(), so opening one for them would just accumulate
        // never-closed rows.
        if ($hasPendingApproverSeat || $isAdminDeciding) {
            DocumentReviewSession::openFor($document, $user);
        }

        abort_unless(Storage::disk('local')->exists($document->file_path), 404, 'File not found.');

        $path = Storage::disk('local')->path($document->file_path);
        $mime = $document->mime_type ?: 'application/octet-stream';

        $response = response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($document->original_filename ?? $document->title) . '"',
        ]);

        // no-store, not response()->file()'s hardcoded "public" default: a
        // browser silently serving a second open from cache skips this
        // whole method entirely, which means openFor() never runs — no
        // review session gets created for that visit, so it never shows up
        // as "reviewing", never gets a realtime push, and its time never
        // gets counted. Every open of this popup needs to genuinely hit the
        // server, not just the first one. Passing 'Cache-Control' in the
        // headers array above isn't enough on its own — BinaryFileResponse's
        // constructor unconditionally calls setPublic() afterwards, which
        // ADDS a "public" directive alongside whatever was already set
        // rather than replacing it, so it has to be removed explicitly
        // here instead.
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->headers->removeCacheControlDirective('public');

        return $response;
    }

    /**
     * Polled every few seconds by the document viewer modal while it's
     * open — returns who's currently reviewing this document (approvers
     * only, see DocumentReviewSession::openFor()'s caller in viewFile()
     * above) and, if the requester is one of them, heartbeats their own
     * session so it stays inside the live() window. Stopping the poll
     * (closing the modal, navigating away, a dead tab) means no more
     * heartbeats, so the icon disappears for everyone else within ~40s
     * without needing an explicit "I closed the viewer" signal.
     */
    public function presence(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        $isOwner = $document->originator_id === $user->user_id;
        $isAdmin = $user->isAdmin();
        ['isAssignedApprover' => $isAssignedApprover, 'hasPendingApproverSeat' => $hasPendingApproverSeat]
            = $this->approverAccessFor($document, $user);
        $isAdminDeciding = $isAdmin && $this->isAwaitingAdminDecision($document);

        abort_unless($isOwner || $isAdmin || $isAssignedApprover, 403);

        if ($hasPendingApproverSeat || $isAdminDeciding) {
            DocumentReviewSession::heartbeat($document, $user);
        }

        $viewers = DocumentReviewSession::live()
            ->where('document_id', $document->document_id)
            ->with('user')
            ->get()
            ->unique('user_id')
            ->values()
            ->map(fn ($session) => [
                'name' => $session->user->full_name ?? 'Unknown',
                'role' => ucfirst($session->user->role ?? ''),
                'category' => $session->user->assigned_category,
            ]);

        return response()->json(['viewers' => $viewers]);
    }

    /**
     * Explicit "I closed the viewer" beacon — fired once on modal close
     * or tab unload (see document-viewer-modal.blade.php). Reuses the
     * same closeFor() a real decision uses (not a separate presence-only
     * mechanism): this both makes the presence icon disappear for other
     * viewers instantly (stillOpen() fails the moment closed_at is set,
     * so it drops out of live() for free) AND correctly records
     * duration_seconds for this review pass, so closing the popup without
     * deciding no longer silently loses that time from the running total
     * — reopening later now counts as a genuine second review pass
     * instead.
     */
    public function presenceLeave(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        $hasPendingApproverSeat = $this->approverAccessFor($document, $user)['hasPendingApproverSeat'];
        $isAdminDeciding = $user->isAdmin() && $this->isAwaitingAdminDecision($document);

        if ($hasPendingApproverSeat || $isAdminDeciding) {
            DocumentReviewSession::closeFor($document, $user);
        }

        return response()->noContent();
    }

    /**
     * This approver's assignment rows on $document, computed once and
     * reused two ways: `isAssignedApprover` (any row at all, even an
     * already-decided one) drives plain view AUTHORIZATION — an approver
     * who already decided their seat can still legitimately look back at
     * a document from Decision History/Archive. `hasPendingApproverSeat`
     * (a still-PENDING row specifically) drives whether opening it right
     * now counts as an active review session — see viewFile()'s docblock
     * for why revisiting an already-decided document shouldn't spin up a
     * fresh DocumentReviewSession every time.
     */
    private function approverAccessFor(DocumentRepository $document, $user): array
    {
        if (!$user->isApprover()) {
            return ['isAssignedApprover' => false, 'hasPendingApproverSeat' => false];
        }

        $assignments = DocumentAssignment::where('document_id', $document->document_id)
            ->where('user_id', $user->user_id)
            ->get(['individual_status']);

        return [
            'isAssignedApprover' => $assignments->isNotEmpty(),
            'hasPendingApproverSeat' => $assignments->contains('individual_status', 'pending'),
        ];
    }

    /**
     * True when $document currently has something sitting in one of the
     * queues where an ADMIN — not an approver — is the one who has to
     * decide it directly: Unassigned Documents (no eligible approver),
     * SLA Override (an approver timed out), or the ML/Readability review
     * queues (see WorkflowService::adminDecideUnassigned(),
     * SlaService::adminOverride(), AdminController's review confirm/
     * reject actions). Drives whether an admin's file view counts as a
     * genuine "review session" (see viewFile()/presence()/presenceLeave()
     * above) — an admin merely browsing a document with nothing pending
     * on it isn't reviewing it in that sense.
     */
    private function isAwaitingAdminDecision(DocumentRepository $document): bool
    {
        if ($document->ml_review_status === 'pending' || $document->readability_review_status === 'pending') {
            return true;
        }

        return DocumentAssignment::where('document_id', $document->document_id)
            ->where('individual_status', 'pending')
            ->where(fn ($q) => $q->where('needs_approver', true)
                ->orWhere(fn ($q2) => $q2->where('escalated_to_admin', true)->whereNull('admin_override_at')))
            ->exists();
    }
}