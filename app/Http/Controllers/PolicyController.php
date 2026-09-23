<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Models\CustodyTransaction;
use App\Models\OperationalDateException;
use App\Models\OperationalWeeklySchedule;
use App\Models\SanctionRule;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\OperationalCalendarService;
use App\Services\NotificationService;
use App\Services\PolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PolicyController extends Controller
{
    public function index(Request $request, PolicyService $policy): View
    {
        $this->authorizeHead($request);

        $academicPeriods = AcademicPeriod::query()
            ->orderByDesc('start_date')
            ->get();

        return view('administration.policies', [
            'academicPeriods' => $academicPeriods,
            'activePeriod' => $academicPeriods->first(
                fn (AcademicPeriod $period): bool =>
                    $period->status === 'ACTIVE'
            ),
            'sanctionRules' => SanctionRule::query()
                ->whereIn('offense_no', [1, 2, 3])
                ->orderBy('offense_no')
                ->get()
                ->keyBy('offense_no'),
            'weeklySchedules' => OperationalWeeklySchedule::query()
                ->orderBy('weekday')
                ->get()
                ->keyBy('weekday'),
            'dateExceptions' => OperationalDateException::query()
                ->whereDate('exception_date', '>=', now()->subMonth()->toDateString())
                ->orderBy('exception_date')
                ->get(),
            'offenseApplicationTypes' => $policy->offenseApplicationTypes(),
        ]);
    }

    public function storeAcademicPeriod(
        Request $request,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeHead($request);

        $data = $request->validate([
            'academic_year' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d{4}-\d{4}$/',
            ],
            'term_code' => [
                'required',
                Rule::in([
                    'FIRST_SEMESTER',
                    'SECOND_SEMESTER',
                    'SUMMER_MIDYEAR',
                ]),
            ],
            'start_date' => [
                'required',
                'date',
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],
        ], [
            'academic_year.regex' =>
                'Use the academic year format YYYY-YYYY, for example 2026-2027.',
            'end_date.after_or_equal' =>
                'End Date cannot be earlier than Start Date.',
        ]);

        [$startYear, $endYear] = array_map(
            'intval',
            explode(
                '-',
                $data['academic_year']
            )
        );

        if ($endYear !== $startYear + 1) {
            return back()
                ->withErrors([
                    'academic_year' =>
                        'The academic year must use consecutive years, for example 2026-2027.',
                ])
                ->withInput();
        }

        $termName = $this->termNameFor(
            $data['term_code']
        );

        $existing = AcademicPeriod::query()
            ->where(
                'academic_year',
                $data['academic_year']
            )
            ->where(
                'term_code',
                $data['term_code']
            )
            ->first();

        $derivedStatus =
            now()->startOfDay()->gt(
                \Illuminate\Support\Carbon::parse(
                    $data['end_date']
                )->startOfDay()
            )
                ? 'COMPLETED'
                : 'UPCOMING';

        /*
         * If the SPMU Head updates the dates of the currently active period
         * through the add/save form, do not silently remove its Active state.
         * A period only changes Active state through the explicit Activate
         * action below.
         */
        if (
            $existing?->status === 'ACTIVE'
            && $derivedStatus !== 'COMPLETED'
        ) {
            $derivedStatus = 'ACTIVE';
        }

        $period = AcademicPeriod::query()->updateOrCreate(
            [
                'academic_year' =>
                    $data['academic_year'],

                'term_code' =>
                    $data['term_code'],
            ],
            [
                'term_name' =>
                    $termName,

                'start_date' =>
                    $data['start_date'],

                'end_date' =>
                    $data['end_date'],

                'status' =>
                    $derivedStatus,

                'configured_by_user_id' =>
                    $request->user()->id,
            ]
        );

        $audit->record(
            'ACADEMIC_PERIOD_CONFIGURED',
            $period,
            after: $period->toArray()
        );

        return back()->with(
            'status',
            $derivedStatus === 'ACTIVE'
                ? 'Active academic period dates updated.'
                : 'Academic period saved. Activate it when it becomes the official current period.'
        );
    }

    public function updateAcademicPeriod(
        Request $request,
        AcademicPeriod $period,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeHead($request);

        /*
         * The configuration screen uses this route for an explicit Activate
         * action. This keeps status management out of the create form and
         * guarantees that only one academic period is Active at a time.
         */
        if ($request->boolean('activate')) {
            if (
                $period->end_date
                && $period->end_date
                    ->copy()
                    ->endOfDay()
                    ->isPast()
            ) {
                return back()->withErrors([
                    'academic_period' =>
                        'A completed academic period cannot be activated.',
                ]);
            }

            $before = $period->toArray();

            AcademicPeriod::query()
                ->whereKeyNot($period->id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'COMPLETED',
                ]);

            $period->update([
                'status' =>
                    'ACTIVE',

                'configured_by_user_id' =>
                    $request->user()->id,
            ]);

            $audit->record(
                'ACADEMIC_PERIOD_ACTIVATED',
                $period,
                before: $before,
                after: $period->fresh()->toArray()
            );

            return back()->with(
                'status',
                $period->academic_year
                .' · '
                .$period->term_name
                .' is now the active academic period.'
            );
        }

        /*
         * Keep the existing update capability for compatibility with any
         * older links/forms, but derive Term Name from Semester / Term and
         * avoid requiring a manual Status selection.
         */
        $data = $request->validate([
            'academic_year' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d{4}-\d{4}$/',
            ],
            'term_code' => [
                'required',
                Rule::in([
                    'FIRST_SEMESTER',
                    'SECOND_SEMESTER',
                    'SUMMER_MIDYEAR',
                ]),
            ],
            'start_date' => [
                'required',
                'date',
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],
        ], [
            'academic_year.regex' =>
                'Use the academic year format YYYY-YYYY, for example 2026-2027.',
            'end_date.after_or_equal' =>
                'End Date cannot be earlier than Start Date.',
        ]);

        [$startYear, $endYear] = array_map(
            'intval',
            explode(
                '-',
                $data['academic_year']
            )
        );

        if ($endYear !== $startYear + 1) {
            return back()
                ->withErrors([
                    'academic_year' =>
                        'The academic year must use consecutive years, for example 2026-2027.',
                ])
                ->withInput();
        }

        $before = $period->toArray();

        $derivedStatus =
            $period->status === 'ACTIVE'
                ? 'ACTIVE'
                : (
                    now()->startOfDay()->gt(
                        \Illuminate\Support\Carbon::parse(
                            $data['end_date']
                        )->startOfDay()
                    )
                        ? 'COMPLETED'
                        : 'UPCOMING'
                );

        $period->update([
            'academic_year' =>
                $data['academic_year'],

            'term_code' =>
                $data['term_code'],

            'term_name' =>
                $this->termNameFor(
                    $data['term_code']
                ),

            'start_date' =>
                $data['start_date'],

            'end_date' =>
                $data['end_date'],

            'status' =>
                $derivedStatus,

            'configured_by_user_id' =>
                $request->user()->id,
        ]);

        $audit->record(
            'ACADEMIC_PERIOD_UPDATED',
            $period,
            before: $before,
            after: $period->fresh()->toArray()
        );

        return back()->with(
            'status',
            'Academic period updated.'
        );
    }

    public function updateSanctionRule(
        Request $request,
        int $offenseNo,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeHead($request);

        abort_unless(in_array($offenseNo, [1, 2, 3], true), 404);

        $data = $request->validate([
            'sanction_code' => [
                'required',
                Rule::in([
                    'NOTICE',
                    'WRITTEN_REPRIMAND',
                    'BORROWING_SUSPENSION',
                    'OTHER',
                ]),
            ],
            'sanction_label' => ['required', 'string', 'max:255'],
            'duration_mode' => [
                'required',
                Rule::in([
                    'NONE',
                    'MONTHS',
                    'UNTIL_ACADEMIC_PERIOD_END',
                    'MANUAL_DATE',
                ]),
            ],
            'duration_value' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        if ($data['duration_mode'] === 'MONTHS' && empty($data['duration_value'])) {
            return back()->withErrors([
                'duration_value' => 'Enter the number of months for this suspension rule.',
            ]);
        }

        if ($data['sanction_code'] !== 'BORROWING_SUSPENSION') {
            $data['duration_mode'] = 'NONE';
            $data['duration_value'] = null;
        }

        $rule = SanctionRule::query()->firstOrNew([
            'offense_no' => $offenseNo,
        ]);

        $before = $rule->exists ? $rule->toArray() : null;

        $rule->fill([
            'sanction_code' => $data['sanction_code'],
            'sanction_label' => trim($data['sanction_label']),
            'duration_mode' => $data['duration_mode'],
            'duration_value' => $data['duration_value'] ?? null,
            'status' => 'ACTIVE',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'configured_by_user_id' => $request->user()->id,
        ])->save();

        $audit->record(
            'SANCTION_RULE_CONFIGURED',
            $rule,
            before: $before,
            after: $rule->fresh()->toArray()
        );

        return redirect()
            ->to(route('policies.index', ['section' => 'sanction-rules']).'#sanction-rules')
            ->with('status', "{$offenseNo} offense sanction rule updated.");
    }

    public function updateOffenseApplication(
        Request $request,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeHead($request);

        $data = $request->validate([
            'case_types' => ['nullable', 'array'],
            'case_types.*' => [
                Rule::in([
                    'LATE_RETURN',
                    'DAMAGED',
                    'LOST_MISSING',
                    'STOLEN',
                    'DESTROYED',
                ]),
            ],
        ]);

        $caseTypes = array_values(array_unique(array_map(
            fn ($type) => strtoupper((string) $type),
            $data['case_types'] ?? []
        )));

        $setting = SystemSetting::query()->firstOrNew([
            'setting_key' => \App\Services\PolicyService::OFFENSE_APPLICATION_SETTING,
        ]);

        $before = $setting->exists ? $setting->toArray() : null;

        $setting->fill([
            'value_json' => $caseTypes,
            'data_type' => 'JSON',
            'group_code' => 'ACCOUNTABILITY',
            'description' => 'Property and return case types that the SPMU Head may explicitly confirm as an administrative offense.',
            'status' => 'ACTIVE',
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        $audit->record(
            'SANCTION_OFFENSE_APPLICATION_UPDATED',
            $setting,
            before: $before,
            after: $setting->fresh()->toArray()
        );

        return redirect()
            ->to(route('policies.index', ['section' => 'sanction-rules']).'#offense-application')
            ->with('status', 'Offense application rules updated. The SPMU Head still decides whether an eligible case is actually counted as an offense.');
    }

    public function updateWeeklySchedule(
        Request $request,
        int $weekday,
        AuditService $audit,
        OperationalCalendarService $calendar,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeHead($request);
        abort_unless($weekday >= 1 && $weekday <= 7, 404);

        $data = $request->validate([
            'is_open' => ['required', 'boolean'],
            'allows_pickup' => ['required', 'boolean'],
            'allows_return' => ['required', 'boolean'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
        ]);

        $physicalTransactionsEnabled = (bool) $data['is_open']
            && ((bool) $data['allows_pickup'] || (bool) $data['allows_return']);

        if ($physicalTransactionsEnabled && (! $data['open_time'] || ! $data['close_time'])) {
            return back()->withErrors([
                'open_time' => 'Open Time and Close Time are required when Pickup / Release or Returns are enabled.',
            ])->withInput();
        }

        if (($data['open_time'] && ! $data['close_time']) || (! $data['open_time'] && $data['close_time'])) {
            return back()->withErrors([
                'open_time' => 'Set both Open Time and Close Time, or leave both blank.',
            ])->withInput();
        }

        if ($data['open_time'] && $data['close_time'] && $data['close_time'] <= $data['open_time']) {
            return back()->withErrors([
                'close_time' => 'Closing time must be later than opening time.',
            ])->withInput();
        }

        // Online borrowing request submission is available 24/7 and is not
        // configured by this physical transaction schedule. Keep the legacy
        // database flag enabled for compatibility with older records/code.
        $data['accepts_requests'] = true;

        if (! (bool) $data['is_open']) {
            $data['allows_pickup'] = false;
            $data['allows_return'] = false;
            $data['open_time'] = null;
            $data['close_time'] = null;
        }

        $rule = OperationalWeeklySchedule::query()->firstOrNew(['weekday' => $weekday]);
        $before = $rule->exists ? $rule->toArray() : null;
        $rule->fill($data + ['configured_by_user_id' => $request->user()->id])->save();

        $audit->record('OPERATIONAL_WEEKLY_SCHEDULE_UPDATED', $rule, before: $before, after: $rule->fresh()->toArray());
        $calendarChanges = $this->synchronizeOpenCustodySchedules($calendar, $audit, $notifications);

        return redirect()->to(route('policies.index', ['section' => 'transaction-schedule']).'#transaction-schedule')
            ->with('status', $this->calendarUpdateStatus(
                'Weekly operational schedule updated.',
                $calendarChanges
            ));
    }

    public function updateWeeklyScheduleBatch(
        Request $request,
        AuditService $audit,
        OperationalCalendarService $calendar,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeHead($request);

        $data = $request->validate([
            'only_day' => ['nullable', 'integer', 'between:1,7'],
            'schedule' => ['required', 'array'],
            'schedule.*.is_open' => ['required', 'boolean'],
            'schedule.*.allows_pickup' => ['required', 'boolean'],
            'schedule.*.allows_return' => ['required', 'boolean'],
            'schedule.*.open_time' => ['nullable', 'date_format:H:i'],
            'schedule.*.close_time' => ['nullable', 'date_format:H:i'],
        ]);

        /*
         * A single-day Save posts the whole grid but limits persistence to
         * that weekday, so an unsaved edit elsewhere is never written by
         * accident.
         */
        $onlyDay = $data['only_day'] ?? null;

        $weekdays = collect($data['schedule'])
            ->keys()
            ->map(fn ($weekday): int => (int) $weekday)
            ->filter(fn (int $weekday): bool => $weekday >= 1 && $weekday <= 7)
            ->when(
                $onlyDay !== null,
                fn ($days) => $days->filter(
                    fn (int $weekday): bool => $weekday === (int) $onlyDay
                )
            )
            ->sort()
            ->values();

        abort_if($weekdays->isEmpty(), 404);

        $errors = [];

        foreach ($weekdays as $weekday) {
            $row = $data['schedule'][$weekday];
            $openTime = ($row['open_time'] ?? null) ?: null;
            $closeTime = ($row['close_time'] ?? null) ?: null;
            $physicalTransactionsEnabled = (bool) ($row['is_open'] ?? false)
                && ((bool) ($row['allows_pickup'] ?? false) || (bool) ($row['allows_return'] ?? false));

            if ($physicalTransactionsEnabled && (! $openTime || ! $closeTime)) {
                $errors["schedule.{$weekday}.open_time"] =
                    'Open Time and Close Time are required when Pickup / Release or Returns are enabled.';
                continue;
            }

            if (($openTime && ! $closeTime) || (! $openTime && $closeTime)) {
                $errors["schedule.{$weekday}.open_time"] =
                    'Set both Open Time and Close Time, or leave both blank.';
                continue;
            }

            if ($openTime && $closeTime && $closeTime <= $openTime) {
                $errors["schedule.{$weekday}.close_time"] =
                    'Closing time must be later than opening time.';
            }
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        $saved = 0;

        foreach ($weekdays as $weekday) {
            $row = $data['schedule'][$weekday];

            $values = [
                'is_open' => (bool) $row['is_open'],
                'accepts_requests' => true,
                'allows_pickup' => (bool) $row['allows_pickup'],
                'allows_return' => (bool) $row['allows_return'],
                'open_time' => ($row['open_time'] ?? null) ?: null,
                'close_time' => ($row['close_time'] ?? null) ?: null,
            ];

            /*
             * The weekly grid governs physical transactions only. Online
             * request submission remains available regardless of office state.
             */
            if (! $values['is_open']) {
                $values['allows_pickup'] = false;
                $values['allows_return'] = false;
                $values['open_time'] = null;
                $values['close_time'] = null;
            }

            $rule = OperationalWeeklySchedule::query()
                ->firstOrNew(['weekday' => $weekday]);

            /*
             * Stored times carry seconds, so they are trimmed to H:i before the
             * comparison. Without it every save-all would rewrite and audit
             * rows nobody touched.
             */
            $stored = [
                'is_open' => (bool) $rule->is_open,
                'accepts_requests' => (bool) $rule->accepts_requests,
                'allows_pickup' => (bool) $rule->allows_pickup,
                'allows_return' => (bool) $rule->allows_return,
                'open_time' => $rule->open_time
                    ? substr((string) $rule->open_time, 0, 5)
                    : null,
                'close_time' => $rule->close_time
                    ? substr((string) $rule->close_time, 0, 5)
                    : null,
            ];

            if ($rule->exists && $stored === $values) {
                continue;
            }

            $before = $rule->exists ? $rule->toArray() : null;

            $rule->fill(
                $values + ['configured_by_user_id' => $request->user()->id]
            );

            $rule->save();
            $saved++;

            $audit->record(
                'OPERATIONAL_WEEKLY_SCHEDULE_UPDATED',
                $rule,
                before: $before,
                after: $rule->fresh()->toArray()
            );
        }

        if ($saved === 0) {
            return redirect()
                ->to(route('policies.index', ['section' => 'transaction-schedule']).'#transaction-schedule')
                ->with('status', 'No schedule changes to save.');
        }

        $calendarChanges = $this->synchronizeOpenCustodySchedules($calendar, $audit, $notifications);

        return redirect()
            ->to(route('policies.index', ['section' => 'transaction-schedule']).'#transaction-schedule')
            ->with('status', $this->calendarUpdateStatus(
                $saved === 1
                    ? 'Weekly operational schedule updated.'
                    : $saved.' schedule days updated.',
                $calendarChanges
            ));
    }

    public function storeDateException(
        Request $request,
        AuditService $audit,
        OperationalCalendarService $calendar,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeHead($request);

        $data = $request->validate([
            'exception_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['OPEN', 'CLOSED'])],
            'allows_pickup' => ['required', 'boolean'],
            'allows_return' => ['required', 'boolean'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Special dates govern physical SPMU availability only. Online
        // borrowing request submission remains available at any time.
        $data['accepts_requests'] = true;

        if ($data['status'] === 'CLOSED') {
            $data['allows_pickup'] = false;
            $data['allows_return'] = false;
            $data['open_time'] = null;
            $data['close_time'] = null;
        }

        if ($data['status'] === 'OPEN') {
            $specialPhysicalTransactionsEnabled = (bool) $data['allows_pickup']
                || (bool) $data['allows_return'];

            if ($specialPhysicalTransactionsEnabled && (! $data['open_time'] || ! $data['close_time'])) {
                return back()->withErrors([
                    'open_time' => 'Open Time and Close Time are required for an open special date that allows Pickup / Release or Returns.',
                ])->withInput();
            }

            if (($data['open_time'] && ! $data['close_time']) || (! $data['open_time'] && $data['close_time'])) {
                return back()->withErrors([
                    'open_time' => 'Set both Open Time and Close Time, or leave both blank.',
                ])->withInput();
            }

            if ($data['open_time'] && $data['close_time'] && $data['close_time'] <= $data['open_time']) {
                return back()->withErrors([
                    'close_time' => 'Closing time must be later than opening time.',
                ])->withInput();
            }
        }

        $exception = OperationalDateException::query()->firstOrNew([
            'exception_date' => $data['exception_date'],
        ]);
        $before = $exception->exists ? $exception->toArray() : null;
        $exception->fill($data + ['configured_by_user_id' => $request->user()->id])->save();

        $audit->record('OPERATIONAL_DATE_EXCEPTION_SAVED', $exception, before: $before, after: $exception->fresh()->toArray());
        $calendarChanges = $this->synchronizeOpenCustodySchedules($calendar, $audit, $notifications);

        return redirect()->to(route('policies.index', ['section' => 'special-dates']).'#special-dates')
            ->with('status', $this->calendarUpdateStatus(
                'Special operational date saved.',
                $calendarChanges
            ));
    }

    public function destroyDateException(
        Request $request,
        OperationalDateException $exception,
        AuditService $audit,
        OperationalCalendarService $calendar,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeHead($request);

        $before = $exception->toArray();
        $audit->record('OPERATIONAL_DATE_EXCEPTION_REMOVED', $exception, before: $before);
        $exception->delete();
        $calendarChanges = $this->synchronizeOpenCustodySchedules($calendar, $audit, $notifications);

        return redirect()->to(route('policies.index', ['section' => 'special-dates']).'#special-dates')
            ->with('status', $this->calendarUpdateStatus(
                'Special operational date removed. Previously communicated return extensions are not shortened automatically.',
                $calendarChanges
            ));
    }

    /**
     * Reconcile both physical pickup windows and effective return deadlines
     * after any Operational Calendar edit. Pickup closures are handled as SPMU
     * schedule changes, never as borrower no-shows.
     *
     * @return array{pickup: int, return: int}
     */
    private function synchronizeOpenCustodySchedules(
        OperationalCalendarService $calendar,
        AuditService $audit,
        NotificationService $notifications
    ): array {
        return [
            'pickup' => $this->synchronizeOpenCustodyPickups($calendar, $audit, $notifications),
            'return' => $this->synchronizeOpenCustodyDueDates($calendar, $audit, $notifications),
        ];
    }

    private function synchronizeOpenCustodyPickups(
        OperationalCalendarService $calendar,
        AuditService $audit,
        NotificationService $notifications
    ): int {
        $adjusted = 0;

        CustodyTransaction::query()
            ->with(['borrower', 'request.currentVersion', 'lines.requestItem'])
            ->where('status', 'PREPARING_RELEASE')
            ->whereNull('released_at')
            ->whereNull('closed_at')
            ->whereNotNull('scheduled_release_at')
            ->whereNotNull('pickup_expires_at')
            ->whereNull('pickup_expired_at')
            ->each(function (CustodyTransaction $custody) use ($calendar, $audit, $notifications, &$adjusted): void {
                $result = $calendar->synchronizeCustodyPickupSchedule($custody, $audit);

                if (! ($result['changed'] ?? false)) {
                    return;
                }

                $adjusted++;
                $synced = $custody->fresh(['borrower', 'request.currentVersion', 'lines.requestItem']);
                $previousStart = $result['previous_start'];
                $newStart = $result['new_start'];
                $newEnd = $result['new_end'];
                $reason = $result['reason'] ?: 'SPMU operational availability changed.';

                if ($synced->borrower) {
                    $message = ($result['mode'] ?? null) === 'RESCHEDULED' && $newStart && $newEnd
                        ? 'Your pickup schedule for '.$synced->custody_no
                            .' was automatically adjusted because the SPMU Operational Calendar changed. Previous pickup: '
                            .$previousStart?->format('F j, Y g:i A').'. New pickup: '
                            .$newStart->format('F j, Y g:i A').' to '.$newEnd->format('g:i A').'. Reason: '
                            .$reason.' This is not recorded as a missed pickup, your same approved request and reservation remain active, and no reschedule request is required from you.'
                        : 'Your previous pickup schedule for '.$synced->custody_no
                            .' is no longer available because the SPMU Operational Calendar changed. Reason: '
                            .$reason.' No valid replacement pickup window is currently available before the approved return date. This is not recorded as a missed pickup. Your approved request and reservation remain active while SPMU resolves the schedule.';

                    $notifications->send(
                        'PICKUP_SCHEDULED',
                        collect([$synced->borrower]),
                        $message,
                        $synced,
                        ['SYSTEM', 'EMAIL'],
                        ['SYSTEM', 'EMAIL']
                    );
                }

                $actionOfficers = User::query()
                    ->where('account_status', 'ACTIVE')
                    ->where('access_classification', AccessClassification::SpmuOfficer->value)
                    ->get();

                if ($actionOfficers->isNotEmpty()) {
                    $officerMessage = ($result['mode'] ?? null) === 'RESCHEDULED' && $newStart && $newEnd
                        ? 'Operational Calendar adjustment moved pickup for '.$synced->custody_no
                            .' to '.$newStart->format('F j, Y g:i A').'–'.$newEnd->format('g:i A')
                            .'. Keep the same approved request/reservation and prepare for the new window. Do not treat this as a borrower missed pickup.'
                        : 'Operational Calendar adjustment removed the invalid pickup window for '.$synced->custody_no
                            .'. No valid replacement window remains before the approved return date. Review the borrowing dates/calendar and coordinate the next administrative action. Do not treat this as a borrower missed pickup.';

                    $notifications->send(
                        'PICKUP_SCHEDULED',
                        $actionOfficers,
                        $officerMessage,
                        $synced,
                        ['SYSTEM'],
                        ['SYSTEM']
                    );
                }
            });

        return $adjusted;
    }

    private function synchronizeOpenCustodyDueDates(
        OperationalCalendarService $calendar,
        AuditService $audit,
        NotificationService $notifications
    ): int {
        $adjusted = 0;

        CustodyTransaction::query()
            ->with(['borrower', 'lines'])
            ->whereNotNull('due_at')
            ->whereNull('closed_at')
            ->whereIn('status', [
                'PREPARING_RELEASE',
                'ACTIVE',
                'RETURN_PROCESSING',
                'OVERDUE',
                'INCIDENT_OPEN',
                'OBLIGATION_OPEN',
            ])
            ->each(function (CustodyTransaction $custody) use ($calendar, $audit, $notifications, &$adjusted): void {
                $previousDue = $custody->due_at?->copy()->endOfDay();
                $synced = $calendar->synchronizeCustodyDueDate($custody, $audit);
                $effectiveDue = $synced->due_at?->copy()->endOfDay();

                if (! $previousDue || ! $effectiveDue || $previousDue->isSameDay($effectiveDue)) {
                    return;
                }

                /*
                 * Calendar-driven deadlines only move forward automatically.
                 * This protects a borrower from a deadline being shortened
                 * after an extension has already been communicated.
                 */
                if ($effectiveDue->lt($previousDue)) {
                    return;
                }

                $hasOutstandingProperty = $synced->lines->contains(
                    fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
                );
                $stillAwaitingRelease = $synced->status === 'PREPARING_RELEASE';

                /*
                 * Do not send a new return-schedule notice for a transaction
                 * whose physical property is already completely back and that
                 * remains open only for accountability/documentation.
                 */
                if (! $hasOutstandingProperty && ! $stillAwaitingRelease) {
                    return;
                }

                $adjusted++;

                if (! $synced->borrower) {
                    return;
                }

                $profile = $calendar->profile($previousDue);
                $reason = $profile['reason']
                    ?: $synced->due_adjustment_reason
                    ?: 'SPMU return transactions are unavailable on the previous effective return date.';

                $notifications->send(
                    'RETURN_SCHEDULE_ADJUSTED',
                    collect([$synced->borrower]),
                    'Your return schedule for '.$synced->custody_no
                        .' was adjusted because SPMU return transactions are unavailable on '
                        .$previousDue->format('F j, Y').'. Effective return date: '
                        .$effectiveDue->format('F j, Y').'. Reason: '.$reason
                        .' This calendar adjustment will not be treated as a late return.',
                    $synced,
                    ['SYSTEM', 'EMAIL'],
                    ['SYSTEM', 'EMAIL']
                );
            });

        return $adjusted;
    }

    /** @param array{pickup: int, return: int} $changes */
    private function calendarUpdateStatus(string $prefix, array $changes): string
    {
        $pickupAdjusted = (int) ($changes['pickup'] ?? 0);
        $returnAdjusted = (int) ($changes['return'] ?? 0);

        if ($pickupAdjusted < 1 && $returnAdjusted < 1) {
            return $prefix.' No active pickup or return schedule changed.';
        }

        $parts = [];

        if ($pickupAdjusted > 0) {
            $parts[] = $pickupAdjusted.' active pickup '
                .($pickupAdjusted === 1 ? 'schedule was' : 'schedules were')
                .' reconciled with the new calendar';
        }

        if ($returnAdjusted > 0) {
            $parts[] = $returnAdjusted.' active return '
                .($returnAdjusted === 1 ? 'deadline was' : 'deadlines were')
                .' moved to the next available Return schedule';
        }

        return $prefix.' '.ucfirst(implode(' and ', $parts)).'. Affected users were notified where applicable.';
    }

    private function termNameFor(
        string $termCode
    ): string {
        return match ($termCode) {
            'FIRST_SEMESTER' =>
                '1st Semester',

            'SECOND_SEMESTER' =>
                '2nd Semester',

            'SUMMER_MIDYEAR' =>
                'Summer / Midyear',
        };
    }

    private function authorizeHead(
        Request $request
    ): void {
        abort_unless(
            $request->user()->access_classification
                === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may configure operational policies.'
        );
    }
}
