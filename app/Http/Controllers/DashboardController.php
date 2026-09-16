<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\Incident;
use App\Models\LaundryJob;
use App\Models\NotificationDelivery;
use App\Models\OverdueCase;
use App\Models\TemporaryDelegation;
use App\Models\User;
use App\Services\BorrowerObligationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user()->load('roles');
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        $allowed = $user->allowedWorkspaces();

        if (! in_array($workspace, $allowed, true)) {
            $workspace = $user->primaryWorkspace() ?? $allowed[0] ?? null;
            abort_unless($workspace, 403, 'No system role is assigned to this account.');
            $request->session()->put('active_workspace', $workspace);
        }

        $classification = $user->access_classification;
        $statistics = [];
        $queue = collect();
        $nextCustodies = collect();
        $activeRequestBars = collect();
        $activeRequestTotal = 0;
        $dashboardMode = $workspace;
        $borrowerObligationOverview = null;

        if ($workspace === 'BORROWER') {
            $dashboardMode = 'BORROWER';

            $activeBorrowings = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
                ->whereNotNull('released_at')
                ->whereHas('lines', fn ($line) => $line
                    ->whereColumn('returned_quantity', '<', 'actual_released_quantity'))
                ->count();

            $upcomingPickup = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->whereNull('released_at')
                ->whereNotNull('scheduled_release_at')
                ->whereNull('pickup_expired_at')
                ->where(function ($query) {
                    $query->whereNull('pickup_expires_at')
                        ->orWhere('pickup_expires_at', '>=', now());
                })
                ->count();

            $returnsDue = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->whereNotIn('status', ['CLOSED', 'CANCELLED', 'OVERDUE'])
                ->whereNotNull('released_at')
                ->whereNotNull('due_at')
                ->whereHas('lines', fn ($line) => $line
                    ->whereColumn('returned_quantity', '<', 'actual_released_quantity'))
                ->whereBetween('due_at', [now()->startOfDay(), now()->addDay()->endOfDay()])
                ->count();

            // Grouped obligation count: one incident/late-return + its linked
            // billing/restriction is one obligation, and a standalone
            // administrative restriction is recognized even without an
            // active CustodyTransaction. This is the same grouping
            // My Obligations uses, from the one shared implementation, so
            // the two screens never disagree.
            $borrowerObligationOverview = app(BorrowerObligationService::class)->overview($user->id);
            $activeObligations = $borrowerObligationOverview['count'];

            $statistics = [
                'Active Borrowings' => $activeBorrowings,
                'Upcoming Pickup' => $upcomingPickup,
                'Returns Due' => $returnsDue,
                'Active Obligations' => $activeObligations,
            ];

            $liveBorrowerRequest = static fn ($query) => $query
                ->whereNotIn('status', [
                    RequestStatus::Cancelled->value,
                    RequestStatus::Rejected->value,
                    RequestStatus::Expired->value,
                ])
                ->where(function ($query) {
                    $query->whereDoesntHave('custody')
                        ->orWhereHas('custody', fn ($custody) => $custody->where('status', '!=', 'CLOSED'));
                });

            // Only records that require an actual borrower action belong in
            // the dashboard action queue. Passive monitoring (under review,
            // released/on-custody, laundry in process, etc.) remains visible in
            // Active Requests without being duplicated under "Needs My Action".
            $queue = BorrowingRequest::query()
                ->with(['currentVersion', 'custody.laundryJob', 'custody.lines.requestItem.inventoryItem'])
                ->where('borrower_user_id', $user->id)
                ->where($liveBorrowerRequest)
                ->get()
                ->filter(function (BorrowingRequest $record): bool {
                    $custody = $record->custody;
                    $laundry = $custody?->laundryJob;

                    return in_array($record->status, [RequestStatus::Draft, RequestStatus::ReturnedForRevision], true)
                        || in_array((string) $custody?->status, ['OVERDUE', 'INCIDENT_OPEN', 'OBLIGATION_OPEN'], true)
                        || ($laundry?->status === 'FOR_LAUNDRY' && ! $laundry->hasVerifiedAccomplishedForm())
                        || (
                            $custody?->scheduled_release_at !== null
                            && $custody?->released_at === null
                        );
                })
                ->sort(function (BorrowingRequest $left, BorrowingRequest $right): int {
                    $priority = static function (BorrowingRequest $record): int {
                        $custody = $record->custody;
                        $laundry = $custody?->laundryJob;

                        return match (true) {
                            $record->status === RequestStatus::ReturnedForRevision => 1,
                            in_array((string) $custody?->status, ['OVERDUE', 'INCIDENT_OPEN', 'OBLIGATION_OPEN'], true) => 2,
                            $custody?->released_at === null && (
                                $custody?->pickup_expired_at !== null
                                || ($custody?->pickup_expires_at !== null && now()->gt($custody->pickup_expires_at))
                            ) => 3,
                            $record->status === RequestStatus::Draft => 4,
                            $laundry?->status === 'FOR_LAUNDRY' => 5,
                            $custody?->scheduled_release_at !== null && $custody?->released_at === null => 6,
                            default => 7,
                        };
                    };

                    $priorityComparison = $priority($left) <=> $priority($right);

                    if ($priorityComparison !== 0) {
                        return $priorityComparison;
                    }

                    return ($right->updated_at?->getTimestamp() ?? 0) <=> ($left->updated_at?->getTimestamp() ?? 0);
                })
                ->take(6)
                ->values();

            // Dashboard overview: show several ongoing requests instead of
            // one "latest request" tracker. Urgent custody/return states are
            // intentionally ranked ahead of newer drafts so an older active
            // borrowing cannot be hidden by a recently created request.
            $borrowerRequestPriority = static function (BorrowingRequest $record): int {
                $custody = $record->custody;
                $custodyStatus = strtoupper((string) ($custody?->status ?? ''));
                $pickupMissed = $custody?->released_at === null
                    && (
                        $custody?->pickup_expired_at !== null
                        || ($custody?->pickup_expires_at !== null && now()->gt($custody->pickup_expires_at))
                    );

                return match (true) {
                    in_array($custodyStatus, ['OVERDUE', 'OBLIGATION_OPEN', 'INCIDENT_OPEN'], true) => 1,
                    $pickupMissed => 2,
                    in_array($custodyStatus, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED'], true) => 3,
                    $custody?->released_at !== null => 4,
                    $record->status === RequestStatus::ReturnedForRevision => 5,
                    $custody?->scheduled_release_at !== null && $custody?->released_at === null => 6,
                    $custodyStatus === 'PREPARING_RELEASE'
                        || in_array($record->status, [RequestStatus::FinalApprovedAwaitingDownload, RequestStatus::ApprovedReadyForRelease], true) => 7,
                    $record->status === RequestStatus::UnderSpmu => 8,
                    in_array($record->status, [RequestStatus::Submitted, RequestStatus::Signed], true) => 9,
                    $record->status === RequestStatus::Draft => 10,
                    default => 11,
                };
            };

            // Keep the monitoring list separate from the action queue. A request
            // that already appears under "Actions Requiring Your Attention" must
            // not be duplicated again under Current Borrowing Requests.
            $actionRequestIds = $queue->pluck('id')->filter()->values()->all();

            $activeBorrowerRequestsQuery = BorrowingRequest::query()
                ->with(['currentVersion', 'custody.laundryJob', 'custody.lines.requestItem.inventoryItem'])
                ->where('borrower_user_id', $user->id)
                ->where($liveBorrowerRequest);

            if ($actionRequestIds !== []) {
                $activeBorrowerRequestsQuery->whereNotIn('id', $actionRequestIds);
            }

            $activeBorrowerRequests = $activeBorrowerRequestsQuery
                ->get()
                ->sort(function (BorrowingRequest $left, BorrowingRequest $right) use ($borrowerRequestPriority): int {
                    $priorityComparison = $borrowerRequestPriority($left) <=> $borrowerRequestPriority($right);

                    if ($priorityComparison !== 0) {
                        return $priorityComparison;
                    }

                    return ($right->updated_at?->getTimestamp() ?? 0) <=> ($left->updated_at?->getTimestamp() ?? 0);
                })
                ->values();

            $activeRequestTotal = $activeBorrowerRequests->count();
            $activeRequestBars = $activeBorrowerRequests->take(5)->values();

            // Pickup and return dates are already shown contextually in the
            // Active Requests rows, so the borrower dashboard no longer performs
            // a second schedule query that would duplicate the same records.
            $nextCustodies = collect();
        } elseif ($workspace === 'SPMU' && $classification === AccessClassification::SpmuOfficer) {
            $dashboardMode = 'SPMU_OFFICER';

            $forVerification = BorrowingRequest::query()
                ->where('status', RequestStatus::UnderSpmu)
                ->whereHas('currentVersion.approvalSteps', fn ($step) => $step
                    ->where('stage_code', 'SPMU')
                    ->where('sequence_no', 1)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']))
                ->count();

            $forRelease = CustodyTransaction::query()
                ->where('status', 'PREPARING_RELEASE')
                ->whereNull('released_at')
                ->count();

            $forReturn = CustodyTransaction::query()
                ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
                ->whereNotNull('released_at')
                ->whereHas('lines', fn ($line) => $line
                    ->whereColumn('returned_quantity', '<', 'actual_released_quantity'))
                ->count();

            $accountabilityCases = $this->openAccountabilityCaseCount();

            $statistics = [
                'For Verification' => $forVerification,
                'For Release' => $forRelease,
                'For Return' => $forReturn,
                'Accountability Cases' => $accountabilityCases,
            ];

            $queue = BorrowingRequest::query()
                ->with(['borrower', 'currentVersion'])
                ->where('status', RequestStatus::UnderSpmu)
                ->whereHas('currentVersion.approvalSteps', fn ($step) => $step
                    ->where('stage_code', 'SPMU')
                    ->where('sequence_no', 1)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']))
                ->oldest()
                ->limit(6)
                ->get();

            $nextCustodies = CustodyTransaction::query()
                ->with(['borrower', 'request'])
                ->whereNotIn('status', ['CLOSED'])
                ->orderBy('due_at')
                ->limit(5)
                ->get();
        } elseif ($workspace === 'SPMU' && $classification === AccessClassification::SpmuHead) {
            $dashboardMode = 'SPMU_HEAD';

            $forApproval = BorrowingRequest::query()
                ->where('status', RequestStatus::UnderSpmu)
                ->whereHas('currentVersion.approvalSteps', fn ($step) => $step
                    ->where('stage_code', 'SPMU')
                    ->where('sequence_no', 2)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']))
                ->count();

            $activeCustodies = CustodyTransaction::query()
                ->whereNotNull('released_at')
                ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
                ->count();

            $openAccountabilityCases = $this->openAccountabilityCaseCount();

            $activeRestrictions = BorrowerRestriction::query()
                ->where('status', 'ACTIVE')
                ->where('effective_from', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>', now());
                })
                ->count();

            $statistics = [
                'For Approval' => $forApproval,
                'Active Custodies' => $activeCustodies,
                'Open Accountability Cases' => $openAccountabilityCases,
                'Active Restrictions' => $activeRestrictions,
            ];

            $queue = BorrowingRequest::query()
                ->with(['borrower', 'currentVersion'])
                ->where('status', RequestStatus::UnderSpmu)
                ->whereHas('currentVersion.approvalSteps', fn ($step) => $step
                    ->where('stage_code', 'SPMU')
                    ->where('sequence_no', 2)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']))
                ->oldest()
                ->limit(6)
                ->get();

            $nextCustodies = CustodyTransaction::query()
                ->with(['borrower', 'request'])
                ->whereNotIn('status', ['CLOSED'])
                ->orderBy('due_at')
                ->limit(5)
                ->get();
        } elseif ($workspace === 'ICTU') {
            $dashboardMode = 'ICTU';

            $statistics = [
                'Active Accounts' => User::query()->where('account_status', 'ACTIVE')->count(),
                'Failed Notifications' => NotificationDelivery::query()->where('delivery_status', 'FAILED')->count(),
                'Active Delegations' => TemporaryDelegation::query()
                    ->where('status', 'ACTIVE')
                    ->whereNull('revoked_at')
                    ->where('effective_from', '<=', now())
                    ->where('effective_to', '>=', now())
                    ->count(),
                'Inactive Accounts' => User::query()->where('account_status', '!=', 'ACTIVE')->count(),
            ];

            $queue = User::query()
                ->with('organizationalUnit')
                ->latest()
                ->limit(6)
                ->get();
        }

        return view('dashboard', compact(
            'statistics',
            'user',
            'workspace',
            'dashboardMode',
            'queue',
            'nextCustodies',
            'activeRequestBars',
            'activeRequestTotal',
            'borrowerObligationOverview'
        ));
    }

    /**
     * Count open accountability matters exactly the way the Accountability
     * workspace presents them: one property incident/late-return matter per
     * underlying custody, plus genuinely standalone open billings.
     */
    private function openAccountabilityCaseCount(): int
    {
        $openIncidents = Incident::query()
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->get(['id', 'custody_transaction_id']);

        $openOverdues = OverdueCase::query()
            ->where('status', '!=', 'RESOLVED')
            ->get(['id', 'custody_transaction_id']);

        $incidentCustodyIds = $openIncidents
            ->pluck('custody_transaction_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();

        $openCaseCount = $openIncidents->count()
            + $openOverdues->reject(
                fn (OverdueCase $case): bool => $case->custody_transaction_id !== null
                    && $incidentCustodyIds->contains((int) $case->custody_transaction_id)
            )->count();

        $openIncidentIds = $openIncidents->pluck('id')->map(fn ($id): int => (int) $id);
        $openOverdueIds = $openOverdues->pluck('id')->map(fn ($id): int => (int) $id);

        $standaloneBillingCount = BillingStatement::query()
            ->with(['lines.penalty:id,overdue_case_id'])
            ->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID'])
            ->get()
            ->reject(function (BillingStatement $billing) use ($openIncidentIds, $openOverdueIds): bool {
                return $billing->lines->contains(function ($line) use ($openIncidentIds, $openOverdueIds): bool {
                    $incidentId = (int) ($line->incident_id ?? 0);
                    $overdueId = (int) ($line->penalty?->overdue_case_id ?? 0);

                    return ($incidentId > 0 && $openIncidentIds->contains($incidentId))
                        || ($overdueId > 0 && $openOverdueIds->contains($overdueId));
                });
            })
            ->count();

        return $openCaseCount + $standaloneBillingCount;
    }
}
