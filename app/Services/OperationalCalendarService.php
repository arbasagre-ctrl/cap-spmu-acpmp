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
        $at = $this->asDateTime($dateTime);
        $profile = $this->profile($at);

        if (! $profile['is_open']) {
            return false;
        }

        $allowed = match (strtoupper($activity)) {
            self::REQUEST => (bool) $profile['accepts_requests'],
            self::PICKUP => (bool) $profile['allows_pickup'],
            self::RETURN => (bool) $profile['allows_return'],
            default => false,
        };

        if (! $allowed || ! $respectHours) {
            return $allowed;
        }

        if (! $profile['open_time'] || ! $profile['close_time']) {
            return true;
        }

        $timezone = config('app.timezone') ?: 'Asia/Manila';
        $open = CarbonImmutable::parse($at->toDateString().' '.$profile['open_time'], $timezone);
        $close = CarbonImmutable::parse($at->toDateString().' '.$profile['close_time'], $timezone);

        return $at->betweenIncluded($open, $close);
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

        if (
            $this->isOpenFor($activity, $date, false)
            && $profile['open_time']
            && $profile['close_time']
        ) {
            throw ValidationException::withMessages([
                $field => ucfirst($label).' is outside the configured operational hours on '.$date->format('F j, Y').'. Allowed window: '.substr((string) $profile['open_time'], 0, 5).' – '.substr((string) $profile['close_time'], 0, 5).'.',
            ]);
        }

        $next = $this->nextOpenDate($activity, $date, true);
        $reason = $profile['reason'] ? ' '.$profile['reason'] : '';
        $label = match (strtoupper($activity)) {
            self::REQUEST => 'request submission',
            self::PICKUP => 'pickup / release',
            self::RETURN => 'return transaction',
            default => 'transaction',
        };

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
