<?php

namespace Tests\Feature;

use App\Models\OperationalDateException;
use App\Models\OperationalWeeklySchedule;
use App\Services\OperationalCalendarService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Priority #1 hardening: a fresh installation must have usable default
 * Monday-Friday operating hours without requiring an Admin to visit
 * Operational Configuration first, because OperationalCalendarService
 * intentionally fails closed without a complete Open Time/Close Time pair.
 *
 * These tests exercise only the data migration
 * (2026_09_14_000001_seed_default_operational_weekly_hours) and the
 * existing, unmodified OperationalCalendarService/OperationalWeeklySchedule
 * architecture - no calendar service or controller logic changed.
 */
class OperationalCalendarDefaultHoursTest extends TestCase
{
    use RefreshDatabase;

    private OperationalCalendarService $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase already runs every migration - including the new
        // default-hours backfill - before this test body runs, so this is
        // exactly what a genuinely fresh installation looks like.
        $this->seed(DatabaseSeeder::class);
        $this->calendar = app(OperationalCalendarService::class);

        Carbon::setTestNow(Carbon::now()->next(Carbon::MONDAY)->setTime(10, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_fresh_seeded_installation_has_default_monday_to_friday_operating_hours(): void
    {
        $weekdays = OperationalWeeklySchedule::query()->orderBy('weekday')->get()->keyBy('weekday');

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $row = $weekdays->get($weekday);
            $this->assertNotNull($row, "Weekday {$weekday} should have a seeded row.");
            $this->assertTrue((bool) $row->is_open, "Weekday {$weekday} should be open by default.");
            $this->assertSame('08:00:00', substr((string) $row->open_time, 0, 8));
            $this->assertSame('17:00:00', substr((string) $row->close_time, 0, 8));
        }

        // Weekend rows remain closed, exactly as the original seed intended
        // - the default-hours backfill only ever targets weekdays 1-5.
        foreach ([6, 7] as $weekday) {
            $row = $weekdays->get($weekday);
            $this->assertNotNull($row);
            $this->assertFalse((bool) $row->is_open);
            $this->assertNull($row->open_time);
            $this->assertNull($row->close_time);
        }
    }

    public function test_pickup_and_return_validation_works_on_a_fresh_installation_without_manual_admin_configuration(): void
    {
        // No configureToday()/manual setup here at all - this is the exact
        // scenario that previously threw "No open operational date could be
        // found within the next year" for every physical transaction.
        $this->assertTrue($this->calendar->isOpenFor(OperationalCalendarService::PICKUP, $this->at('10:00')));
        $this->assertTrue($this->calendar->isOpenFor(OperationalCalendarService::RETURN, $this->at('10:00')));

        $this->calendar->assertOpenFor(OperationalCalendarService::PICKUP, $this->at('10:00'));
        $this->calendar->assertOpenFor(OperationalCalendarService::RETURN, $this->at('10:00'));

        $this->assertSame(
            Carbon::now()->toDateString(),
            $this->calendar->nextOpenDate(OperationalCalendarService::PICKUP, Carbon::now(), true)->toDateString()
        );

        $nextWindow = $this->calendar->nextPickupWindow($this->at('10:00'));
        $this->assertNotNull($nextWindow);
        $this->assertSame($this->at('10:00')->format('Y-m-d H:i'), $nextWindow->format('Y-m-d H:i'));

        // Outside the default window, the office correctly reports closed
        // rather than silently allowing an out-of-hours transaction.
        $this->expectException(ValidationException::class);
        $this->calendar->assertOpenFor(OperationalCalendarService::PICKUP, $this->at('19:00'));
    }

    public function test_admin_configured_hours_still_override_the_seeded_default(): void
    {
        $weekday = Carbon::now()->dayOfWeekIso;

        OperationalWeeklySchedule::query()->where('weekday', $weekday)->update([
            'open_time' => '13:00',
            'close_time' => '16:00',
        ]);

        [$open, $close] = $this->calendar->operatingWindow(OperationalCalendarService::PICKUP, $this->at('10:00'));

        $this->assertSame('13:00', $open->format('H:i'));
        $this->assertSame('16:00', $close->format('H:i'));

        // 10:00 is inside the seeded 08:00-17:00 default but now outside
        // the Admin's narrower 1:00 PM-4:00 PM window.
        $this->assertFalse($this->calendar->isOpenFor(OperationalCalendarService::PICKUP, $this->at('10:00'), true));
        $this->assertTrue($this->calendar->isOpenFor(OperationalCalendarService::PICKUP, $this->at('14:00'), true));
    }

    public function test_holidays_and_non_operating_days_still_behave_correctly(): void
    {
        // Weekends remain closed by default, unaffected by the backfill.
        $saturday = Carbon::now()->next(Carbon::SATURDAY)->setTime(10, 0);
        $this->assertFalse($this->calendar->isOpenFor(OperationalCalendarService::PICKUP, $saturday));

        // A date exception (holiday closure) on an otherwise-open weekday
        // still closes the office, exactly as before this change.
        $holiday = Carbon::now()->startOfDay();
        OperationalDateException::query()->create([
            'exception_date' => $holiday->toDateString(),
            'status' => 'CLOSED',
            'reason' => 'Declared holiday.',
        ]);

        $this->assertFalse($this->calendar->isOpenFor(OperationalCalendarService::PICKUP, $holiday->copy()->setTime(10, 0)));
        $this->assertTrue($this->calendar->isOpenFor(OperationalCalendarService::REQUEST, $holiday->copy()->setTime(10, 0)));

        $next = $this->calendar->nextOpenDate(OperationalCalendarService::PICKUP, $holiday, true);
        $this->assertSame($holiday->copy()->addDay()->toDateString(), $next->toDateString());
    }

    public function test_the_default_hours_migration_never_overwrites_a_schedule_the_admin_already_configured(): void
    {
        $weekday = Carbon::now()->dayOfWeekIso;

        // Simulate an Admin who, before this backfill ever ran, deliberately
        // configured a different window via Operational Configuration
        // (PolicyController::updateTransactionSchedule).
        OperationalWeeklySchedule::query()->where('weekday', $weekday)->update([
            'open_time' => '09:30',
            'close_time' => '15:45',
        ]);

        // Re-running the same migration (its up() is a targeted, guarded
        // update - see whereNull('open_time')->whereNull('close_time') -
        // so replaying it is exactly what proves it is safe against an
        // already-configured row, not just a hypothetical).
        (require database_path('migrations/2026_09_14_000001_seed_default_operational_weekly_hours.php'))->up();

        $row = OperationalWeeklySchedule::query()->where('weekday', $weekday)->first();
        $this->assertSame('09:30', substr((string) $row->open_time, 0, 5));
        $this->assertSame('15:45', substr((string) $row->close_time, 0, 5));
    }

    private function at(string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return Carbon::now()->setTime($hour, $minute);
    }
}
