<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\NotificationDelivery;
use App\Models\TemporaryDelegation;
use App\Models\User;
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

        if ($workspace === 'BORROWER') {
            $dashboardMode = 'BORROWER';

            $openRequests = BorrowingRequest::query()
                ->where('borrower_user_id', $user->id)
                ->whereNotIn('status', [
                    RequestStatus::Cancelled,
                    RequestStatus::Rejected,
                    RequestStatus::Expired,
                ])
                ->whereDoesntHave('custody', fn ($query) => $query->whereNotNull('released_at'))
                ->count();

            $activeBorrowings = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->where('status', '!=', 'CLOSED')
                ->whereNotNull('released_at')
                ->count();

            $dueForReturn = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->where('status', '!=', 'CLOSED')
                ->whereNotNull('released_at')
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now()->addDay()->endOfDay())
                ->count();

            $requestActions = BorrowingRequest::query()
                ->where('borrower_user_id', $user->id)
                ->whereIn('status', [RequestStatus::Draft, RequestStatus::ReturnedForRevision])
                ->count();

            $pickupActions = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->whereNull('released_at')
                ->whereNotNull('scheduled_release_at')
                ->whereNull('pickup_expired_at')
                ->count();

            $laundryActions = LaundryJob::query()
                ->whereHas('custody', fn ($query) => $query->where('borrower_user_id', $user->id))
                ->where('status', 'FOR_LAUNDRY')
                ->count();

            $statistics = [
                'Open Requests' => $openRequests,
                'Active Borrowings' => $activeBorrowings,
                'Due for Return' => $dueForReturn,
                'Needs My Action' => $requestActions + $pickupActions + $laundryActions,
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
                ->with(['currentVersion', 'custody.laundryJob'])
                ->where('borrower_user_id', $user->id)
                ->where($liveBorrowerRequest)
                ->get()
                ->filter(function (BorrowingRequest $record): bool {
                    $custody = $record->custody;
                    $laundry = $custody?->laundryJob;

                    return in_array($record->status, [RequestStatus::Draft, RequestStatus::ReturnedForRevision], true)
                        || $laundry?->status === 'FOR_LAUNDRY'
                        || (
                            $custody?->scheduled_release_at !== null
                            && $custody?->released_at === null
                            && $custody?->pickup_expired_at === null
                        );
                })
                ->sort(function (BorrowingRequest $left, BorrowingRequest $right): int {
                    $priority = static function (BorrowingRequest $record): int {
                        $custody = $record->custody;
                        $laundry = $custody?->laundryJob;

                        return match (true) {
                            $record->status === RequestStatus::ReturnedForRevision => 1,
                            $record->status === RequestStatus::Draft => 2,
                            $laundry?->status === 'FOR_LAUNDRY' => 3,
                            $custody?->scheduled_release_at !== null && $custody?->released_at === null => 4,
                            default => 5,
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

                return match (true) {
                    in_array($custodyStatus, ['OVERDUE', 'OBLIGATION_OPEN', 'INCIDENT_OPEN'], true) => 1,
                    in_array($custodyStatus, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED'], true) => 2,
                    $custody?->released_at !== null => 3,
                    $record->status === RequestStatus::ReturnedForRevision => 4,
                    $custody?->scheduled_release_at !== null && $custody?->released_at === null => 5,
                    $custodyStatus === 'PREPARING_RELEASE'
                        || in_array($record->status, [RequestStatus::FinalApprovedAwaitingDownload, RequestStatus::ApprovedReadyForRelease], true) => 6,
                    $record->status === RequestStatus::UnderSpmu => 7,
                    in_array($record->status, [RequestStatus::Submitted, RequestStatus::Signed], true) => 8,
                    $record->status === RequestStatus::Draft => 9,
                    default => 10,
                };
            };

            $activeBorrowerRequests = BorrowingRequest::query()
                ->with(['currentVersion', 'custody.laundryJob'])
                ->where('borrower_user_id', $user->id)
                ->where($liveBorrowerRequest)
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

            $forPickupScheduling = CustodyTransaction::query()
                ->where('status', 'PREPARING_RELEASE')
                ->whereNull('scheduled_release_at')
                ->count();

            $readyForRelease = CustodyTransaction::query()
                ->where('status', 'PREPARING_RELEASE')
                ->whereNotNull('scheduled_release_at')
                ->whereNull('pickup_expired_at')
                ->count();

            $forReturnCheck = CustodyTransaction::query()
                ->whereIn('status', ['ACTIVE', 'RETURN_PROCESSING', 'OVERDUE'])
                ->count();

            $laundryOperations = LaundryJob::query()
                ->where('status', '!=', 'LAUNDRY_COMPLETED')
                ->count();

            $statistics = [
                'For Pickup Scheduling' => $forPickupScheduling,
                'Ready for Release' => $readyForRelease,
                'For Return Check' => $forReturnCheck,
                'Laundry Operations' => $laundryOperations,
            ];

            $queue = CustodyTransaction::query()
                ->with(['borrower', 'request.currentVersion'])
                ->where('status', 'PREPARING_RELEASE')
                ->orderByRaw('CASE WHEN scheduled_release_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('scheduled_release_at')
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
                    ->where('sequence_no', 1)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']))
                ->count();

            $approvedToday = BorrowingRequest::query()
                ->whereDate('final_approved_at', today())
                ->count();

            $activeBorrowings = CustodyTransaction::query()
                ->whereNotIn('status', ['CLOSED', 'PREPARING_RELEASE'])
                ->count();

            $issues = CustodyTransaction::query()
                ->whereIn('status', ['OVERDUE', 'INCIDENT_OPEN', 'OBLIGATION_OPEN', 'RETURN_PROCESSING'])
                ->count();

            $statistics = [
                'For Approval' => $forApproval,
                'Approved Today' => $approvedToday,
                'Active Borrowings' => $activeBorrowings,
                'Overdue / Issues' => $issues,
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
            'activeRequestTotal'
        ));
    }
}
