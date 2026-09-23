<?php

namespace App\Console\Commands;

use App\Models\Incident;
use Illuminate\Console\Command;

/**
 * Read-only. Counts Incident rows currently sitting in the statuses the
 * Accountability rework retires from every new write path, so the real
 * live-data blast radius is known before Phase 2 ships, instead of assumed.
 * Writes nothing.
 */
class AuditLegacyAccountabilityStatuses extends Command
{
    protected $signature = 'spmu:audit-legacy-accountability-statuses';

    protected $description = 'Read-only: count Incident rows in statuses retired by the Accountability rework';

    private const LEGACY_STATUSES = [
        'FOR_BILLING',
        'BILLING_PENDING',
        'COMPLIANCE_REQUIRED',
        'COMPLIANCE_RSLDDP_PENDING',
        'RSLDDP_FOR_ACCOUNTING_PROCESSING',
        'RSLDDP_PAYMENT_REQUIRED',
    ];

    public function handle(): int
    {
        $counts = Incident::query()
            ->whereIn('status', self::LEGACY_STATUSES)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->info('Incident rows currently sitting in a legacy-only Accountability status:');

        foreach (self::LEGACY_STATUSES as $status) {
            $this->line(sprintf('  %-35s %d', $status, $counts[$status] ?? 0));
        }

        $total = (int) $counts->sum();
        $this->newLine();
        $this->info("Total: {$total} row(s). These are not migrated or force-transitioned by this command; ".
            'they continue through the unmodified legacy code paths.');

        return self::SUCCESS;
    }
}
