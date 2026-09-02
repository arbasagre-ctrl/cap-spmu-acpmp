<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\OperationalDateException;
use App\Models\OperationalWeeklySchedule;
use App\Models\User;
use App\Services\OperationalCalendarService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * An SPMU day carries an operating state and three transaction permissions:
 *
 *   Requests          online workflow action
 *   Pickup / Release  physical counter transaction, 1:00 PM - 4:00 PM
 *   Returns           physical counter transaction, unchanged hours
 *
 * Persistence rule: closing a day withdraws all three permissions and both
 * operating hours. PolicyController normalises this on both the single-day and
 * the batch save, so a stored closed day never carries a permission or a time.
 *
 * OperationalCalendarService is a separate concern: it reads the permission
 * flags rather than is_open, so a row seeded directly with a permission on a
 * closed day still evaluates that permission. That combination is no longer
 * reachable through the UI, but legacy rows keep it well defined.
 *
 * Boundary convention: the pickup window is inclusive at both ends
 * (13:00 <= t <= 16:00), matching the existing betweenIncluded() convention
 * used for configured operating hours.
 */
class OperationalTransactionScheduleTest extends TestCase
{
    use RefreshDatabase;

    private OperationalCalendarService $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->calendar = app(OperationalCalendarService::class);

        // Deterministic clock: a Monday, so weekday maths never depends on
        // the day the suite happens to run.
        Carbon::setTestNow(
            Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0)
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Configure the weekday that "today" falls on. */
    private function configureToday(array $values): void
    {
        OperationalWeeklySchedule::query()->updateOrCreate(
            ['weekday' => Carbon::now()->dayOfWeekIso],
            $values
        );
    }

    private function at(string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return Carbon::now()->setTime($hour, $minute);
    }

    // -----------------------------------------------------------------
    // 1-2. Closed day, Requests permission decides submission
    // -----------------------------------------------------------------

    public function test_closed_day_still_accepts_requests_when_the_permission_is_enabled(): void
    {
        $this->configureToday([
            'is_open' => false,
            'accepts_requests' => true,
            'allows_pickup' => false,
            'allows_return' => false,
        ]);

        $this->assertTrue(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, Carbon::now())
        );

        // The office being closed does not by itself permit physical work.
        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::PICKUP, Carbon::now())
        );
        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::RETURN, Carbon::now())
        );
    }

    public function test_closed_day_without_the_requests_permission_blocks_submission(): void
    {
        $this->configureToday([
            'is_open' => false,
            'accepts_requests' => false,
            'allows_pickup' => false,
            'allows_return' => false,
        ]);

        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, Carbon::now())
        );

        $this->expectException(ValidationException::class);

        $this->calendar->assertOpenFor(
            OperationalCalendarService::REQUEST,
            Carbon::now(),
            'submission'
        );
    }

    public function test_open_day_without_the_requests_permission_blocks_submission(): void
    {
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => false,
            'allows_pickup' => true,
            'allows_return' => true,
        ]);

        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, Carbon::now())
        );
    }

    // -----------------------------------------------------------------
    // 3. Closing a day withdraws its permissions and operating hours
    // -----------------------------------------------------------------

    /** The SPMU Head who owns operational configuration. */
    private function spmuHead(): User
    {
        return User::query()
            ->where('access_classification', AccessClassification::SpmuHead->value)
            ->firstOrFail();
    }

    private function storedSchedule(int $weekday): OperationalWeeklySchedule
    {
        return OperationalWeeklySchedule::query()
            ->where('weekday', $weekday)
            ->firstOrFail();
    }

    private function assertDayIsFullyClosed(OperationalWeeklySchedule $stored): void
    {
        $this->assertFalse((bool) $stored->is_open);
        $this->assertFalse((bool) $stored->accepts_requests, 'Closing a day withdraws Requests.');
        $this->assertFalse((bool) $stored->allows_pickup, 'Closing a day withdraws Pickup / Release.');
        $this->assertFalse((bool) $stored->allows_return, 'Closing a day withdraws Returns.');
        $this->assertNull($stored->open_time, 'A closed day keeps no opening time.');
        $this->assertNull($stored->close_time, 'A closed day keeps no closing time.');
    }

    public function test_closing_a_day_clears_transaction_permissions_and_operating_hours(): void
    {
        $weekday = Carbon::now()->dayOfWeekIso;

        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => true,
            'allows_return' => true,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        /*
         * The payload still carries the permissions and the hours, which is
         * what a stale or hand-built submission looks like. Closing the day
         * must withdraw them regardless of what was posted.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->spmuHead())
            ->put(route('policies.weekly-schedule.update', $weekday), [
                'is_open' => 0,
                'accepts_requests' => 1,
                'allows_pickup' => 1,
                'allows_return' => 1,
                'open_time' => '08:00',
                'close_time' => '17:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDayIsFullyClosed($this->storedSchedule($weekday));
    }

    public function test_batch_update_clears_permissions_and_hours_only_for_the_closed_day(): void
    {
        $closingWeekday = Carbon::now()->dayOfWeekIso;
        $openWeekday = Carbon::now()->addDay()->dayOfWeekIso;

        foreach ([$closingWeekday, $openWeekday] as $weekday) {
            OperationalWeeklySchedule::query()->updateOrCreate(
                ['weekday' => $weekday],
                [
                    'is_open' => true,
                    'accepts_requests' => true,
                    'allows_pickup' => true,
                    'allows_return' => true,
                    'open_time' => '08:00',
                    'close_time' => '17:00',
                ]
            );
        }

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->spmuHead())
            ->put(route('policies.weekly-schedule.batch-update'), [
                'schedule' => [
                    $closingWeekday => [
                        'is_open' => 0,
                        'accepts_requests' => 1,
                        'allows_pickup' => 1,
                        'allows_return' => 1,
                        'open_time' => '08:00',
                        'close_time' => '17:00',
                    ],
                    $openWeekday => [
                        'is_open' => 1,
                        'accepts_requests' => 1,
                        'allows_pickup' => 1,
                        'allows_return' => 1,
                        'open_time' => '08:00',
                        'close_time' => '17:00',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDayIsFullyClosed($this->storedSchedule($closingWeekday));

        // The day left open in the same submission keeps everything.
        $stillOpen = $this->storedSchedule($openWeekday);

        $this->assertTrue((bool) $stillOpen->is_open);
        $this->assertTrue((bool) $stillOpen->accepts_requests);
        $this->assertTrue((bool) $stillOpen->allows_pickup);
        $this->assertTrue((bool) $stillOpen->allows_return);
        $this->assertNotNull($stillOpen->open_time);
        $this->assertNotNull($stillOpen->close_time);
    }

    // -----------------------------------------------------------------
    // 4-7. Pickup / release day and time
    // -----------------------------------------------------------------

    public function test_pickup_is_blocked_when_the_day_does_not_allow_it(): void
    {
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => false,
            'allows_return' => true,
        ]);

        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::PICKUP, $this->at('14:00'), true)
        );

        // Online request submission is unaffected.
        $this->assertTrue(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, $this->at('14:00'))
        );
    }

    public static function pickupBoundaries(): array
    {
        return [
            'one minute before the window' => ['12:59', false],
            'exactly 1:00 PM' => ['13:00', true],
            'inside the window' => ['14:30', true],
            'exactly 4:00 PM' => ['16:00', true],
            'one minute after the window' => ['16:01', false],
            'evening' => ['17:00', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pickupBoundaries')]
    public function test_pickup_window_boundaries(string $time, bool $expected): void
    {
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => true,
            'allows_return' => true,
        ]);

        $this->assertSame(
            $expected,
            $this->calendar->isOpenFor(
                OperationalCalendarService::PICKUP,
                $this->at($time),
                true
            ),
            $time.' should '.($expected ? 'be' : 'not be').' inside the pickup window.'
        );

        // The window never applies to online request submission.
        $this->assertTrue(
            $this->calendar->isOpenFor(
                OperationalCalendarService::REQUEST,
                $this->at($time),
                true
            )
        );
    }

    public function test_before_the_window_reports_the_available_hours(): void
    {
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => true,
            'allows_return' => true,
        ]);

        try {
            $this->calendar->assertOpenFor(
                OperationalCalendarService::PICKUP,
                $this->at('11:00'),
                'release'
            );
            $this->fail('Pickup before 1:00 PM must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'available from 1:00 PM to 4:00 PM',
                $exception->validator->errors()->first('release')
            );
        }

        // Same-day 1:00 PM is still the next valid window.
        $this->assertSame(
            $this->at('13:00')->format('Y-m-d H:i'),
            $this->calendar->nextPickupWindow($this->at('11:00'))->format('Y-m-d H:i')
        );
    }

    public function test_after_the_window_suggests_the_next_valid_schedule(): void
    {
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => true,
            'allows_return' => true,
        ]);

        try {
            $this->calendar->assertOpenFor(
                OperationalCalendarService::PICKUP,
                $this->at('17:00'),
                'release'
            );
            $this->fail('Pickup after 4:00 PM must be rejected.');
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first('release');
            $this->assertStringContainsString("Today's", $message);
            $this->assertStringContainsString('window has ended', $message);
        }

        $next = $this->calendar->nextPickupWindow($this->at('17:00'));

        $this->assertTrue($next->gt($this->at('17:00')));
        $this->assertSame('13:00', $next->format('H:i'));
    }

    // -----------------------------------------------------------------
    // 8. Next valid day resolution
    // -----------------------------------------------------------------

    public function test_next_pickup_window_skips_days_where_pickup_is_disabled(): void
    {
        $today = Carbon::now()->dayOfWeekIso;
        $tomorrow = Carbon::now()->addDay()->dayOfWeekIso;

        // Today's window has already ended, and tomorrow disallows pickup.
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => true,
            'allows_return' => true,
        ]);

        OperationalWeeklySchedule::query()->updateOrCreate(
            ['weekday' => $tomorrow],
            [
                'is_open' => true,
                'accepts_requests' => true,
                'allows_pickup' => false,
                'allows_return' => true,
            ]
        );

        $next = $this->calendar->nextPickupWindow($this->at('17:00'));

        $this->assertNotSame(
            Carbon::now()->addDay()->toDateString(),
            $next->toDateString(),
            'A day with Pickup disabled must be skipped.'
        );
        $this->assertSame('13:00', $next->format('H:i'));
        $this->assertNotSame($today, 0);
    }

    public function test_a_closed_date_exception_still_overrides_every_permission(): void
    {
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => true,
            'allows_return' => true,
        ]);

        OperationalDateException::query()->create([
            'exception_date' => Carbon::now()->toDateString(),
            'status' => 'CLOSED',
            'accepts_requests' => false,
            'allows_pickup' => false,
            'allows_return' => false,
            'reason' => 'Institutional holiday.',
        ]);

        // Special-date precedence is unchanged: a closure closes everything.
        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, Carbon::now())
        );
        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::PICKUP, Carbon::now())
        );
        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::RETURN, Carbon::now())
        );
    }

    public function test_closed_day_may_still_allow_pickup_when_policy_says_so(): void
    {
        $this->configureToday([
            'is_open' => false,
            'accepts_requests' => true,
            'allows_pickup' => true,
            'allows_return' => false,
        ]);

        // CASE E: the explicit permission is evaluated, not the day toggle.
        $this->assertTrue(
            $this->calendar->isOpenFor(
                OperationalCalendarService::PICKUP,
                $this->at('14:00'),
                true
            )
        );
    }

    // -----------------------------------------------------------------
    // 9. Returns regression
    // -----------------------------------------------------------------

    public function test_returns_keep_their_own_permission_and_no_pickup_time_window(): void
    {
        $this->configureToday([
            'is_open' => false,
            'accepts_requests' => false,
            'allows_pickup' => false,
            'allows_return' => true,
        ]);

        // Returns are independently allowed, and the 1-4 PM pickup window is
        // not applied to them at any hour.
        foreach (['08:00', '12:59', '13:00', '16:00', '17:30'] as $time) {
            $this->assertTrue(
                $this->calendar->isOpenFor(
                    OperationalCalendarService::RETURN,
                    $this->at($time),
                    true
                ),
                'Returns must not inherit the pickup window at '.$time.'.'
            );
        }
    }
}
