<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Models\BorrowerRestriction;
use App\Models\BorrowerViolation;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\ReturnTransaction;
use App\Models\Sanction;
use App\Models\SanctionRule;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PolicyService
{
    public const OFFENSE_APPLICATION_SETTING = 'sanction_offense_application_types';

    public const OFFENSE_APPLICATION_TYPES = [
        'LATE_RETURN',
        'DAMAGED',
        'LOST_MISSING',
        'STOLEN',
        'DESTROYED',
    ];

    private const PROPERTY_OFFENSE_TYPES = [
        'DAMAGED',
        'LOST_MISSING',
        'STOLEN',
        'DESTROYED',
    ];

    public function __construct(private AuditService $audit) {}

    public function activePeriodFor(CarbonInterface|string|null $date = null): ?AcademicPeriod
    {
        $date = $date instanceof CarbonInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse($date ?: now());

        return AcademicPeriod::query()
            ->where('status', 'ACTIVE')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * Returns the case types that the institution currently allows the SPMU
     * Head to confirm as an administrative offense. A missing setting keeps
     * the client-approved defaults enabled; an intentionally saved empty list
     * is respected.
     *
     * @return list<string>
     */
    public function offenseApplicationTypes(): array
    {
        $configured = SystemSetting::value(self::OFFENSE_APPLICATION_SETTING, null);

        if ($configured === null) {
            return self::OFFENSE_APPLICATION_TYPES;
        }

        if (! is_array($configured)) {
            return self::OFFENSE_APPLICATION_TYPES;
        }

        $normalized = array_map(
            fn ($type) => strtoupper(trim((string) $type)),
            $configured
        );

        return array_values(array_intersect(self::OFFENSE_APPLICATION_TYPES, array_unique($normalized)));
    }

    /**
     * Create one reviewable violation record per borrowing transaction.
     * A transaction may contain several findings, but it does not silently
     * increment the offense count more than once.
     */
    public function detectFromConfirmedReturn(
        CustodyTransaction $custody,
        ReturnTransaction $return,
        User $spmu
    ): ?BorrowerViolation {
        $return->loadMissing('lines');

        $reasons = [];
        $dueDate = $custody->due_at?->toDateString();
        $originalDueDate = $custody->original_due_at?->toDateString();
        $actualDate = $return->received_at?->toDateString();

        if ($dueDate && $actualDate && $actualDate > $dueDate) {
            $reasons[] = 'LATE_RETURN';
        }

        foreach ($return->lines as $line) {
            $condition = strtoupper((string) $line->condition_code);
            if ($condition === 'FINE') {
                continue;
            }

            $normalized = $this->normalizeOffenseApplicationType($condition);
            if ($normalized) {
                $reasons[] = $normalized;
            }
        }

        if ($reasons === []) {
            return null;
        }

        $period = $this->activePeriodFor($return->received_at);

        $violation = BorrowerViolation::query()->updateOrCreate(
            [
                'custody_transaction_id' => $custody->id,
                'violation_code' => 'BORROWING_VIOLATION',
            ],
            [
                'borrower_user_id' => $custody->borrower_user_id,
                'academic_period_id' => $period?->id,
                'details_json' => [
                    'reasons' => array_values(array_unique($reasons)),
                    'original_expected_return_date' => $originalDueDate ?: $dueDate,
                    'effective_return_date' => $dueDate,
                    'actual_return_date' => $actualDate,
                    'return_date_adjusted' => $originalDueDate && $originalDueDate !== $dueDate,
                ],
                'status' => 'PENDING_REVIEW',
                'detected_at' => now(),
                'detected_by_user_id' => $spmu->id,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'review_remarks' => null,
            ]
        );

        $this->audit->record(
            'BORROWING_VIOLATION_DETECTED',
            $violation,
            after: $violation->details_json
        );

        return $violation;
    }

    /**
     * @return list<string>
     */
    public function incidentOffenseTypes(Incident $incident): array
    {
        $incident->loadMissing('lines');

        $types = [];
        $candidates = [$incident->incident_type];

        foreach ($incident->lines as $line) {
            $candidates[] = $line->observed_condition;
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeOffenseApplicationType((string) $candidate);
            if ($normalized && in_array($normalized, self::PROPERTY_OFFENSE_TYPES, true)) {
                $types[] = $normalized;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Build the information shown beside the SPMU Head decision. This is a
     * preview only; no offense is counted until the Head explicitly checks
     * the confirmation box and submits the case decision.
     *
     * @return array<string, mixed>
     */
    public function incidentOffensePreview(Incident $incident): array
    {
        $incident->loadMissing(['borrower', 'lines']);

        $enabledTypes = $this->offenseApplicationTypes();
        $detectedTypes = $this->incidentOffenseTypes($incident);
        $eligibleTypes = array_values(array_intersect($detectedTypes, $enabledTypes));
        $period = $this->activePeriodFor($incident->reported_at ?: now());

        $existingViolation = BorrowerViolation::query()
            ->with(['sanction', 'academicPeriod'])
            ->where('custody_transaction_id', $incident->custody_transaction_id)
            ->where('violation_code', 'BORROWING_VIOLATION')
            ->first();

        $existingSanction = $existingViolation?->sanction;

        $confirmedBefore = 0;
        if ($period) {
            $confirmedBefore = BorrowerViolation::query()
                ->where('borrower_user_id', $incident->borrower_user_id)
                ->where('academic_period_id', $period->id)
                ->where('status', 'CONFIRMED')
                ->when(
                    $existingViolation,
                    fn ($query) => $query->whereKeyNot($existingViolation->id)
                )
                ->count();
        }

        $offenseNo = $existingSanction?->offense_no ?: ($confirmedBefore + 1);
        $rule = SanctionRule::query()
            ->where('offense_no', min(max(1, (int) $offenseNo), 3))
            ->where('status', 'ACTIVE')
            ->latest('effective_from')
            ->first();

        $restrictionPreview = 'No sanction suspension configured.';
        $effectiveTo = null;

        if ($rule?->sanction_code === 'BORROWING_SUSPENSION') {
            $effectiveFrom = CarbonImmutable::instance(now());
            $effectiveTo = match ($rule->duration_mode) {
                'MONTHS' => $effectiveFrom
                    ->addMonthsNoOverflow(max(1, (int) ($rule->duration_value ?: 1)))
                    ->endOfDay(),
                'UNTIL_ACADEMIC_PERIOD_END' => $period?->end_date
                    ? CarbonImmutable::instance($period->end_date)->endOfDay()
                    : null,
                default => null,
            };

            $restrictionPreview = match ($rule->duration_mode) {
                'MONTHS' => $effectiveTo
                    ? 'Borrowing suspension until '.$effectiveTo->format('d M Y').'.'
                    : 'Fixed-month borrowing suspension.',
                'UNTIL_ACADEMIC_PERIOD_END' => $effectiveTo
                    ? 'Borrowing suspension until '.$effectiveTo->format('d M Y').' (semester end).'
                    : 'Borrowing suspension until the active semester ends.',
                'MANUAL_DATE' => 'Borrowing suspension; the Head must set the end date during administrative review.',
                default => 'Borrowing suspension.',
            };
        }

        $canConfirm = $eligibleTypes !== []
            && $period !== null
            && ! in_array($existingViolation?->status, ['DISMISSED'], true);

        return [
            'enabled_types' => $enabledTypes,
            'detected_types' => $detectedTypes,
            'eligible_types' => $eligibleTypes,
            'is_eligible' => $eligibleTypes !== [],
            'can_confirm' => $canConfirm,
            'academic_period' => $period,
            'academic_period_label' => $period
                ? $period->academic_year.' · '.$period->term_name
                : 'No active academic period',
            'previous_confirmed_offenses' => $existingSanction
                ? max(0, (int) $existingSanction->offense_no - 1)
                : $confirmedBefore,
            'next_offense_no' => (int) $offenseNo,
            'next_offense_label' => $this->ordinalOffense((int) $offenseNo),
            'configured_rule' => $rule,
            'configured_sanction_label' => $existingSanction?->sanction_label
                ?: ($rule?->sanction_label ?: 'No active sanction rule configured'),
            'restriction_preview' => $existingSanction?->effective_to
                ? 'Existing sanction effective until '.$existingSanction->effective_to->format('d M Y').'.'
                : $restrictionPreview,
            'existing_violation' => $existingViolation,
            'existing_sanction' => $existingSanction,
            'existing_status' => $existingViolation?->status,
        ];
    }

    /**
     * Confirm the property case as the one administrative offense for the
     * borrowing transaction. If a pending late/property violation already
     * exists for the same custody record, it is reused so one borrowing does
     * not silently become multiple offenses.
     */
    public function confirmIncidentOffense(
        Incident $incident,
        User $spmuHead,
        ?string $remarks = null
    ): Sanction {
        abort_unless(
            $spmuHead->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may confirm administrative offenses.'
        );

        $preview = $this->incidentOffensePreview($incident);

        if (! $preview['is_eligible']) {
            throw ValidationException::withMessages([
                'count_as_offense' => 'This property finding is not enabled as an applicable offense type under Operational Configuration → Sanction Rules.',
            ]);
        }

        if (! $preview['academic_period']) {
            throw ValidationException::withMessages([
                'academic_period' => 'Configure and activate the applicable academic period before confirming this case as an offense.',
            ]);
        }

        $violation = BorrowerViolation::query()
            ->where('custody_transaction_id', $incident->custody_transaction_id)
            ->where('violation_code', 'BORROWING_VIOLATION')
            ->first();

        if ($violation?->status === 'CONFIRMED') {
            $sanction = $violation->sanction()->first();
            if ($sanction) {
                return $sanction;
            }

            throw ValidationException::withMessages([
                'count_as_offense' => 'This borrowing transaction is already counted as a confirmed offense.',
            ]);
        }

        if ($violation?->status === 'DISMISSED') {
            throw ValidationException::withMessages([
                'count_as_offense' => 'The administrative violation for this borrowing transaction was already dismissed. Review the existing administrative decision instead of counting it again.',
            ]);
        }

        $eligibleTypes = $preview['eligible_types'];
        $existingDetails = is_array($violation?->details_json) ? $violation->details_json : [];
        $existingReasons = is_array($existingDetails['reasons'] ?? null) ? $existingDetails['reasons'] : [];
        $incidentIds = is_array($existingDetails['incident_ids'] ?? null) ? $existingDetails['incident_ids'] : [];

        $details = array_merge($existingDetails, [
            'reasons' => array_values(array_unique(array_merge($existingReasons, $eligibleTypes))),
            'incident_ids' => array_values(array_unique(array_merge($incidentIds, [$incident->id]))),
            'offense_confirmation_source' => 'PROPERTY_ACCOUNTABILITY_HEAD_DECISION',
        ]);

        if (! $violation) {
            $violation = BorrowerViolation::query()->create([
                'borrower_user_id' => $incident->borrower_user_id,
                'custody_transaction_id' => $incident->custody_transaction_id,
                'academic_period_id' => $preview['academic_period']->id,
                'violation_code' => 'BORROWING_VIOLATION',
                'details_json' => $details,
                'status' => 'PENDING_REVIEW',
                'detected_at' => $incident->reported_at ?: now(),
                'detected_by_user_id' => $incident->reported_by_user_id ?: $spmuHead->id,
            ]);
        } else {
            $violation->update([
                'academic_period_id' => $preview['academic_period']->id,
                'details_json' => $details,
            ]);
        }

        $sanction = $this->reviewViolation(
            $violation->fresh(),
            $spmuHead,
            'CONFIRMED',
            $remarks,
            null,
            null,
            null
        );

        if (! $sanction) {
            throw ValidationException::withMessages([
                'count_as_offense' => 'The administrative offense could not be recorded.',
            ]);
        }

        return $sanction;
    }

    /**
     * SPMU Head reviews a detected violation and, when confirmed, records the
     * administrative sanction for that specific case. The configured offense
     * rule supplies the default action; the Head may explicitly override it.
     */
    public function reviewViolation(
        BorrowerViolation $violation,
        User $spmuHead,
        string $decision,
        ?string $remarks = null,
        ?string $sanctionCode = null,
        ?string $customSanctionLabel = null,
        ?string $effectiveTo = null
    ): ?Sanction {
        abort_unless(
            $spmuHead->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may confirm sanctions.'
        );

        $decision = strtoupper($decision);
        if (! in_array($decision, ['CONFIRMED', 'DISMISSED'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Choose CONFIRMED or DISMISSED.',
            ]);
        }

        if ($violation->status !== 'PENDING_REVIEW') {
            throw ValidationException::withMessages([
                'decision' => 'This violation has already been reviewed.',
            ]);
        }

        if ($decision === 'CONFIRMED' && ! $this->violationIsEnabledForOffense($violation)) {
            throw ValidationException::withMessages([
                'decision' => 'This violation type is not enabled under Operational Configuration → Sanction Rules → Offense Application.',
            ]);
        }

        $sanctionCode = $sanctionCode ? strtoupper($sanctionCode) : null;
        $allowedSanctions = [
            'NOTICE' => 'Notice',
            'WRITTEN_REPRIMAND' => 'Written Reprimand',
            'BORROWING_SUSPENSION' => 'Borrowing Suspension',
            'OTHER' => null,
        ];

        if (
            $decision === 'CONFIRMED'
            && $sanctionCode !== null
            && ! array_key_exists($sanctionCode, $allowedSanctions)
        ) {
            throw ValidationException::withMessages([
                'sanction_code' => 'Choose a valid administrative sanction or use the configured offense rule.',
            ]);
        }

        if ($decision === 'CONFIRMED' && $sanctionCode === 'OTHER' && trim((string) $customSanctionLabel) === '') {
            throw ValidationException::withMessages([
                'custom_sanction_label' => 'Enter the administrative action when Other is selected.',
            ]);
        }

        return DB::transaction(function () use (
            $violation,
            $spmuHead,
            $decision,
            $remarks,
            $sanctionCode,
            $customSanctionLabel,
            $effectiveTo,
            $allowedSanctions
        ): ?Sanction {
            $locked = BorrowerViolation::query()->lockForUpdate()->findOrFail($violation->id);

            if ($locked->status !== 'PENDING_REVIEW') {
                throw ValidationException::withMessages([
                    'decision' => 'This violation has already been reviewed.',
                ]);
            }

            if ($decision === 'DISMISSED') {
                $locked->update([
                    'status' => 'DISMISSED',
                    'reviewed_by_user_id' => $spmuHead->id,
                    'reviewed_at' => now(),
                    'review_remarks' => $remarks,
                ]);

                $this->audit->record(
                    'BORROWING_VIOLATION_DISMISSED',
                    $locked,
                    reason: $remarks
                );

                return null;
            }

            $period = $locked->academicPeriod ?: $this->activePeriodFor($locked->detected_at);
            if (! $period) {
                throw ValidationException::withMessages([
                    'academic_period' => 'Configure and activate the applicable academic period before confirming this violation.',
                ]);
            }

            $offenseNo = BorrowerViolation::query()
                ->where('borrower_user_id', $locked->borrower_user_id)
                ->where('academic_period_id', $period->id)
                ->where('status', 'CONFIRMED')
                ->whereKeyNot($locked->id)
                ->count() + 1;

            $configuredRule = SanctionRule::query()
                ->where('offense_no', min($offenseNo, 3))
                ->where('status', 'ACTIVE')
                ->latest('effective_from')
                ->first();

            if ($sanctionCode === null) {
                $sanctionCode = $configuredRule?->sanction_code;
            }

            if (! $sanctionCode || ! array_key_exists($sanctionCode, $allowedSanctions)) {
                throw ValidationException::withMessages([
                    'sanction_code' => 'Configure the applicable offense sanction rule or choose an override for this case.',
                ]);
            }

            $usesConfiguredRule = $configuredRule && $configuredRule->sanction_code === $sanctionCode;

            $sanctionLabel = $sanctionCode === 'OTHER'
                ? trim((string) $customSanctionLabel)
                : ($configuredRule && $configuredRule->sanction_code === $sanctionCode
                    ? $configuredRule->sanction_label
                    : $allowedSanctions[$sanctionCode]);

            if ($sanctionCode === 'OTHER' && $sanctionLabel === '') {
                throw ValidationException::withMessages([
                    'custom_sanction_label' => 'Enter the administrative action when Other is selected.',
                ]);
            }

            $effectiveFrom = CarbonImmutable::instance(now());
            $sanctionEffectiveTo = $effectiveTo
                ? CarbonImmutable::parse($effectiveTo)->endOfDay()
                : null;

            if ($sanctionCode === 'BORROWING_SUSPENSION' && ! $sanctionEffectiveTo && $usesConfiguredRule) {
                $sanctionEffectiveTo = match ($configuredRule->duration_mode) {
                    'MONTHS' => $effectiveFrom
                        ->addMonthsNoOverflow(max(1, (int) ($configuredRule->duration_value ?: 1)))
                        ->endOfDay(),
                    'UNTIL_ACADEMIC_PERIOD_END' => $period->end_date
                        ? CarbonImmutable::instance($period->end_date)->endOfDay()
                        : null,
                    default => null,
                };
            }

            if ($sanctionCode === 'BORROWING_SUSPENSION' && ! $sanctionEffectiveTo) {
                throw ValidationException::withMessages([
                    'effective_to' => 'The configured suspension rule has no usable duration. Configure a duration or enter a manual suspension end date.',
                ]);
            }

            $locked->update([
                'academic_period_id' => $period->id,
                'status' => 'CONFIRMED',
                'reviewed_by_user_id' => $spmuHead->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ]);

            $sanction = Sanction::query()->create([
                'borrower_violation_id' => $locked->id,
                'borrower_user_id' => $locked->borrower_user_id,
                'academic_period_id' => $period->id,
                'sanction_rule_id' => $usesConfiguredRule ? $configuredRule->id : null,
                'offense_no' => $offenseNo,
                'sanction_code' => $sanctionCode,
                'sanction_label' => $sanctionLabel,
                'effective_from' => $effectiveFrom,
                'effective_to' => $sanctionEffectiveTo,
                'status' => 'ACTIVE',
                'confirmed_by_user_id' => $spmuHead->id,
                'confirmed_at' => now(),
                'remarks' => $remarks,
            ]);

            if ($sanctionCode === 'BORROWING_SUSPENSION') {
                BorrowerRestriction::query()->create([
                    'borrower_user_id' => $locked->borrower_user_id,
                    'restriction_type' => 'SANCTION_SUSPENSION',
                    'reason' => $sanctionLabel,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $sanctionEffectiveTo,
                    'status' => 'ACTIVE',
                    'imposed_by_user_id' => $spmuHead->id,
                    'sanction_id' => $sanction->id,
                ]);
            }

            $this->audit->record(
                'SANCTION_CONFIRMED',
                $sanction,
                reason: $remarks,
                after: [
                    'offense_no' => $offenseNo,
                    'academic_period' => $period->term_name,
                    'sanction_code' => $sanctionCode,
                    'sanction_label' => $sanctionLabel,
                    'effective_to' => $sanctionEffectiveTo?->toIso8601String(),
                    'decision_source' => 'SPMU_HEAD_CASE_REVIEW',
                    'configured_rule_applied' => (bool) $usesConfiguredRule,
                    'duration_mode' => $usesConfiguredRule ? $configuredRule->duration_mode : 'MANUAL_OVERRIDE',
                ]
            );

            return $sanction;
        }, 3);
    }

    private function violationIsEnabledForOffense(BorrowerViolation $violation): bool
    {
        $enabled = $this->offenseApplicationTypes();
        $detected = $this->violationOffenseTypes($violation);

        if (in_array('PROPERTY_ACCOUNTABILITY', $detected, true)) {
            return array_intersect($enabled, self::PROPERTY_OFFENSE_TYPES) !== [];
        }

        return array_intersect($enabled, $detected) !== [];
    }

    /**
     * @return list<string>
     */
    private function violationOffenseTypes(BorrowerViolation $violation): array
    {
        $details = is_array($violation->details_json) ? $violation->details_json : [];
        $reasons = is_array($details['reasons'] ?? null) ? $details['reasons'] : [];
        $types = [];

        foreach ($reasons as $reason) {
            $raw = strtoupper(trim((string) $reason));
            if ($raw === 'SLDDP_ACCOUNTABILITY' || $raw === 'PROPERTY_ACCOUNTABILITY') {
                $types[] = 'PROPERTY_ACCOUNTABILITY';
                continue;
            }

            $normalized = $this->normalizeOffenseApplicationType($raw);
            if ($normalized) {
                $types[] = $normalized;
            }
        }

        return array_values(array_unique($types));
    }

    private function normalizeOffenseApplicationType(string $value): ?string
    {
        return match (strtoupper(trim($value))) {
            'LATE_RETURN' => 'LATE_RETURN',
            'DAMAGED', 'DAMAGE' => 'DAMAGED',
            'MISSING', 'LOST', 'LOST_MISSING' => 'LOST_MISSING',
            'STOLEN' => 'STOLEN',
            'DESTROYED' => 'DESTROYED',
            default => null,
        };
    }

    private function ordinalOffense(int $offenseNo): string
    {
        return match ($offenseNo) {
            1 => '1st Offense',
            2 => '2nd Offense',
            3 => '3rd Offense',
            default => $offenseNo.'th Offense (3rd-offense rule applies)',
        };
    }
}
