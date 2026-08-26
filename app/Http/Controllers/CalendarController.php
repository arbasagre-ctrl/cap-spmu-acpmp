<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
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

    public function index(Request $request): View
    {
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        $isBorrower = $workspace === 'BORROWER';
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
                $query->where(function ($privacy) use ($request): void {
                    $privacy->where('borrower_user_id', $request->user()->id)
                        ->orWhereNotIn('status', ['CLOSED', 'OBLIGATION_OPEN']);
                });
            })
            ->orderBy('scheduled_release_at')
            ->get();

        $events = collect();
        foreach ($allocations->groupBy(fn (Allocation $allocation) => $allocation->requestItem->version->request_id) as $requestId => $requestAllocations) {
            $event = $this->allocationEvent($requestAllocations, $workspace, $request->user()->id, $dueSoonDays);
            if ($event) {
                $events->put((int) $requestId, $event);
            }
        }
        foreach ($custodies as $custody) {
            $events->put($custody->request_id, $this->custodyEvent($custody, $workspace, $request->user()->id, $dueSoonDays));
        }

        $calendarEvents = $events->sortBy('start_at')->values();
        $occurrencesByDate = $this->occurrencesByDate($calendarEvents, $monthStart, $monthEnd, $gridStart, $gridEnd);
        $calendarWeeks = $this->calendarWeeks($gridStart, $gridEnd, $month, $occurrencesByDate);
        $summaryEvents = $isBorrower ? $calendarEvents->where('own_record', true) : $calendarEvents;
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
    private function allocationEvent(Collection $allocations, string $workspace, int $userId, int $dueSoonDays): ?array
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

        return $this->baseEvent(
            request: $request,
            workspace: $workspace,
            userId: $userId,
            startAt: CarbonImmutable::instance($allocations->sortBy('period_start')->first()->period_start),
            dueAt: CarbonImmutable::instance($allocations->sortByDesc('period_end')->first()->period_end),
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

    private function custodyEvent(CustodyTransaction $custody, string $workspace, int $userId, int $dueSoonDays): array
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

        return $this->baseEvent(
            request: $request,
            workspace: $workspace,
            userId: $userId,
            startAt: CarbonImmutable::instance($custody->scheduled_release_at ?: $version->needed_from),
            dueAt: CarbonImmutable::instance($custody->due_at),
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
        CarbonImmutable $dueAt,
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
            'due_at' => $dueAt,
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
                    'phase_label' => $this->phaseLabel($kind),
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

    private function phaseLabel(string $kind): string
    {
        return match ($kind) {
            'start' => 'Borrowing starts',
            'due' => 'Return due',
            'start_due' => 'Starts and due',
            'returned' => 'Returned',
            default => 'Ongoing borrowing',
        };
    }

    private function calendarWeeks(CarbonImmutable $gridStart, CarbonImmutable $gridEnd, CarbonImmutable $month, Collection $occurrences): Collection
    {
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
                ]);
                $cursor = $cursor->addDay();
            }
            $weeks->push($week);
        }

        return $weeks;
    }

    private function quantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');
    }
}
