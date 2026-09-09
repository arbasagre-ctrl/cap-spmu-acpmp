<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\BillingStatement;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\NotificationEvent;
use App\Models\Penalty;
use App\Services\CustodyService;
use App\Services\OperationalCalendarService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustodyController extends Controller
{
    public function index(Request $request): View
    {
        $query = CustodyTransaction::with(['borrower.organizationalUnit', 'request.currentVersion', 'lines.requestItem.inventoryItem', 'laundryJob.latestEvidence.file', 'incidents', 'overdueCase'])->latest();
        if (strtoupper((string) $request->session()->get('active_workspace')) === 'BORROWER') {
            $query->where('borrower_user_id', $request->user()->id);
        }

        return view('custody.index', ['custodies' => $query->get()]);
    }

    public function releaseIndex(Request $request): View
    {
        $this->authorizeSpmuOfficer($request);

        $custodies = CustodyTransaction::with(['borrower', 'request.currentVersion', 'lines.requestItem.inventoryItem', 'incidents', 'overdueCase'])
            ->whereNull('released_at')
            ->where('status', 'PREPARING_RELEASE')
            ->latest()
            ->get();

        return view('custody.index', [
            'custodies' => $custodies,
            'spmuMode' => 'release',
        ]);
    }

    public function returnIndex(Request $request): View
    {
        $this->authorizeSpmuOfficer($request);

        $relations = ['borrower', 'request.currentVersion', 'lines.requestItem.inventoryItem', 'laundryJob.latestEvidence.file', 'incidents', 'overdueCase'];
        $hasEarlyReturnTable = Schema::hasTable('early_return_requests');

        if ($hasEarlyReturnTable) {
            $relations['earlyReturnRequests'] = fn ($query) => $query
                ->where('status', 'REQUESTED')
                ->latest('requested_at');
        }

        $custodies = CustodyTransaction::with($relations)
            ->whereNotNull('released_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->latest()
            ->get();

        if (! $hasEarlyReturnTable) {
            $custodies->each(
                fn (CustodyTransaction $custody) => $custody->setRelation('earlyReturnRequests', collect())
            );
        }

        /*
         * Put active Early Return requests first so the Action Officer can
         * notice them immediately, while retaining the normal newest-first
         * order within the Early Return and regular-return groups.
         */
        $custodies = $custodies->sort(function (CustodyTransaction $left, CustodyTransaction $right): int {
            $leftEarly = $left->earlyReturnRequests->isNotEmpty() ? 1 : 0;
            $rightEarly = $right->earlyReturnRequests->isNotEmpty() ? 1 : 0;

            if ($leftEarly !== $rightEarly) {
                return $rightEarly <=> $leftEarly;
            }

            return ($right->updated_at?->timestamp ?? 0) <=> ($left->updated_at?->timestamp ?? 0);
        })->values();

        return view('custody.index', [
            'custodies' => $custodies,
            'spmuMode' => 'return',
        ]);
    }

    public function show(Request $request, CustodyTransaction $custody): View|RedirectResponse
    {
        $this->authorizeCustody($request, $custody);

        if (
            strtoupper((string) $request->session()->get('active_workspace')) === 'SPMU'
            && $request->user()?->access_classification === AccessClassification::SpmuOfficer
        ) {
            return redirect()->route(
                $custody->released_at ? 'custody.return.show' : 'custody.release.show',
                $custody
            );
        }

        return $this->renderShow($request, $custody);
    }

    public function releaseShow(Request $request, CustodyTransaction $custody): View|RedirectResponse
    {
        $this->authorizeSpmuOfficer($request);
        $this->authorizeCustody($request, $custody);

        if ($custody->released_at) {
            return redirect()->route('custody.return.show', $custody);
        }

        return $this->renderShow($request, $custody, 'release');
    }

    public function returnShow(Request $request, CustodyTransaction $custody): View|RedirectResponse
    {
        $this->authorizeSpmuOfficer($request);
        $this->authorizeCustody($request, $custody);

        if (! $custody->released_at) {
            return redirect()->route('custody.release.show', $custody);
        }

        return $this->renderShow($request, $custody, 'return');
    }

    private function renderShow(Request $request, CustodyTransaction $custody, ?string $spmuMode = null): View
    {
        $relations = [
            'borrower',
            'request.currentVersion.approvalSteps',
            'request.statusHistory',
            'lines.allocation',
            'lines.requestItem.inventoryItem.unit',
            'returns.lines.laundryRecord',
            'incidents',
            'overdueCase',
            'gatePass.accomplishedFile',
        ];

        if (Schema::hasTable('laundry_jobs')) {
            $relations[] = 'laundryJob.document.file';
            $relations[] = 'laundryJob.latestEvidence.file';
            $relations[] = 'laundryJob.lines.custodyLine.requestItem.inventoryItem.unit';
            $relations[] = 'laundryJob.formVerifier';
        }

        if (Schema::hasTable('early_return_requests')) {
            $relations[] = Schema::hasTable('early_return_request_lines')
                ? 'earlyReturnRequests.lines.custodyLine.requestItem'
                : 'earlyReturnRequests';
        } else {
            $custody->setRelation('earlyReturnRequests', collect());
        }

        $custody->load($relations);

        $incidentIds = Incident::query()
            ->where('custody_transaction_id', $custody->id)
            ->pluck('id');

        $penaltyIds = Penalty::query()
            ->where('custody_transaction_id', $custody->id)
            ->pluck('id');

        $billingIds = collect();

        if ($incidentIds->isNotEmpty() || $penaltyIds->isNotEmpty()) {
            $billingIds = DB::table('billing_lines')
                ->where(function ($query) use ($incidentIds, $penaltyIds): void {
                    if ($incidentIds->isNotEmpty()) {
                        $query->whereIn('incident_id', $incidentIds);
                    }

                    if ($penaltyIds->isNotEmpty()) {
                        $method = $incidentIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('penalty_id', $penaltyIds);
                    }
                })
                ->pluck('billing_statement_id')
                ->unique()
                ->values();
        }

        $relatedBillings = BillingStatement::query()
            ->with('payments')
            ->whereIn('id', $billingIds)
            ->latest('issued_at')
            ->get();

        $latestReceipt = $relatedBillings
            ->flatMap(fn (BillingStatement $billing) => $billing->payments)
            ->filter(fn ($payment) => $payment->evidence_file_id)
            ->sortByDesc(fn ($payment) => $payment->submitted_at?->timestamp ?? 0)
            ->first();

        $pickupNotificationEvents = NotificationEvent::query()
            ->with(['deliveries' => fn ($query) => $query
                ->where('recipient_user_id', $custody->borrower_user_id)
                ->orderByDesc('attempted_at')])
            ->where('source_type', $custody->getMorphClass())
            ->where('source_id', $custody->id)
            ->whereIn('event_code', ['PICKUP_SCHEDULED', 'PICKUP_EXPIRED', 'PICKUP_RESCHEDULE_REQUESTED'])
            ->latest('occurred_at')
            ->get()
            ->groupBy('event_code')
            ->map(fn ($events) => $events->first());

        /*
         * A missed/unusable pickup can stay on the same approved request only
         * while another valid SPMU Pickup / Release operating window still
         * exists strictly BEFORE the approved Expected Return Date. The AO
         * must never extend the approved borrowing period through rescheduling.
         */
        $nextPickupRescheduleAt = null;
        $pickupRescheduleAvailable = false;
        $approvedReturnDate = $custody->original_due_at ?: $custody->due_at;

        if (! $custody->released_at && $custody->status === 'PREPARING_RELEASE' && $approvedReturnDate) {
            $timezone = config('app.timezone') ?: 'Asia/Manila';
            $now = now($timezone);
            $nextPickupRescheduleAt = app(OperationalCalendarService::class)->nextPickupWindow($now);
            $returnDay = \Carbon\CarbonImmutable::parse($approvedReturnDate, $timezone)->startOfDay();

            $pickupRescheduleAvailable = $nextPickupRescheduleAt !== null
                && $nextPickupRescheduleAt->copy()->startOfDay()->lt($returnDay);
        }

        $pickupRescheduleRequestEvent = $pickupNotificationEvents->get('PICKUP_RESCHEDULE_REQUESTED');
        $pickupRescheduleRequested = (bool) $pickupRescheduleRequestEvent
            && (
                ! $custody->pickup_scheduled_at
                || $pickupRescheduleRequestEvent->occurred_at?->gt($custody->pickup_scheduled_at)
            );

        return view('custody.show', [
            'custody' => $custody,
            'spmuMode' => $spmuMode,
            'relatedBillings' => $relatedBillings,
            'latestReceipt' => $latestReceipt,
            'pickupScheduleNotification' => $pickupNotificationEvents->get('PICKUP_SCHEDULED'),
            'pickupMissedNotification' => $pickupNotificationEvents->get('PICKUP_EXPIRED'),
            'pickupRescheduleRequestEvent' => $pickupRescheduleRequestEvent,
            'pickupRescheduleRequested' => $pickupRescheduleRequested,
            'pickupRescheduleAvailable' => $pickupRescheduleAvailable,
            'nextPickupRescheduleAt' => $nextPickupRescheduleAt,
            'documents' => $custody->request->currentVersion
                ->documents()
                ->where(function ($query) use ($custody) {
                    $query->where('document_type', 'APPROVED_REQUEST_LETTER')
                        ->orWhere(function ($query) use ($custody) {
                            $query->where('subject_type', CustodyTransaction::class)
                                ->where('subject_id', $custody->id);
                        });
                })
                ->with('evidence')
                ->latest()
                ->get(),
        ]);
    }

    public function schedulePickup(
        Request $request,
        CustodyTransaction $custody,
        CustodyService $service
    ): RedirectResponse {
        $this->authorizeCustody($request, $custody);

        abort_unless(
            strtoupper((string) $request->session()->get('active_workspace')) === 'SPMU'
                && $request->user()?->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $request->user()->id,
            403
        );

        $preparationAlreadyComplete = (bool) $custody->prepared_at;

        $service->confirmPickupSchedule(
            $custody,
            $request->user()
        );

        $message = 'System-generated Pickup / Issuance schedule confirmed. Notification delivery attempts were recorded and are shown in the Pickup & Issuance Schedule section.';

        $message .= $preparationAlreadyComplete
            ? ' Existing item preparation remains valid.'
            : ' Continue with Item Preparation below.';

        return redirect()
            ->to(route('custody.release.show', $custody).'#item-preparation')
            ->with('status', $message);
    }

    public function requestPickupReschedule(
        Request $request,
        CustodyTransaction $custody,
        CustodyService $service
    ): RedirectResponse {
        $this->authorizeCustody($request, $custody);

        abort_unless(
            strtoupper((string) $request->session()->get('active_workspace')) === 'BORROWER'
                && $request->user()?->id === $custody->borrower_user_id,
            403
        );

        $service->requestPickupReschedule(
            $custody,
            $request->user()
        );

        return redirect()
            ->to(route('custody.show', $custody).'#pickup-reschedule')
            ->with(
                'status',
                'Pickup reschedule requested. SPMU has been notified and will confirm the next valid operating window. You do not need to submit a new borrowing request.'
            );
    }

    public function reschedulePickup(
        Request $request,
        CustodyTransaction $custody,
        CustodyService $service
    ): RedirectResponse {
        $this->authorizeCustody($request, $custody);

        abort_unless(
            strtoupper((string) $request->session()->get('active_workspace')) === 'SPMU'
                && $request->user()?->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $request->user()->id,
            403
        );

        $service->rescheduleMissedPickup(
            $custody,
            $request->user()
        );

        return redirect()
            ->to(route('custody.release.show', $custody).'#pickup-schedule')
            ->with(
                'status',
                'Pickup & Issuance was rescheduled on the same approved request to the next valid SPMU operating window. Notification delivery attempts were recorded; no new borrowing request is required.'
            );
    }

    public function quantities(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:0'],
            'reasons' => ['nullable', 'array'],
        ]);
        $service->updateReceiptQuantities($custody, $request->user(), $data['quantities'], $data['reasons'] ?? []);

        return back()->with('status', 'Quantity to receive saved. SPMU must verify any reduction before acknowledgement.');
    }

    public function prepare(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:0'],
        ]);

        $service->prepare($custody, $request->user(), $data['quantities']);

        return redirect()
            ->to(route('custody.release.show', $custody).'#release-actions')
            ->with(
                'status',
                'Item preparation confirmed. Continue with the physical documents and release steps below.'
            );
    }

    public function acknowledge(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $service->acknowledge($custody, $request->user());

        return back()->with('status', 'Borrower acknowledgement recorded. This is a system confirmation only; no electronic signature was created.');
    }

    public function release(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $data = $request->validate([
            'physical_signatures_confirmed' => ['required', 'accepted'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], [
            'physical_signatures_confirmed.accepted' => 'Confirm that the physical handover and applicable handwritten acknowledgements were completed.',
        ]);

        $service->release(
            $custody,
            $request->user(),
            $data['remarks'] ?? null
        );

        return redirect()
            ->route('custody.return.show', $custody)
            ->with(
                'status',
                'Physical handover recorded. Custody is now Released / On Custody and the transaction is under Return tracking.'
            );
    }

    public function receiveReturn(Request $request, CustodyTransaction $custody, CustodyService $service, ProtectedFileService $files): RedirectResponse
    {
        $data = $request->validate([
            // Current full-accounting UI: one condition breakdown per custody line.
            'accounting' => ['nullable', 'array'],
            'accounting.*' => ['nullable', 'array'],
            'accounting.*.FINE' => ['nullable', 'integer', 'min:0'],
            'accounting.*.DAMAGED' => ['nullable', 'integer', 'min:0'],
            'accounting.*.DESTROYED' => ['nullable', 'integer', 'min:0'],
            'accounting.*.MISSING' => ['nullable', 'integer', 'min:0'],
            'accounting.*.LOST' => ['nullable', 'integer', 'min:0'],
            'accounting.*.STOLEN' => ['nullable', 'integer', 'min:0'],

            // Legacy payload support for existing tests/API clients.
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['nullable', Rule::in(['FINE', 'DAMAGED', 'DESTROYED', 'MISSING', 'LOST', 'STOLEN'])],
            'police_blotter_references' => ['nullable', 'array'],
            'police_blotter_references.*' => ['nullable', 'string', 'max:255'],
            'evidence_files' => ['nullable', 'array'],
            'evidence_files.*' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'remarks' => ['nullable', 'string', 'max:2000'],

            /*
             * Linen only, and only when Laundry Personnel never captured their
             * receipt digitally. This is the RECEIVED BY date wet-signed on the
             * accomplished Laundry Form, so it can never be in the future.
             */
            'laundry_received_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        /*
         * RETURN INPUT SANITIZATION
         * -------------------------
         * Non-linen may be inspected and fully accounted as soon as it is
         * physically returned to SPMU. Linen remains excluded from encoding
         * until the offline Laundry Worker has checked it, wet-signed the same
         * physical Laundry Form with the actual receipt date, and delivered
         * that accomplished form to SPMU. No Laundry portal user exists.
         */
        $custody->loadMissing('lines.requestItem.inventoryItem', 'laundryJob');
        $eligibleLineIds = [];

        foreach ($custody->lines as $line) {
            $lineId = (int) $line->id;
            $outstanding = max(
                0,
                (float) $line->actual_released_quantity - (float) $line->returned_quantity
            );

            // A line that is already fully accounted must not be submitted again.
            if ($outstanding <= 0) {
                unset(
                    $data['accounting'][$lineId],
                    $data['quantities'][$lineId],
                    $data['conditions'][$lineId],
                    $data['police_blotter_references'][$lineId]
                );

                continue;
            }

            $eligibleLineIds[] = $lineId;
        }

        $evidenceFileIds = [];
        foreach ($request->file('evidence_files', []) as $lineId => $upload) {
            $lineId = (int) $lineId;

            if ($upload && in_array($lineId, $eligibleLineIds, true)) {
                $evidenceFileIds[$lineId] = $files->storeUpload(
                    $upload,
                    'incident-evidence',
                    'INCIDENT_EVIDENCE'
                )->id;
            }
        }
        $service->receiveReturn(
            $custody,
            $request->user(),
            $data['quantities'] ?? [],
            $data['conditions'] ?? [],
            $data['remarks'] ?? null,
            $data['police_blotter_references'] ?? [],
            $evidenceFileIds,
            conditionBreakdowns: $data['accounting'] ?? [],
            laundryReceivedOn: filled($data['laundry_received_date'] ?? null)
                ? \Carbon\Carbon::parse($data['laundry_received_date'])->startOfDay()
                : null,
        );

        /*
         * GUIDED TRANSACTION HANDOFF
         * --------------------------
         * Keep the Action Officer inside the same transaction. After Return
         * Inspection, send them directly to the exact next required record
         * instead of making them search the Gate Pass or Laundry queues.
         */
        $custody->refresh()->loadMissing([
            'gatePass',
            'laundryJob.latestEvidence',
            'lines.requestItem.inventoryItem',
        ]);

        $remainingOnCustody = $custody->lines->sum(
            fn ($line) => max(
                0,
                (float) $line->actual_released_quantity - (float) $line->returned_quantity
            )
        );

        if ($remainingOnCustody <= 0) {
            $gatePass = $custody->gatePass;

            if ($gatePass
                && $gatePass->status !== 'VERIFIED'
                && ! $gatePass->accomplished_file_id) {
                return redirect()
                    ->route('gate-passes.show', $gatePass)
                    ->with(
                        'status',
                        'Return inspection recorded. Next: record the accomplished Gate Pass for this transaction.'
                    );
            }

            $laundryJob = $custody->laundryJob;

            if ($laundryJob && $laundryJob->status === 'TURNED_OVER_TO_LAUNDRY') {
                return redirect()
                    ->route('laundry.show', $laundryJob)
                    ->with(
                        'status',
                        'Return inspection recorded. This older Laundry record is awaiting compatibility availability reconciliation.'
                    );
            }

            if ($laundryJob && $laundryJob->status === 'LAUNDRY_COMPLETED') {
                return redirect()
                    ->to(route('custody.return.show', $custody).'#return-summary')
                    ->with(
                        'status',
                        'Return inspection recorded. The completed Laundry Form was encoded and serviceable linen is Available. Any late or adverse finding continues through Accountability Processing.'
                    );
            }
        }

        return redirect()
            ->to(route('custody.return.show', $custody).'#return-primary')
            ->with('status', 'Return inspection recorded.');
    }

    public function requestEarlyReturn(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $this->authorizeCustody($request, $custody);

        abort_unless(
            strtoupper((string) $request->session()->get('active_workspace')) === 'BORROWER'
                && $custody->borrower_user_id === $request->user()?->id,
            403
        );

        $data = $request->validate([
            'proposed_return_at' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->requestEarlyReturn(
            $custody,
            $request->user(),
            $data['proposed_return_at'],
            $data['reason'] ?? null
        );

        return back()->with(
            'status',
            'Early Return coordination sent to SPMU. Actual quantities and conditions will be recorded only during physical Return & Inspection.'
        );
    }

    private function authorizeSpmuOfficer(Request $request): void
    {
        abort_unless(
            strtoupper((string) $request->session()->get('active_workspace')) === 'SPMU'
                && $request->user()?->access_classification === AccessClassification::SpmuOfficer,
            403
        );
    }

    private function authorizeCustody(Request $request, CustodyTransaction $custody): void
    {
        $user = $request->user();
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        abort_unless(($workspace === 'BORROWER' && $custody->borrower_user_id === $user->id) || $workspace === 'SPMU', 403);
    }
}
