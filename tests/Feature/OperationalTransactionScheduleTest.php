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
 * Operational configuration governs physical SPMU transactions only.
 *
 *   Online requests     available 24/7, independent of office state/hours
 *   Pickup / Release    physical transaction; configured day + hours
 *   Returns             physical transaction; configured day + hours
 *
 * A physical transaction fails closed when its day is disabled or when an
 * enabled day does not have a complete, valid Open Time / Close Time pair.
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
        $physicalEnabled = (bool) ($values['allows_pickup'] ?? false)
            || (bool) ($values['allows_return'] ?? false);

        if ($physicalEnabled && ! array_key_exists('open_time', $values)) {
            $values['open_time'] = '13:00';
        }
        if ($physicalEnabled && ! array_key_exists('close_time', $values)) {
            $values['close_time'] = '16:00';
        }

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
    // 1-2. Online request submission is 24/7
    // -----------------------------------------------------------------

    public function test_online_request_submission_is_available_on_a_closed_weekday(): void
    {
        $this->configureToday([
            'is_open' => false,
            'accepts_requests' => false,
            'allows_pickup' => false,
            'allows_return' => false,
        ]);

        $this->assertTrue(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, $this->at('23:30'), true)
        );

        $this->calendar->assertOpenFor(
            OperationalCalendarService::REQUEST,
            $this->at('23:30'),
            'submission'
        );
    }

    public function test_online_request_submission_is_available_during_a_closed_special_date(): void
    {
        OperationalDateException::query()->create([
            'exception_date' => Carbon::now()->toDateString(),
            'status' => 'CLOSED',
            'accepts_requests' => false,
            'allows_pickup' => false,
            'allows_return' => false,
            'reason' => 'Institutional closure.',
        ]);

        $this->assertTrue(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, $this->at('21:00'), true)
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
        $this->assertTrue((bool) $stored->accepts_requests, 'The legacy request flag remains enabled because online submission is 24/7.');
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

        // Online request submission remains available at all times.
        $this->assertTrue(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, $this->at('14:00'))
        );
    }

    public function test_physical_transactions_fail_closed_when_hours_are_blank(): void
    {
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => true,
            'allows_return' => true,
            'open_time' => null,
            'close_time' => null,
        ]);

        $this->assertTrue(
            $this->calendar->isOpenFor(OperationalCalendarService::REQUEST, $this->at('21:00'), true),
            'Online request submission is not limited by physical counter hours.'
        );
        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::PICKUP, $this->at('14:00'), true)
        );
        $this->assertFalse(
            $this->calendar->isOpenFor(OperationalCalendarService::RETURN, $this->at('14:00'), true)
        );
    }

    public function test_weekly_schedule_rejects_blank_hours_when_physical_transactions_are_enabled(): void
    {
        $weekday = Carbon::now()->dayOfWeekIso;

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->spmuHead())
            ->put(route('policies.weekly-schedule.update', $weekday), [
                'is_open' => 1,
                'accepts_requests' => 1,
                'allows_pickup' => 1,
                'allows_return' => 1,
                'open_time' => '',
                'close_time' => '',
            ])
            ->assertSessionHasErrors('open_time');
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
            'late evening' => ['21:00', false],
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

        OperationalWeeklySchedule::query()->updateOrCreate(
            ['weekday' => Carbon::now()->addDay()->dayOfWeekIso],
            [
                'is_open' => true,
                'accepts_requests' => true,
                'allows_pickup' => true,
                'allows_return' => true,
                'open_time' => '13:00',
                'close_time' => '16:00',
            ]
        );

        $next = $this->calendar->nextPickupWindow($this->at('17:00'));

        $this->assertTrue($next->gt($this->at('17:00')));
        $this->assertSame('13:00', $next->format('H:i'));
    }

    // -----------------------------------------------------------------
    // 8. Next valid day resolution
    // -----------------------------------------------------------------

    public function test_latest_pickup_window_on_or_before_need_date_uses_the_previous_operational_day(): void
    {
        $tuesday = Carbon::now()->addDay();
        $wednesday = Carbon::now()->addDays(2);

        OperationalWeeklySchedule::query()->updateOrCreate(
            ['weekday' => $tuesday->dayOfWeekIso],
            [
                'is_open' => true,
                'accepts_requests' => false,
                'allows_pickup' => true,
                'allows_return' => true,
                'open_time' => '13:00',
                'close_time' => '16:00',
            ]
        );

        OperationalWeeklySchedule::query()->updateOrCreate(
            ['weekday' => $wednesday->dayOfWeekIso],
            [
                'is_open' => false,
                'accepts_requests' => false,
                'allows_pickup' => false,
                'allows_return' => false,
                'open_time' => null,
                'close_time' => null,
            ]
        );

        $window = $this->calendar->latestPickupWindowOnOrBefore(
            $wednesday,
            Carbon::now()
        );

        $this->assertNotNull($window);
        $this->assertSame($tuesday->toDateString(), $window['start']->toDateString());
        $this->assertSame('13:00', $window['start']->format('H:i'));
        $this->assertSame('16:00', $window['end']->format('H:i'));
    }

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

        OperationalWeeklySchedule::query()->updateOrCreate(
            ['weekday' => Carbon::now()->addDays(2)->dayOfWeekIso],
            [
                'is_open' => true,
                'accepts_requests' => true,
                'allows_pickup' => true,
                'allows_return' => true,
                'open_time' => '13:00',
                'close_time' => '16:00',
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

    public function test_a_closed_date_exception_blocks_physical_transactions_but_not_online_requests(): void
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

        // The closure blocks physical transactions, but online submission remains available.
        $this->assertTrue(
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

    public function test_returns_use_their_own_permission_and_configured_physical_hours(): void
    {
        $this->configureToday([
            'is_open' => true,
            'accepts_requests' => true,
            'allows_pickup' => false,
            'allows_return' => true,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        foreach (['08:00', '12:59', '13:00', '16:00', '17:00'] as $time) {
            $this->assertTrue(
                $this->calendar->isOpenFor(
                    OperationalCalendarService::RETURN,
                    $this->at($time),
                    true
                ),
                'Return should be permitted inside its configured window at '.$time.'.'
            );
        }

        foreach (['07:59', '17:01', '21:00'] as $time) {
            $this->assertFalse(
                $this->calendar->isOpenFor(
                    OperationalCalendarService::RETURN,
                    $this->at($time),
                    true
                ),
                'Return should be blocked outside its configured window at '.$time.'.'
            );
        }
    }
}
