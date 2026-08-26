<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Models\BorrowerRestriction;
use App\Models\BorrowerViolation;
use App\Models\CustodyTransaction;
use App\Models\ReturnTransaction;
use App\Models\Sanction;
use App\Models\SanctionRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PolicyService
{
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
        $actualDate = $return->received_at?->toDateString();

        if ($dueDate && $actualDate && $actualDate > $dueDate) {
            $reasons[] = 'LATE_RETURN';
        }

        if ($return->lines->contains(fn ($line) => strtoupper((string) $line->condition_code) !== 'FINE')) {
            $reasons[] = 'SLDDP_ACCOUNTABILITY';
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
                    'expected_return_date' => $dueDate,
                    'actual_return_date' => $actualDate,
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

            if ($sanctionCode === 'BORROWING_SUSPENSION' && ! $effectiveTo) {
                throw ValidationException::withMessages([
                    'effective_to' => 'Set the suspension end date for a borrowing suspension.',
                ]);
            }

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



            $effectiveFrom = now();
            $sanctionEffectiveTo = $effectiveTo
                ? CarbonImmutable::parse($effectiveTo)->endOfDay()
                : null;

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
                'sanction_rule_id' => $configuredRule && $configuredRule->sanction_code === $sanctionCode ? $configuredRule->id : null,
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
                ]
            );

            return $sanction;
        }, 3);
    }

}
