<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Services\OperationalCalendarService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CalendarController extends Controller
{
    private const AUTHORITY_CUSTODY_STATUSES = [
        'PREPARING_RELEASE',
        'ACTIVE',
        'RETURN_PROCESSING',
        'OVERDUE',
        'INCIDENT_OPEN',
        'OBLIGATION_OPEN',
        'CLOSED',
    ];

    public function index(Request $request, OperationalCalendarService $operationalCalendar): View
    {
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        $isBorrower = $workspace === 'BORROWER';
        $classification = $request->user()->access_classification;
        $isSpmuHead = $classification === AccessClassification::SpmuHead;
        $isSpmuOfficer = $classification === AccessClassification::SpmuOfficer;
        $month = $this->selectedMonth($request);
        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth();
        $gridStart = $monthStart->startOfWeek(CarbonInterface::SUNDAY);
        $gridEnd = $monthEnd->endOfWeek(CarbonInterface::SATURDAY);
        $dueSoonDays = 1;

        $allocations = Allocation::query()
            ->with([
                'requestItem.version.request.accountableUnit',
                'requestItem.version.request.currentVersion.approvalSteps',
            ])
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_RELEASED'])
            ->where('period_start', '<=', $monthEnd->endOfDay())
            ->where('period_end', '>=', $monthStart->startOfDay())
            ->when($isBorrower, function ($query) use ($request): void {
                $query->whereHas('requestItem.version.request', function ($requests) use ($request): void {
                    $requests->where('borrower_user_id', $request->user()->id);
                });
            })
            ->orderBy('period_start')
            ->get();

        $custodies = CustodyTransaction::query()
            ->with([
                'request.accountableUnit',
                'request.currentVersion.approvalSteps',
                'lines.requestItem',
                'overdueCase',
            ])
            ->whereIn('status', self::AUTHORITY_CUSTODY_STATUSES)
            ->where(function ($query) use ($monthStart, $monthEnd): void {
                $query->where(function ($range) use ($monthStart, $monthEnd): void {
                    $range->where('scheduled_release_at', '<=', $monthEnd->endOfDay())
                        ->where('due_at', '>=', $monthStart->startOfDay());
                })->orWhere(function ($closed) use ($monthStart, $monthEnd): void {
                    $closed->where('status', 'CLOSED')
                        ->whereBetween('closed_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()]);
                });
            })
            ->when($isBorrower, function ($query) use ($request): void {
                $query->where('borrower_user_id', $request->user()->id);
            })
            ->orderBy('scheduled_release_at')
            ->get();

        $events = collect();
        foreach ($allocations->groupBy(fn (Allocation $allocation) => $allocation->requestItem->version->request_id) as $requestId => $requestAllocations) {
            $event = $this->allocationEvent($requestAllocations, $workspace, $request->user()->id, $dueSoonDays, $operationalCalendar);
            if ($event) {
                $events->put((int) $requestId, $event);
            }
        }
        foreach ($custodies as $custody) {
            $events->put($custody->request_id, $this->custodyEvent($custody, $workspace, $request->user()->id, $dueSoonDays, $operationalCalendar));
        }

        $calendarEvents = $events->sortBy('start_at')->values();
        $occurrencesByDate = $this->occurrencesByDate($calendarEvents, $monthStart, $monthEnd, $gridStart, $gridEnd);
        $operationalProfiles = $operationalCalendar->profilesForRange($gridStart, $gridEnd);
        $calendarWeeks = $this->calendarWeeks($gridStart, $gridEnd, $month, $occurrencesByDate, $operationalProfiles);
        $summaryEvents = $calendarEvents;

        [$calendarTitle, $calendarEyebrow, $calendarDescription] = match (true) {
            $isBorrower => [
                'Borrowing Calendar',
                'My schedule',
                'Your requests, pickup/return dates, and SPMU operating days. Other borrowers’ request details are not shown.',
            ],
            $isSpmuHead => [
                'Borrowing & Operations Calendar',
                'Institutional schedule',
                'Institution-wide borrowing activity with the weekly transaction schedule and special-date overrides applied automatically.',
            ],
            $isSpmuOfficer => [
                'Operations Calendar',
                'Operational schedule',
                'Approved and released borrowings, pickup/return activity, and SPMU operating-day availability in one calendar.',
            ],
            default => [
                'Borrowing Calendar',
                'Schedule overview',
                'Borrowing and operational schedule overview.',
            ],
        };
        $summary = [
            'active' => $summaryEvents->where('is_active', true)->count(),
            'due_soon' => $summaryEvents->where('is_due_soon', true)->count(),
            'overdue' => $summaryEvents->where('is_overdue', true)->count(),
            'returned' => $summaryEvents->where('is_closed', true)->count(),
        ];

        return view('calendar.index', [
            'workspace' => $workspace,
            'month' => $month,
            'previousMonth' => $month->subMonth(),
            'nextMonth' => $month->addMonth(),
            'calendarWeeks' => $calendarWeeks,
            'calendarEvents' => $calendarEvents,
            'summary' => $summary,
            'calendarTitle' => $calendarTitle,
            'calendarEyebrow' => $calendarEyebrow,
            'calendarDescription' => $calendarDescription,
            'isSpmuHead' => $isSpmuHead,
            'isSpmuOfficer' => $isSpmuOfficer,
        ]);
    }

    private function selectedMonth(Request $request): CarbonImmutable
    {
        $timezone = config('app.timezone');
        $requested = trim((string) $request->query('month'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)) {
            return CarbonImmutable::now($timezone)->startOfMonth();
        }

        return CarbonImmutable::createFromFormat('!Y-m', $requested, $timezone)->startOfMonth();
    }

    /** @param Collection<int, Allocation> $allocations */
    private function allocationEvent(Collection $allocations, string $workspace, int $userId, int $dueSoonDays, OperationalCalendarService $operationalCalendar): ?array
    {
        $first = $allocations->first();
        $request = $first?->requestItem?->version?->request;
        $version = $request?->currentVersion ?: $first?->requestItem?->version;
        if (! $request || ! $version) {
            return null;
        }

        $own = $request->borrower_user_id === $userId;
        $detailsVisible = $workspace !== 'BORROWER' || $own;
        $items = $detailsVisible
            ? $allocations->map(fn (Allocation $allocation) => [
                'name' => $allocation->requestItem->description_snapshot,
                'quantity' => $this->quantity($allocation->allocated_quantity),
                'unit' => $allocation->requestItem->unit_snapshot,
                'quantity_label' => 'Approved quantity',
            ])->values()
            : collect();

        $originalDueAt = CarbonImmutable::instance($allocations->sortByDesc('period_end')->first()->period_end)->endOfDay();
        $effectiveDueAt = $operationalCalendar->effectiveReturnDeadline($originalDueAt);
        $adjustmentReason = $originalDueAt->isSameDay($effectiveDueAt)
            ? null
            : ($operationalCalendar->profile($originalDueAt)['reason'] ?: 'The requested return date is not an open SPMU return day.');

        return $this->baseEvent(
            request: $request,
            workspace: $workspace,
            userId: $userId,
            startAt: CarbonImmutable::instance($allocations->sortBy('period_start')->first()->period_start),
            startPhaseLabel: 'Approved use begins',
            dueAt: $effectiveDueAt,
            originalDueAt: $originalDueAt,
            dueAdjustmentReason: $adjustmentReason,
            status: $detailsVisible ? $request->status->value : 'APPROVED',
            reference: $detailsVisible ? $request->request_no : 'Reserved institutional use',
            itemCount: $allocations->count(),
            items: $items,
            own: $own,
            detailsVisible: $detailsVisible,
            custody: null,
            dueSoonDays: $dueSoonDays,
        );
    }

    private function custodyEvent(CustodyTransaction $custody, string $workspace, int $userId, int $dueSoonDays, OperationalCalendarService $operationalCalendar): array
    {
        $request = $custody->request;
        $version = $request->currentVersion;
        $own = $custody->borrower_user_id === $userId;
        $detailsVisible = $workspace !== 'BORROWER' || $own;
        $released = $custody->released_at !== null;
        $items = $detailsVisible
            ? $custody->lines->map(fn ($line) => [
                'name' => $line->requestItem->description_snapshot,
                'quantity' => $this->quantity($released ? $line->actual_released_quantity : $line->approved_quantity),
                'unit' => $line->requestItem->unit_snapshot,
                'quantity_label' => $released ? 'Issued quantity' : 'Approved quantity',
            ])->values()
            : collect();

        $storedDueAt = CarbonImmutable::instance($custody->due_at);
        $originalDueAt = CarbonImmutable::instance($custody->original_due_at ?: $custody->due_at)->endOfDay();
        $effectiveDueAt = $custody->status === 'CLOSED'
            ? $storedDueAt
            : $operationalCalendar->effectiveReturnDeadline($originalDueAt);
        $adjustmentReason = $originalDueAt->isSameDay($effectiveDueAt)
            ? null
            : ($custody->due_adjustment_reason ?: $operationalCalendar->profile($originalDueAt)['reason'] ?: 'The original return date is not an open SPMU return day.');

        return $this->baseEvent(
            request: $request,
            workspace: $workspace,
            userId: $userId,
            startAt: CarbonImmutable::instance($custody->released_at ?: $custody->scheduled_release_at ?: $version->needed_from),
            startPhaseLabel: $custody->released_at ? 'Items released' : 'Pickup / Release',
            dueAt: $effectiveDueAt,
            originalDueAt: $originalDueAt,
            dueAdjustmentReason: $adjustmentReason,
            status: $custody->status,
            reference: $detailsVisible ? $request->request_no : 'Active institutional use',
            itemCount: $custody->lines->count(),
            items: $items,
            own: $own,
            detailsVisible: $detailsVisible,
            custody: $custody,
            dueSoonDays: $dueSoonDays,
        );
    }

    private function baseEvent(
        BorrowingRequest $request,
        string $workspace,
        int $userId,
        CarbonImmutable $startAt,
        string $startPhaseLabel,
        CarbonImmutable $dueAt,
        CarbonImmutable $originalDueAt,
        ?string $dueAdjustmentReason,
        string $status,
        string $reference,
        int $itemCount,
        Collection $items,
        bool $own,
        bool $detailsVisible,
        ?CustodyTransaction $custody,
        int $dueSoonDays,
    ): array {
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();
        $dueDate = $dueAt->startOfDay();
        $isDueSoon = $custody
            && in_array($custody->status, ['ACTIVE', 'RETURN_PROCESSING'], true)
            && $dueDate->betweenIncluded($today, $today->addDays($dueSoonDays));
        $canViewRequest = $this->canViewRequest($request, $workspace, $userId);

        return [
            'key' => 'request-'.$request->id,
            'request_id' => $request->id,
            'reference' => $reference,
            'status' => $status,
            'start_at' => $startAt,
            'start_phase_label' => $startPhaseLabel,
            'due_at' => $dueAt,
            'original_due_at' => $originalDueAt,
            'return_adjusted' => ! $originalDueAt->isSameDay($dueAt),
            'due_adjustment_reason' => $dueAdjustmentReason,
            'closed_at' => $custody?->closed_at ? CarbonImmutable::instance($custody->closed_at) : null,
            'purpose' => $detailsVisible ? $request->currentVersion?->purpose_event : null,
            'office' => $detailsVisible ? $request->accountableUnit?->unit_name : null,
            'item_count' => $itemCount,
            'items' => $items,
            'own_record' => $own,
            'details_visible' => $detailsVisible,
            'request_url' => $canViewRequest ? route('requests.show', $request) : null,
            'action_label' => $workspace === 'BORROWER' ? 'View My Request' : 'View Request',
            'next_action' => $this->nextAction($status, $own, $detailsVisible, $isDueSoon, $dueAt),
            'is_active' => $custody && in_array($custody->status, ['ACTIVE', 'RETURN_PROCESSING', 'INCIDENT_OPEN'], true),
            'is_due_soon' => $isDueSoon,
            'is_overdue' => $custody?->status === 'OVERDUE',
            'is_closed' => $custody?->status === 'CLOSED',
        ];
    }

    private function canViewRequest(BorrowingRequest $request, string $workspace, int $userId): bool
    {
        return match ($workspace) {
            'BORROWER' => $request->borrower_user_id === $userId,
            'SPMU' => true,
            default => false,
        };
    }

    private function nextAction(string $status, bool $own, bool $detailsVisible, bool $isDueSoon, CarbonImmutable $dueAt): string
    {
        if (! $detailsVisible) {
            return 'This approved period may affect item availability.';
        }
        if ($status === 'OVERDUE') {
            return $own ? 'Action required — coordinate the overdue return with SPMU.' : 'The borrowing is recorded as overdue.';
        }
        if ($status === 'CLOSED') {
            return 'The physical return and any required closeout are complete.';
        }
        if ($status === 'OBLIGATION_OPEN') {
            return $own ? 'Action required — resolve the outstanding accountability obligation.' : 'The return is complete, with an outstanding obligation under review.';
        }
        if ($isDueSoon) {
            return 'Return due '.$dueAt->format('d F Y').'.';
        }

        return match ($status) {
            RequestStatus::ReturnedForRevision->value => 'Action required — review the remarks and revise this request.',
            RequestStatus::FinalApprovedAwaitingDownload->value => 'Action required — download the approved letter before the deadline.',
            RequestStatus::ApprovedReadyForRelease->value, 'PREPARING_RELEASE' => 'Ready for release processing with SPMU.',
            RequestStatus::UnderSpmu->value => 'No action required — waiting for SPMU review.',
            RequestStatus::UnderGsu->value,
            RequestStatus::UnderVpaf->value => 'Legacy request — borrower resubmission is required under the current SPMU-only workflow.',
            'ACTIVE', 'RETURN_PROCESSING', 'INCIDENT_OPEN' => 'Return due '.$dueAt->format('d F Y').'.',
            default => 'Review the request record for its latest status and instructions.',
        };
    }

    /** @param Collection<int, array<string, mixed>> $events */
    private function occurrencesByDate(Collection $events, CarbonImmutable $monthStart, CarbonImmutable $monthEnd, CarbonImmutable $gridStart, CarbonImmutable $gridEnd): Collection
    {
        $occurrences = collect();
        foreach ($events as $event) {
            $markers = collect([
                ['date' => $event['start_at'], 'kind' => 'start'],
                ['date' => $event['due_at'], 'kind' => 'due'],
            ]);
            if ($event['return_adjusted']) {
                $markers->push(['date' => $event['original_due_at'], 'kind' => 'adjusted_due']);
            }
            if ($event['closed_at']) {
                $markers->push(['date' => $event['closed_at'], 'kind' => 'returned']);
            }
            if ($event['start_at']->lt($monthStart) && $event['due_at']->gt($monthEnd)) {
                $markers->push(['date' => $monthStart, 'kind' => 'ongoing']);
            }

            foreach ($markers as $marker) {
                $date = $marker['date']->toDateString();
                if ($marker['date']->lt($gridStart) || $marker['date']->gt($gridEnd)) {
                    continue;
                }
                $dayEvents = $occurrences->get($date, collect());
                $existing = $dayEvents->get($event['key']);
                $kind = $this->combinedOccurrenceKind($existing['kind'] ?? null, $marker['kind']);
                $dayEvents->put($event['key'], [
                    'event' => $event,
                    'kind' => $kind,
                    'phase_label' => $this->phaseLabel($kind, $event),
                ]);
                $occurrences->put($date, $dayEvents);
            }
        }

        return $occurrences->map(fn (Collection $dayEvents) => $dayEvents->sortBy(fn ($occurrence) => [$occurrence['event']['start_at']->timestamp, $occurrence['event']['reference']])->values());
    }

    private function combinedOccurrenceKind(?string $existing, string $incoming): string
    {
        if (! $existing || $existing === $incoming) {
            return $incoming;
        }
        if ($existing === 'returned' || $incoming === 'returned') {
            return 'returned';
        }
        if (in_array('start', [$existing, $incoming], true) && in_array('due', [$existing, $incoming], true)) {
            return 'start_due';
        }

        return $incoming;
    }

    /** @param array<string, mixed> $event */
    private function phaseLabel(string $kind, array $event): string
    {
        return match ($kind) {
            'start' => (string) ($event['start_phase_label'] ?? 'Pickup / Release'),
            'due' => $event['return_adjusted'] ? 'Adjusted return' : 'Return due',
            'start_due' => $event['return_adjusted'] ? 'Release / adjusted return' : 'Release / return due',
            'returned' => 'Returned',
            'adjusted_due' => 'Original return date',
            default => 'Ongoing borrowing',
        };
    }

    private function calendarWeeks(
        CarbonImmutable $gridStart,
        CarbonImmutable $gridEnd,
        CarbonImmutable $month,
        Collection $occurrences,
        Collection $operationalProfiles
    ): Collection {
        $weeks = collect();
        $cursor = $gridStart;
        $today = CarbonImmutable::now(config('app.timezone'));
        while ($cursor->lte($gridEnd)) {
            $week = collect();
            for ($day = 0; $day < 7; $day++) {
                $date = $cursor;
                $week->push([
                    'date' => $date,
                    'in_month' => $date->month === $month->month && $date->year === $month->year,
                    'is_today' => $date->isSameDay($today),
                    'occurrences' => $occurrences->get($date->toDateString(), collect()),
                    'operational' => $this->operationalPresentation(
                        $operationalProfiles->get($date->toDateString(), [])
                    ),
                ]);
                $cursor = $cursor->addDay();
            }
            $weeks->push($week);
        }

        return $weeks;
    }

    /** @param array<string, mixed> $profile */
    private function operationalPresentation(array $profile): array
    {
        $isOpen = (bool) ($profile['is_open'] ?? false);
        $acceptsRequests = (bool) ($profile['accepts_requests'] ?? false);
        $allowsPickup = (bool) ($profile['allows_pickup'] ?? false);
        $allowsReturn = (bool) ($profile['allows_return'] ?? false);
        $isException = ($profile['source'] ?? null) === 'EXCEPTION';
        $isLimited = $isOpen && ! ($acceptsRequests && $allowsPickup && $allowsReturn);

        $label = match (true) {
            ! $isOpen => 'SPMU Closed',
            $isException => 'Special Open',
            $isLimited => 'Limited Operations',
            default => null,
        };
        $tone = match (true) {
            ! $isOpen => 'closed',
            $isException => 'special',
            $isLimited => 'limited',
            default => 'open',
        };

        $hours = null;
        if (! empty($profile['open_time']) && ! empty($profile['close_time'])) {
            $hours = substr((string) $profile['open_time'], 0, 5).'–'.substr((string) $profile['close_time'], 0, 5);
        }

        $capabilities = collect([
            'Requests' => $acceptsRequests,
            'Pickup / Release' => $allowsPickup,
            'Returns' => $allowsReturn,
        ])->map(fn (bool $allowed, string $name) => $name.': '.($allowed ? 'Open' : 'Closed'))->values()->implode(' · ');

        $details = ! $isOpen
            ? ((string) ($profile['reason'] ?? '') ?: 'No SPMU transactions are accepted on this date.')
            : $capabilities.($hours ? ' · Hours: '.$hours : '').(! empty($profile['reason']) ? ' · '.$profile['reason'] : '');

        return [
            'is_open' => $isOpen,
            'accepts_requests' => $acceptsRequests,
            'allows_pickup' => $allowsPickup,
            'allows_return' => $allowsReturn,
            'is_exception' => $isException,
            'is_limited' => $isLimited,
            'label' => $label,
            'tone' => $tone,
            'details' => $details,
            'reason' => $profile['reason'] ?? null,
            'hours' => $hours,
        ];
    }

    private function quantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');
    }
}
