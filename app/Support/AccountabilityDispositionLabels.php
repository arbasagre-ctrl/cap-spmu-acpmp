<?php

namespace App\Support;

use App\Models\Incident;

/**
 * Single source of truth for the "Compliance Verification" sub-label (spec
 * Section J's stage-4 sub-status text), so CustodyTransaction,
 * BorrowerObligationService, NotificationService, and the Blade views never
 * independently drift on how official_disposition maps to display text.
 */
class AccountabilityDispositionLabels
{
    public const LABELS = [
        'MONETARY_SETTLEMENT' => 'Receipt Pending',
        'REPAIR' => 'Repair Verification Pending',
        'REPLACEMENT' => 'Replacement Verification Pending',
        'RETURN_RECOVERY' => 'Recovery Verification Pending',
        'OTHER' => 'Verification Pending',
    ];

    public const DISPOSITION_LABELS = [
        'MONETARY_SETTLEMENT' => 'Monetary Settlement',
        'REPAIR' => 'Repair',
        'REPLACEMENT' => 'Replacement',
        'RETURN_RECOVERY' => 'Return / Recovery',
        'OTHER' => 'Other',
    ];

    public static function officialDispositionLabel(?string $disposition): ?string
    {
        return $disposition ? (self::DISPOSITION_LABELS[strtoupper($disposition)] ?? null) : null;
    }

    /**
     * The full "Compliance Verification — X" text for an incident currently
     * sitting at RSLDDP_COMPLIANCE_VERIFICATION. Returns the plain stage
     * label for any other status.
     */
    public static function subStatusLabel(Incident $incident): string
    {
        $stage = StatusLabels::label($incident->status) ?: $incident->status;

        if ($incident->status !== 'RSLDDP_COMPLIANCE_VERIFICATION') {
            return $stage;
        }

        $sub = self::LABELS[strtoupper((string) $incident->official_disposition)] ?? null;

        return $sub ? "{$stage} — {$sub}" : $stage;
    }
}
