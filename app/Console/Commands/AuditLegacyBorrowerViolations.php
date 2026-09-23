<?php

namespace App\Console\Commands;

use App\Models\BorrowerViolation;
use App\Models\Incident;
use Illuminate\Console\Command;

/**
 * Read-only. Classifies every existing borrower_violations row into exactly
 * three buckets ahead of the violation_source backfill: deterministic
 * PROPERTY_ACCOUNTABILITY, deterministic LATE_RETURN, or mixed/unknown
 * (printed for manual review, never auto-classified). Writes nothing. The
 * migration's own backfill applies only the same two deterministic rules
 * printed here.
 */
class AuditLegacyBorrowerViolations extends Command
{
    protected $signature = 'spmu:audit-legacy-borrower-violations';

    protected $description = 'Read-only: classify existing BorrowerViolation rows for the violation_source backfill';

    private const PROPERTY_REASONS = ['DAMAGED', 'LOST_MISSING', 'STOLEN', 'DESTROYED'];

    public function handle(): int
    {
        $violations = BorrowerViolation::query()
            ->with(['detectedBy', 'reviewedBy'])
            ->orderBy('id')
            ->get();

        $custodyIdsWithIncident = Incident::query()
            ->pluck('custody_transaction_id')
            ->unique()
            ->flip();

        $property = collect();
        $lateReturn = collect();
        $ambiguous = collect();

        foreach ($violations as $violation) {
            $details = is_array($violation->details_json) ? $violation->details_json : [];
            $reasons = is_array($details['reasons'] ?? null)
                ? array_map(fn ($reason) => strtoupper((string) $reason), $details['reasons'])
                : [];
            $incidentIds = is_array($details['incident_ids'] ?? null) ? $details['incident_ids'] : [];

            $hasPropertyReason = array_intersect($reasons, self::PROPERTY_REASONS) !== [];
            $hasLinkedIncident = $incidentIds !== []
                || ($violation->custody_transaction_id !== null
                    && $custodyIdsWithIncident->has($violation->custody_transaction_id));

            $row = [
                'id' => $violation->id,
                'custody_transaction_id' => $violation->custody_transaction_id,
                'violation_code' => $violation->violation_code,
                'status' => $violation->status,
                'reasons' => $reasons,
                'created_at' => $violation->created_at?->toDateTimeString(),
                'detected_by' => $violation->detectedBy?->full_name,
                'reviewed_by' => $violation->reviewedBy?->full_name,
                'linked_incident' => $hasLinkedIncident ? 'yes' : 'no',
            ];

            if ($hasPropertyReason || $hasLinkedIncident) {
                $property->push($row);

                continue;
            }

            if ($reasons === ['LATE_RETURN']) {
                $lateReturn->push($row);

                continue;
            }

            $ambiguous->push($row);
        }

        $this->info("Deterministic -> PROPERTY_ACCOUNTABILITY: {$property->count()} row(s)");
        $this->info("Deterministic -> LATE_RETURN: {$lateReturn->count()} row(s)");
        $this->warn("Mixed / unknown / insufficient evidence (NOT auto-classified, stays NULL): {$ambiguous->count()} row(s)");

        if ($ambiguous->isNotEmpty()) {
            $this->newLine();
            $this->warn('Rows requiring manual review before any migration touches violation_source:');
            $this->table(
                ['id', 'custody_transaction_id', 'status', 'reasons', 'created_at', 'detected_by', 'reviewed_by'],
                $ambiguous->map(fn ($row) => [
                    $row['id'],
                    $row['custody_transaction_id'],
                    $row['status'],
                    implode(',', $row['reasons']) ?: '(none)',
                    $row['created_at'],
                    $row['detected_by'] ?? '(none)',
                    $row['reviewed_by'] ?? '(none)',
                ])->all()
            );
        }

        return self::SUCCESS;
    }
}
