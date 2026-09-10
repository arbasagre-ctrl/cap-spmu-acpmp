<?php

namespace App\Services;

use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\OverdueCase;
use App\Models\OperationalDateException;
use App\Models\OperationalWeeklySchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OperationalCalendarService
{
    public const REQUEST = 'REQUEST';
    public const PICKUP = 'PICKUP';
    public const RETURN = 'RETURN';

    public function profile(CarbonInterface|string $date): array
    {
        $day = $this->asDate($date);
        $weekly = OperationalWeeklySchedule::query()
            ->where('weekday', $day->dayOfWeekIso)
            ->first();
        $exception = OperationalDateException::query()
            ->whereDate('exception_date', $day->toDateString())
            ->first();

        return $this->profileFromRecords($day, $weekly, $exception);
    }

    /**
     * Load a complete operational-calendar range without issuing database
     * queries for every day rendered by the monthly borrowing calendar.
     *
     * @return Collection<string, array<string, mixed>> keyed by Y-m-d
     */
    public function profilesForRange(CarbonInterface|string $start, CarbonInterface|string $end): Collection
    {
        $first = $this->asDate($start);
        $last = $this->asDate($end);

        if ($last->lt($first)) {
            [$first, $last] = [$last, $first];
        }

        $weekly = OperationalWeeklySchedule::query()
            ->get()
            ->keyBy('weekday');
        $exceptions = OperationalDateException::query()
            ->whereBetween('exception_date', [$first->toDateString(), $last->toDateString()])
            ->get()
            ->keyBy(fn (OperationalDateException $exception) => CarbonImmutable::parse($exception->exception_date, config('app.timezone') ?: 'Asia/Manila')->toDateString());

        $profiles = collect();
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $profiles->put(
                $day->toDateString(),
                $this->profileFromRecords(
                    $day,
                    $weekly->get($day->dayOfWeekIso),
                    $exceptions->get($day->toDateString())
                )
            );
        }

        return $profiles;
    }

    public function isOpenFor(string $activity, CarbonInterface|string $dateTime, bool $respectHours = false): bool
    {
        $activity = strtoupper($activity);

        /*
         * Borrower request submission is an online service and is available
         * at any time. Weekly office state, operating hours, weekends, and
         * special closures govern physical SPMU transactions only.
         */
        if ($activity === self::REQUEST) {
            return true;
        }

        $at = $this->asDateTime($dateTime);
        $profile = $this->profile($at);

        $allowed = match ($activity) {
            self::PICKUP => (bool) $profile['allows_pickup'],
            self::RETURN => (bool) $profile['allows_return'],
            default => false,
        };

        if (! $allowed) {
            return false;
        }

        [$open, $close] = $this->operatingWindow($activity, $at, $profile);

        /*
         * Physical transactions fail closed unless a complete, valid
         * operating window is configured for the selected date.
         */
        if (! $open || ! $close || ! $open->lt($close)) {
            return false;
        }

        if (! $respectHours) {
            return true;
        }

        return $at->betweenIncluded($open, $close);
    }

    /**
     * Resolve the effective time window for an activity on a given day.
     *
     * The time window comes directly from Operational Configuration for the
     * selected date, including any date-specific exception. Pickup / release
     * therefore follows the administrator's current Open Time and Close Time.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function operatingWindow(string $activity, CarbonInterface|string $dateTime, ?array $profile = null): array
    {
        $at = $this->asDateTime($dateTime);
        $profile ??= $this->profile($at);
        $timezone = config('app.timezone') ?: 'Asia/Manila';
        $date = $at->toDateString();

        $open = $profile['open_time']
            ? CarbonImmutable::parse($date.' '.$profile['open_time'], $timezone)
            : null;
        $close = $profile['close_time']
            ? CarbonImmutable::parse($date.' '.$profile['close_time'], $timezone)
            : null;

        return [$open, $close];
    }

    /**
     * The next date and time at which a physical pickup / release may occur.
     *
     * Reuses the existing day resolver (weekly schedule, special dates and
     * closures included) and then applies the pickup window, so there is no
     * second schedule resolver.
     */
    public function nextPickupWindow(CarbonInterface|string $from): ?CarbonImmutable
    {
        $at = $this->asDateTime($from);

        // The current day may still be used when Pickup / Release is enabled
        // and a complete operating window is configured.
        if ($this->isOpenFor(self::PICKUP, $at, false)) {
            [$open, $close] = $this->operatingWindow(self::PICKUP, $at);

            if ($open && $close && $at->lte($close)) {
                return $at->lt($open) ? $open : $at;
            }
        }

        /*
         * Find the next Pickup / Release day that also has a complete
         * Open Time and Close Time. An enabled day without both times is
         * not a valid physical transaction window.
         */
        $candidate = $at->addDay()->startOfDay();

        for ($i = 0; $i <= 370; $i++) {
            if ($this->isOpenFor(self::PICKUP, $candidate, false)) {
                [$open, $close] = $this->operatingWindow(self::PICKUP, $candidate);

                if ($open && $close && $open->lt($close)) {
                    return $open;
                }
            }

            $candidate = $candidate->addDay();
        }

        // Request creation must remain available even when no future
        // Pickup / Release window is currently configured.
        return null;
    }

    /**
     * Resolve the automatic Pickup / Issuance window for a newly approved
     * request. The normal pickup date is the latest valid SPMU operating day
     * strictly BEFORE the approved Items Needed From date. Closed weekends,
     * holidays, suspensions, and date-specific closures are skipped.
     *
     * When approval happens on that same prior operating day, the window may
     * start at the current minute as long as the office has not yet closed.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function automaticPickupWindowBefore(
        CarbonInterface|string $neededFrom,
        CarbonInterface|string|null $notBefore = null
    ): ?array {
        $needDate = $this->asDate($neededFrom);
        $floor = $notBefore !== null
            ? $this->asDateTime($notBefore)
            : CarbonImmutable::now(config('app.timezone') ?: 'Asia/Manila');

        for (
            $candidate = $needDate->subDay();
            $candidate->gte($floor->startOfDay());
            $candidate = $candidate->subDay()
        ) {
            if (! $this->isOpenFor(self::PICKUP, $candidate, false)) {
                continue;
            }

            [$open, $close] = $this->operatingWindow(self::PICKUP, $candidate);

            if (! $open || ! $close || ! $open->lt($close)) {
                continue;
            }

            if ($candidate->isSameDay($floor)) {
                if ($floor->gte($close)) {
                    continue;
                }

                $start = $floor->gt($open)
                    ? $floor->startOfMinute()
                    : $open;

                if ($start->gte($close)) {
                    continue;
                }

                return ['start' => $start, 'end' => $close];
            }

            return ['start' => $open, 'end' => $close];
        }

        return null;
    }

    /**
     * Resolve the latest configured Pickup / Release window that can still
     * occur on or before the approved Items Needed From date. This is a
     * scheduling suggestion only; the Action Officer still confirms the
     * actual appointment and normal schedulePickup() validation remains
     * authoritative.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function latestPickupWindowOnOrBefore(
        CarbonInterface|string $neededFrom,
        CarbonInterface|string $notBefore
    ): ?array {
        $needDate = $this->asDate($neededFrom);
        $floor = $this->asDateTime($notBefore);

        if ($needDate->lt($floor->startOfDay())) {
            return null;
        }

        for ($candidate = $needDate; $candidate->gte($floor->startOfDay()); $candidate = $candidate->subDay()) {
            if (! $this->isOpenFor(self::PICKUP, $candidate, false)) {
                continue;
            }

            [$open, $close] = $this->operatingWindow(self::PICKUP, $candidate);

            if (! $open || ! $close || ! $open->lt($close)) {
                continue;
            }

            if ($candidate->isSameDay($floor)) {
                if ($floor->gt($close)) {
                    continue;
                }

                $start = $floor->gt($open) ? $floor->startOfMinute() : $open;

                if ($start->gt($close)) {
                    continue;
                }

                return ['start' => $start, 'end' => $close];
            }

            return ['start' => $open, 'end' => $close];
        }

        return null;
    }

    public function nextOpenDate(string $activity, CarbonInterface|string $from, bool $includeCurrent = true): CarbonImmutable
    {
        $candidate = $this->asDate($from);
        if (! $includeCurrent) {
            $candidate = $candidate->addDay();
        }

        for ($i = 0; $i <= 370; $i++) {
            if ($this->isOpenFor($activity, $candidate)) {
                return $candidate;
            }
            $candidate = $candidate->addDay();
        }

        throw ValidationException::withMessages([
            'operational_calendar' => 'No open operational date could be found within the next year. Review the Operational Calendar configuration.',
        ]);
    }

    public function effectiveReturnDate(CarbonInterface|string $requestedDate): CarbonImmutable
    {
        return $this->nextOpenDate(self::RETURN, $requestedDate, true);
    }

    public function effectiveReturnDeadline(CarbonInterface|string $requestedDate): CarbonImmutable
    {
        return $this->effectiveReturnDate($requestedDate)->endOfDay();
    }

    public function assertOpenFor(string $activity, CarbonInterface|string $dateTime, string $field = 'schedule'): void
    {
        if ($this->isOpenFor($activity, $dateTime, true)) {
            return;
        }

        $at = $this->asDateTime($dateTime);
        $date = $at->startOfDay();
        $profile = $this->profile($date);
        $label = match (strtoupper($activity)) {
            self::REQUEST => 'request submission',
            self::PICKUP => 'pickup / release',
            self::RETURN => 'return transaction',
            default => 'transaction',
        };

        if ($this->isOpenFor($activity, $date, false)) {
            [$open, $close] = $this->operatingWindow($activity, $at, $profile);

            if ($open && $close && ($at->lt($open) || $at->gt($close))) {
                if (strtoupper($activity) === self::PICKUP) {
                    throw ValidationException::withMessages([
                        $field => 'Please choose a pickup time between '
                            .$open->format('g:i A').' and '.$close->format('g:i A')
                            .' for the selected date.',
                    ]);
                }

                if ($at->lt($open)) {
                    throw ValidationException::withMessages([
                        $field => ucfirst($label).' is available from '.$open->format('g:i A').' to '.$close->format('g:i A').'.',
                    ]);
                }

                throw ValidationException::withMessages([
                    $field => "Today's ".$label.' window has ended. Allowed window: '
                        .$open->format('g:i A').' – '.$close->format('g:i A').'.',
                ]);
            }
        }

        $next = $this->nextOpenDate($activity, $date, true);
        $reason = $profile['reason'] ? ' '.$profile['reason'] : '';
        $label = match (strtoupper($activity)) {
            self::REQUEST => 'request submission',
            self::PICKUP => 'pickup / release',
            self::RETURN => 'return transaction',
            default => 'transaction',
        };

        if (strtoupper($activity) === self::PICKUP) {
            throw ValidationException::withMessages([
                $field => 'Pickup and release are not available on '.$date->format('F j, Y').'.'
                    .$reason.' Please choose the next open date: '.$next->format('F j, Y').'.',
            ]);
        }

        throw ValidationException::withMessages([
            $field => ucfirst($label).' is closed on '.$date->format('F j, Y').'.'.$reason.' Next open date: '.$next->format('F j, Y').'.',
        ]);
    }

    public function synchronizeCustodyDueDate(CustodyTransaction $custody, ?AuditService $audit = null): CustodyTransaction
    {
        if (! $custody->due_at && ! $custody->original_due_at) {
            return $custody;
        }

        $original = $this->asDateTime($custody->original_due_at ?: $custody->due_at)->endOfDay();
        $effective = $this->effectiveReturnDeadline($original);
        $changed = ! $custody->original_due_at || ! $custody->due_at || ! $custody->due_at->isSameDay($effective);

        $profile = $this->profile($original);
        $reason = $original->isSameDay($effective)
            ? null
            : ($profile['reason'] ?: 'Original Expected Return Date is not an open SPMU return date.');

        if ($changed || (string) $custody->due_adjustment_reason !== (string) $reason) {
            $before = [
                'original_due_at' => $custody->original_due_at?->toIso8601String(),
                'due_at' => $custody->due_at?->toIso8601String(),
                'due_adjustment_reason' => $custody->due_adjustment_reason,
            ];

            $custody->forceFill([
                'original_due_at' => $original,
                'due_at' => $effective,
                'due_adjustment_reason' => $reason,
                'due_adjusted_at' => $reason ? now() : null,
            ])->save();

            if (
                $custody->status === 'OVERDUE'
                && ! now()->startOfDay()->gt($effective->startOfDay())
            ) {
                $overdueCase = OverdueCase::query()
                    ->where('custody_transaction_id', $custody->id)
                    ->first();

                $canReverseAutomaticLateState = ! $overdueCase
                    || in_array($overdueCase->status, ['OVERDUE', 'OPEN', 'CALENDAR_ADJUSTED'], true);

                if ($canReverseAutomaticLateState) {
                    $custody->forceFill(['status' => 'ACTIVE'])->save();

                    if ($overdueCase) {
                        $overdueCase->update(['status' => 'CALENDAR_ADJUSTED']);
                    }

                    BorrowerRestriction::query()
                        ->where('borrower_user_id', $custody->borrower_user_id)
                        ->whereIn('restriction_type', ['PENDING_RETURN', 'OVERDUE_RETURN'])
                        ->where('status', 'ACTIVE')
                        ->update([
                            'status' => 'LIFTED',
                            'effective_to' => now(),
                        ]);
                }
            }

            if ($audit) {
                $audit->record(
                    'CUSTODY_RETURN_DATE_SYNCHRONIZED',
                    $custody,
                    before: $before,
                    after: [
                        'original_expected_return_date' => $original->toDateString(),
                        'effective_return_date' => $effective->toDateString(),
                        'adjustment_reason' => $reason,
                    ]
                );
            }
        }

        return $custody->fresh();
    }


    private function profileFromRecords(
        CarbonImmutable $day,
        ?OperationalWeeklySchedule $weekly,
        ?OperationalDateException $exception
    ): array {
        $defaultOpen = $day->dayOfWeekIso <= 5;
        $weeklyProfile = [
            'is_open' => (bool) ($weekly?->is_open ?? $defaultOpen),
            'accepts_requests' => (bool) ($weekly?->accepts_requests ?? $defaultOpen),
            'allows_pickup' => (bool) ($weekly?->allows_pickup ?? $defaultOpen),
            'allows_return' => (bool) ($weekly?->allows_return ?? $defaultOpen),
            'open_time' => $weekly?->open_time,
            'close_time' => $weekly?->close_time,
            'source' => 'WEEKLY',
            'reason' => null,
        ];

        if (! $exception) {
            return $weeklyProfile;
        }

        if (strtoupper((string) $exception->status) === 'CLOSED') {
            return [
                'is_open' => false,
                'accepts_requests' => false,
                'allows_pickup' => false,
                'allows_return' => false,
                'open_time' => null,
                'close_time' => null,
                'source' => 'EXCEPTION',
                'reason' => $exception->reason ?: 'SPMU operations are closed on this date.',
            ];
        }

        return [
            'is_open' => true,
            'accepts_requests' => $exception->accepts_requests ?? $weeklyProfile['accepts_requests'],
            'allows_pickup' => $exception->allows_pickup ?? $weeklyProfile['allows_pickup'],
            'allows_return' => $exception->allows_return ?? $weeklyProfile['allows_return'],
            'open_time' => $exception->open_time ?: $weeklyProfile['open_time'],
            'close_time' => $exception->close_time ?: $weeklyProfile['close_time'],
            'source' => 'EXCEPTION',
            'reason' => $exception->reason,
        ];
    }

    private function asDate(CarbonInterface|string $value): CarbonImmutable
    {
        return $this->asDateTime($value)->startOfDay();
    }

    private function asDateTime(CarbonInterface|string $value): CarbonImmutable
    {
        $timezone = config('app.timezone') ?: 'Asia/Manila';

        return $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)->setTimezone($timezone)
            : CarbonImmutable::parse($value, $timezone);
    }
}

