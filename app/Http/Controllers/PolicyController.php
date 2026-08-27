<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Models\CustodyTransaction;
use App\Models\OperationalDateException;
use App\Models\OperationalWeeklySchedule;
use App\Models\SanctionRule;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\OperationalCalendarService;
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
        OperationalCalendarService $calendar
    ): RedirectResponse {
        $this->authorizeHead($request);
        abort_unless($weekday >= 1 && $weekday <= 7, 404);

        $data = $request->validate([
            'is_open' => ['required', 'boolean'],
            'accepts_requests' => ['required', 'boolean'],
            'allows_pickup' => ['required', 'boolean'],
            'allows_return' => ['required', 'boolean'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
        ]);

        if ($data['open_time'] && $data['close_time'] && $data['close_time'] <= $data['open_time']) {
            return back()->withErrors([
                'close_time' => 'Closing time must be later than opening time.',
            ]);
        }

        if (! (bool) $data['is_open']) {
            $data['accepts_requests'] = false;
            $data['allows_pickup'] = false;
            $data['allows_return'] = false;
            $data['open_time'] = null;
            $data['close_time'] = null;
        }

        $rule = OperationalWeeklySchedule::query()->firstOrNew(['weekday' => $weekday]);
        $before = $rule->exists ? $rule->toArray() : null;
        $rule->fill($data + ['configured_by_user_id' => $request->user()->id])->save();

        $audit->record('OPERATIONAL_WEEKLY_SCHEDULE_UPDATED', $rule, before: $before, after: $rule->fresh()->toArray());
        $this->synchronizeOpenCustodyDueDates($calendar, $audit);

        return redirect()->to(route('policies.index', ['section' => 'transaction-schedule']).'#transaction-schedule')
            ->with('status', 'Weekly operational schedule updated. Existing open custody return dates were re-evaluated.');
    }

    public function storeDateException(
        Request $request,
        AuditService $audit,
        OperationalCalendarService $calendar
    ): RedirectResponse {
        $this->authorizeHead($request);

        $data = $request->validate([
            'exception_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['OPEN', 'CLOSED'])],
            'accepts_requests' => ['required', 'boolean'],
            'allows_pickup' => ['required', 'boolean'],
            'allows_return' => ['required', 'boolean'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($data['open_time'] && $data['close_time'] && $data['close_time'] <= $data['open_time']) {
            return back()->withErrors([
                'close_time' => 'Closing time must be later than opening time.',
            ]);
        }

        if ($data['status'] === 'CLOSED') {
            $data['accepts_requests'] = false;
            $data['allows_pickup'] = false;
            $data['allows_return'] = false;
            $data['open_time'] = null;
            $data['close_time'] = null;
        } elseif (! $data['accepts_requests'] && ! $data['allows_pickup'] && ! $data['allows_return']) {
            // A special working day should be useful by default. The Head can
            // still uncheck individual transaction types before saving.
            $data['accepts_requests'] = true;
            $data['allows_pickup'] = true;
            $data['allows_return'] = true;
        }

        $exception = OperationalDateException::query()->firstOrNew([
            'exception_date' => $data['exception_date'],
        ]);
        $before = $exception->exists ? $exception->toArray() : null;
        $exception->fill($data + ['configured_by_user_id' => $request->user()->id])->save();

        $audit->record('OPERATIONAL_DATE_EXCEPTION_SAVED', $exception, before: $before, after: $exception->fresh()->toArray());
        $this->synchronizeOpenCustodyDueDates($calendar, $audit);

        return redirect()->to(route('policies.index', ['section' => 'special-dates']).'#special-dates')
            ->with('status', 'Special operational date saved. Affected open custody return dates were re-evaluated.');
    }

    public function destroyDateException(
        Request $request,
        OperationalDateException $exception,
        AuditService $audit,
        OperationalCalendarService $calendar
    ): RedirectResponse {
        $this->authorizeHead($request);

        $before = $exception->toArray();
        $audit->record('OPERATIONAL_DATE_EXCEPTION_REMOVED', $exception, before: $before);
        $exception->delete();
        $this->synchronizeOpenCustodyDueDates($calendar, $audit);

        return redirect()->to(route('policies.index', ['section' => 'special-dates']).'#special-dates')
            ->with('status', 'Special operational date removed. Affected return dates were recalculated from the weekly schedule.');
    }

    private function synchronizeOpenCustodyDueDates(
        OperationalCalendarService $calendar,
        AuditService $audit
    ): void {
        CustodyTransaction::query()
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
            ->each(function (CustodyTransaction $custody) use ($calendar, $audit): void {
                $calendar->synchronizeCustodyDueDate($custody, $audit);
            });
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
