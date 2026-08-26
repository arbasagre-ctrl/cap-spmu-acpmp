<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Models\SanctionRule;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PolicyController extends Controller
{
    public function index(Request $request): View
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
        ]);

        $rule = SanctionRule::query()->firstOrNew([
            'offense_no' => $offenseNo,
        ]);

        $before = $rule->exists ? $rule->toArray() : null;

        $rule->fill([
            'sanction_code' => $data['sanction_code'],
            'sanction_label' => trim($data['sanction_label']),
            'duration_mode' => 'MANUAL',
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
            ->to(route('policies.index').'#sanction-rules')
            ->with('status', "{$offenseNo} offense sanction rule updated.");
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
            'Only the SPMU Head may configure academic periods.'
        );
    }
}
