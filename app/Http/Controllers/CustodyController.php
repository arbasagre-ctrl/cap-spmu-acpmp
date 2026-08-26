<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\BillingStatement;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\Penalty;
use App\Services\CustodyService;
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
        $query = CustodyTransaction::with(['borrower', 'request.currentVersion', 'lines.requestItem.inventoryItem'])->latest();
        if (strtoupper((string) $request->session()->get('active_workspace')) === 'BORROWER') {
            $query->where('borrower_user_id', $request->user()->id);
        }

        return view('custody.index', ['custodies' => $query->get()]);
    }

    public function releaseIndex(Request $request): View
    {
        $this->authorizeSpmuOfficer($request);

        $custodies = CustodyTransaction::with(['borrower', 'request.currentVersion', 'lines.requestItem.inventoryItem'])
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

        $custodies = CustodyTransaction::with(['borrower', 'request.currentVersion', 'lines.requestItem.inventoryItem'])
            ->whereNotNull('released_at')
            ->latest()
            ->get();

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
            'gatePass.accomplishedFile',
        ];

        /*
        * Prevent /custody/{id} from crashing when the Early Return
        * database tables have not yet been created.
        */
        if (Schema::hasTable('laundry_jobs')) {
            $relations[] = 'laundryJob.document.file';
            $relations[] = 'laundryJob.latestEvidence.file';
            $relations[] = 'laundryJob.lines.custodyLine.requestItem.inventoryItem.unit';
            $relations[] = 'laundryJob.formVerifier';
        }

        if (Schema::hasTable('early_return_requests')) {
            if (Schema::hasTable('early_return_request_lines')) {
                $relations[] = 'earlyReturnRequests.lines';
            } else {
                $relations[] = 'earlyReturnRequests';
            }
        } else {
            /*
            * custody.show.blade.php accesses:
            * $custody->earlyReturnRequests
            *
            * Setting an empty relation prevents Laravel from trying
            * to query a table that does not exist.
            */
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

        return view('custody.show', [
            'custody' => $custody,
            'spmuMode' => $spmuMode,
            'relatedBillings' => $relatedBillings,
            'latestReceipt' => $latestReceipt,
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

        $data = $request->validate([
            'pickup_at' => ['required', 'date'],
            'pickup_expires_at' => ['required', 'date', 'after:pickup_at'],
        ]);

        $service->schedulePickup(
            $custody,
            $request->user(),
            $data['pickup_at'],
            $data['pickup_expires_at']
        );

        return redirect()
            ->to(route('custody.release.show', $custody).'#item-preparation')
            ->with(
                'status',
                'Pickup schedule saved and the borrower was notified. Continue with Item Preparation below.'
            );
    }

    public function quantities(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $data = $request->validate(['quantities' => ['required', 'array'], 'reasons' => ['nullable', 'array']]);
        $service->updateReceiptQuantities($custody, $request->user(), $data['quantities'], $data['reasons'] ?? []);

        return back()->with('status', 'Quantity to receive saved. SPMU must verify any reduction before acknowledgement.');
    }

    public function prepare(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'numeric', 'min:0'],
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
                'Physical release completed. The transaction is now under Return tracking.'
            );
    }

    public function receiveReturn(Request $request, CustodyTransaction $custody, CustodyService $service, ProtectedFileService $files): RedirectResponse
    {
        $data = $request->validate([
            // Current full-accounting UI: one condition breakdown per custody line.
            'accounting' => ['nullable', 'array'],
            'accounting.*' => ['nullable', 'array'],
            'accounting.*.FINE' => ['nullable', 'numeric', 'min:0'],
            'accounting.*.DAMAGED' => ['nullable', 'numeric', 'min:0'],
            'accounting.*.DESTROYED' => ['nullable', 'numeric', 'min:0'],
            'accounting.*.MISSING' => ['nullable', 'numeric', 'min:0'],
            'accounting.*.LOST' => ['nullable', 'numeric', 'min:0'],
            'accounting.*.STOLEN' => ['nullable', 'numeric', 'min:0'],

            // Legacy payload support for existing tests/API clients.
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0'],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['nullable', Rule::in(['FINE', 'DAMAGED', 'DESTROYED', 'MISSING', 'LOST', 'STOLEN'])],
            'police_blotter_references' => ['nullable', 'array'],
            'police_blotter_references.*' => ['nullable', 'string', 'max:255'],
            'evidence_files' => ['nullable', 'array'],
            'evidence_files.*' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'early_return' => ['nullable', 'boolean'],
        ]);

        /*
         * RETURN INPUT SANITIZATION
         * -------------------------
         * The Return page is stateful: a line can become fully accounted or a
         * linen line can move between Laundry stages while an older browser
         * form is still open. Never let stale values from an already-completed
         * line produce a misleading "complete Laundry workflow" error.
         *
         * Non-linen is always eligible for ordinary SPMU return inspection
         * while it has an outstanding quantity. Linen is eligible only when
         * the Laundry Worker has brought the cleaned linen back to SPMU
         * (READY_FOR_SPMU_RETURN), except for legacy records without a
         * LaundryJob.
         */
        $custody->loadMissing('lines.requestItem.inventoryItem', 'laundryJob');
        $laundryJob = $custody->relationLoaded('laundryJob') ? $custody->laundryJob : null;
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

            $isLinen = (bool) $line->requestItem?->inventoryItem?->laundry_required;
            $isEligible = ! $isLinen
                || ! $laundryJob
                || $laundryJob->status === 'READY_FOR_SPMU_RETURN';

            $breakdown = $data['accounting'][$lineId] ?? [];
            $entered = is_array($breakdown)
                ? collect($breakdown)->sum(fn ($value) => max(0, (float) $value))
                : 0;

            // Legacy payload support.
            $entered = max($entered, (float) ($data['quantities'][$lineId] ?? 0));

            if (! $isEligible) {
                // Nothing was actually submitted for this linen line: simply
                // leave it under the Laundry subprocess without an error.
                if ($entered <= 0) {
                    unset(
                        $data['accounting'][$lineId],
                        $data['quantities'][$lineId],
                        $data['conditions'][$lineId],
                        $data['police_blotter_references'][$lineId]
                    );

                    continue;
                }

                $description = $line->requestItem?->description_snapshot ?: 'Linen item';

                throw ValidationException::withMessages([
                    'return' => $description
                        .': this linen is still in the required Laundry subprocess and is not yet ready for SPMU final inspection.',
                ]);
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
            $request->boolean('early_return'),
            $data['police_blotter_references'] ?? [],
            $evidenceFileIds,
            conditionBreakdowns: $data['accounting'] ?? [],
        );

        return redirect()
            ->to(route('custody.return.show', $custody).'#return-primary')
            ->with('status', 'Return inspection recorded. The remaining return or Laundry status is shown beside the inspection panel.');
    }

    public function requestEarlyReturn(Request $request, CustodyTransaction $custody, CustodyService $service): RedirectResponse
    {
        $data = $request->validate([
            'proposed_return_at' => ['required', 'date', 'after_or_equal:now'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->requestEarlyReturn($custody, $request->user(), $data['quantities'], $data['proposed_return_at'], $data['reason'] ?? null);

        return back()->with('status', 'Early Return notice sent to SPMU. Inventory will change only after physical inspection.');
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
