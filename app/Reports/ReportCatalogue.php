<?php

namespace App\Reports;

use App\Support\OrganizationalStructure;

/**
 * The catalogue of formal report types.
 *
 * This is the single source of truth for what Reports can produce: the report
 * list in the builder, the filters each report accepts, the validation rules
 * applied to a submitted filter set, and the CSV/print filename. Nothing else
 * in the module may invent a report key or a filter key.
 *
 * A report entry carries a `builder` once it has been migrated onto the
 * normalized ReportDataset pipeline. Entries with a null builder are still
 * served by the legacy controller path; they remain fully working and are
 * listed here so the catalogue always describes the complete module.
 */
final class ReportCatalogue
{
    /**
     * Filter definitions shared across reports.
     *
     * Each filter declares how it is rendered and what values validation will
     * accept. Options given as a callable are resolved at request time so
     * inventory-backed lists stay current without a deploy.
     *
     * @return array<string, array{label:string, type:string, placeholder?:string, options?:callable|array<string,string>, depends_on?:string}>
     */
    public static function filterDefinitions(): array
    {
        return [
            'division' => [
                'label' => 'Division',
                'type' => 'select',
                'placeholder' => 'All divisions',
                'options' => fn (): array => OrganizationalStructure::DIVISIONS,
            ],

            /*
             * Unit is dependent on Division: the option list is the units of
             * the chosen division, and a unit from another division is
             * rejected by validation rather than silently returning nothing.
             */
            'unit' => [
                'label' => 'Office / Unit',
                'type' => 'select',
                'placeholder' => 'All units',
                'depends_on' => 'division',
                'options' => function (?string $division): array {
                    $byDivision = OrganizationalStructure::unitsByDivision();

                    $units = $division !== null && isset($byDivision[$division])
                        ? $byDivision[$division]
                        : array_merge(...array_values($byDivision));

                    return array_combine($units, $units);
                },
            ],

            'status' => [
                'label' => 'Status',
                'type' => 'select',
                'placeholder' => 'All statuses',
                'options' => fn (): array => [
                    'DRAFT' => 'Draft',
                    'UNDER_SPMU' => 'Under SPMU Review',
                    'RETURNED_FOR_REVISION' => 'Returned for Revision',
                    'APPROVED_READY_FOR_RELEASE' => 'Approved / Ready for Release',
                    'PREPARING_RELEASE' => 'Preparing Release',
                    'ACTIVE' => 'Released / On Custody',
                    'RETURN_PROCESSING' => 'Return Processing',
                    'OVERDUE' => 'Overdue',
                    'INCIDENT_OPEN' => 'Incident Open',
                    'OBLIGATION_OPEN' => 'Obligation Open',
                    'COMPLETED' => 'Completed',
                    'REJECTED' => 'Rejected',
                    'CANCELLED' => 'Cancelled',
                    'EXPIRED' => 'Expired',
                ],
            ],

            'verification' => [
                'label' => 'AO Verification',
                'type' => 'select',
                'placeholder' => 'All verification states',
                'options' => fn (): array => [
                    'NOT_REQUIRED' => 'Not required (on-campus)',
                    'PENDING' => 'Awaiting verification',
                    'VERIFIED' => 'Verified',
                    'RETURNED_FOR_REVISION' => 'Returned for correction',
                ],
            ],

            'decision' => [
                'label' => 'Admin Decision',
                'type' => 'select',
                'placeholder' => 'All decisions',
                'options' => fn (): array => [
                    'PENDING' => 'Awaiting decision',
                    'APPROVED' => 'Approved',
                    'RETURNED_FOR_REVISION' => 'Returned for correction',
                    'REJECTED' => 'Denied',
                ],
            ],

            'custody_status' => [
                'label' => 'Custody Status',
                'type' => 'select',
                'placeholder' => 'All custody states',
                'options' => fn (): array => [
                    'PREPARING_RELEASE' => 'Preparing Release',
                    'ACTIVE' => 'Released / On Custody',
                    'RETURN_PROCESSING' => 'Return Processing',
                    'PARTIALLY_RETURNED' => 'Partially Returned',
                    'OVERDUE' => 'Overdue',
                    'INCIDENT_OPEN' => 'Incident Open',
                    'OBLIGATION_OPEN' => 'Obligation Open',
                    'CLOSED' => 'Completed / Closed',
                ],
            ],

            'return_status' => [
                'label' => 'Return Status',
                'type' => 'select',
                'placeholder' => 'All return states',
                'options' => fn (): array => [
                    'RETURNED_ON_TIME' => 'Returned on time',
                    'RETURNED_LATE' => 'Returned late',
                    'CURRENTLY_OVERDUE' => 'Currently overdue',
                    'ON_CUSTODY' => 'Still on custody',
                ],
            ],

            'open_accountability' => [
                'label' => 'Open Accountability',
                'type' => 'select',
                'placeholder' => 'Any',
                'options' => fn (): array => [
                    'OPEN' => 'With open accountability',
                    'NONE' => 'No open accountability',
                ],
            ],

            'equipment' => [
                'label' => 'Equipment',
                'type' => 'select',
                'placeholder' => 'All equipment',
                'options' => fn (): array => \App\Models\InventoryItem::query()
                    ->where('active', true)
                    ->orderBy('unique_description')
                    ->pluck('unique_description', 'id')
                    ->map(fn ($description): string => (string) $description)
                    ->all(),
            ],

            'linen' => [
                'label' => 'Linen',
                'type' => 'select',
                'placeholder' => 'All linen',
                'options' => fn (): array => \App\Models\InventoryItem::query()
                    ->where('active', true)
                    ->where('laundry_required', true)
                    ->orderBy('unique_description')
                    ->pluck('unique_description', 'id')
                    ->map(fn ($description): string => (string) $description)
                    ->all(),
            ],

            'laundry_status' => [
                'label' => 'Laundry Status',
                'type' => 'select',
                'placeholder' => 'All laundry states',
                'options' => fn (): array => [
                    'FOR_LAUNDRY' => 'Awaiting laundry return',
                    'TURNED_OVER_TO_LAUNDRY' => 'Internal laundry pending',
                    'LAUNDRY_COMPLETED' => 'Laundry completed',
                ],
            ],

            'gate_pass_status' => [
                'label' => 'Gate Pass Status',
                'type' => 'select',
                'placeholder' => 'All gate pass states',
                'options' => fn (): array => [
                    'NOT_ISSUED' => 'Not issued',
                    'PENDING' => 'Pending',
                    'READY_FOR_PRINTING' => 'Ready for printing',
                    'VERIFIED' => 'Verified',
                ],
            ],

            'student_activity' => [
                'label' => 'Student Activity',
                'type' => 'select',
                'placeholder' => 'Any',
                'options' => fn (): array => [
                    'YES' => 'Student activity',
                    'NO' => 'Not a student activity',
                ],
            ],

            'off_campus' => [
                'label' => 'Off-Campus',
                'type' => 'select',
                'placeholder' => 'Any',
                'options' => fn (): array => [
                    'YES' => 'Off-campus',
                    'NO' => 'On-campus',
                ],
            ],

            'availability_status' => [
                'label' => 'Availability Status',
                'type' => 'select',
                'placeholder' => 'All availability states',
                'options' => fn (): array => [
                    'AVAILABLE' => 'Has available stock',
                    'FULLY_COMMITTED' => 'Nothing available',
                    'ALLOCATED' => 'Has allocated stock',
                    'ON_CUSTODY' => 'Has stock on custody',
                    'IN_LAUNDRY' => 'Has stock in laundry',
                    'UNSERVICEABLE' => 'Has unserviceable stock',
                ],
            ],
        ];
    }

    /**
     * Every report type the module offers, in builder display order.
     *
     * @return array<string, array{label:string, description:string, filters:list<string>, builder:?class-string<ReportBuilder>, export:?string, legacy:bool}>
     */
    /**
     * The eight formal report types, grouped as the builder presents them.
     *
     * Every entry is backed by an authoritative data source; no report type
     * exists here that the workflow tables cannot answer.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'borrowing' => [
                'label' => 'Borrowing Activity Report',
                'group' => 'Borrowing',
                'description' => 'Detailed borrowing request records for the selected period.',
                'filters' => ['division', 'unit', 'status'],
                'builder' => Builders\BorrowingActivityReport::class,
                'export' => 'borrowing',
                'empty' => 'No borrowing records matched the selected reporting criteria.',
            ],

            'approval' => [
                'label' => 'Approval & Decision Report',
                'group' => 'Borrowing',
                'description' => 'Action Officer verification and SPMU Head decision for each request, read from the authoritative approval steps.',
                'filters' => ['division', 'unit', 'verification', 'decision'],
                'builder' => Builders\ApprovalDecisionReport::class,
                'export' => 'approval',
                'empty' => 'No approval or decision records matched the selected reporting criteria.',
            ],

            'custody' => [
                'label' => 'Release & Custody Report',
                'group' => 'Custody & Return',
                'description' => 'Official records of physically released assets and their current custody state. Approved-but-unreleased requests are not counted as released.',
                'filters' => ['division', 'unit', 'equipment', 'custody_status'],
                'builder' => Builders\ReleaseCustodyReport::class,
                'export' => 'custody',
                'empty' => 'No released/custody records were found for this period.',
            ],

            'returns' => [
                'label' => 'Return & Accountability Report',
                'group' => 'Custody & Return',
                'description' => 'Return lifecycle and unresolved obligations, one record per custody transaction.',
                'filters' => ['division', 'unit', 'return_status', 'open_accountability'],
                'builder' => Builders\ReturnAccountabilityReport::class,
                'export' => 'returns',
                'empty' => 'No return/accountability records matched the selected filters.',
            ],

            'inventory' => [
                'label' => 'Inventory Status Report',
                'group' => 'Assets',
                'description' => 'Official operational inventory snapshot taken from the authoritative inventory service.',
                'filters' => ['equipment', 'availability_status'],
                'builder' => Builders\InventoryStatusReport::class,
                'export' => 'inventory',
                'empty' => 'No inventory records matched the selected filters.',
            ],

            'utilization' => [
                'label' => 'Equipment Utilization Report',
                'group' => 'Assets',
                'description' => 'Actual physical usage measured from released custody quantity, never from approval alone.',
                'filters' => ['division', 'unit', 'equipment'],
                'builder' => Builders\EquipmentUtilizationReport::class,
                'export' => 'utilization',
                'empty' => 'No equipment utilization was recorded for this period.',
            ],

            'laundry' => [
                'label' => 'Laundry Operations Report',
                'group' => 'Special Operations',
                'description' => 'Linen traceability from issuance through laundry receipt, completion, and return to available stock.',
                'filters' => ['linen', 'laundry_status'],
                'builder' => Builders\LaundryOperationsReport::class,
                'export' => 'laundry',
                'empty' => 'No laundry operations matched the selected period.',
            ],

            'gate-pass' => [
                'label' => 'Off-Campus / Gate Pass Report',
                'group' => 'Special Operations',
                'description' => 'Off-campus transactions with verification, decision, Permission to Conduct where the Student Activity rule applies, and gate pass state.',
                'filters' => ['division', 'unit', 'gate_pass_status', 'student_activity', 'off_campus'],
                'builder' => Builders\OffCampusGatePassReport::class,
                'export' => 'gate-pass',
                'empty' => 'No Gate Pass records matched the selected criteria.',
            ],
        ];
    }

    /**
     * Report keys that were consolidated into the formal eight.
     *
     * The reports these keys named were merged, not discarded: their records
     * and columns live on in the destination report. The aliases exist so a
     * bookmark or an old link still opens the report that now carries that
     * information instead of failing.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            /*
             * Request Status listed request records under their operational
             * status, which Borrowing Activity now reports with a status
             * filter and a status column.
             */
            'requests' => 'borrowing',

            /* Received-to-decision timing is a column on Approval & Decision. */
            'review-turnaround' => 'approval',

            /*
             * Overdue cases and incident/violation records are columns and
             * filters on Return & Accountability, which reports one row per
             * custody transaction so a case cannot be counted twice.
             */
            'overdue' => 'returns',
            'accountability' => 'returns',

            /*
             * The compliance percentage is an Analytics interpretation; the
             * detailed return records behind it are here.
             */
            'compliance' => 'returns',

            /*
             * Borrower ranking is an Analytics summary; the detailed
             * borrowing records by borrower are here.
             */
            'borrowers' => 'borrowing',
        ];
    }

    /**
     * Report types grouped for the builder select, in display order.
     *
     * @return array<string, array<string, string>>  group => [key => label]
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::all() as $key => $definition) {
            $grouped[$definition['group']][$key] = $definition['label'];
        }

        return $grouped;
    }

    /** Report keys in display order. */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::all());
    }

    /** The default report shown when none is chosen or the key is unknown. */
    public static function defaultKey(): string
    {
        return 'borrowing';
    }

    /**
     * Normalize a submitted report key to one the catalogue actually offers.
     */
    public static function resolveKey(?string $key): string
    {
        if (self::has($key)) {
            return (string) $key;
        }

        /* A consolidated report opens the report that absorbed it. */
        return self::aliases()[(string) $key] ?? self::defaultKey();
    }

    /**
     * @return array{label:string, description:string, filters:list<string>, builder:?class-string<ReportBuilder>, export:?string, legacy:bool}
     */
    public static function definition(string $key): array
    {
        return self::all()[self::resolveKey($key)];
    }

    /** Whether this report is served by the normalized dataset pipeline. */
    public static function isMigrated(string $key): bool
    {
        return (self::definition($key)['builder'] ?? null) !== null;
    }

    /** The empty-state sentence shown when a report returns no records. */
    public static function emptyMessage(string $key): string
    {
        return self::definition($key)['empty']
            ?? 'No records matched the selected reporting criteria.';
    }

    /**
     * The filter definitions that apply to one report, in display order.
     *
     * Reports only ever show their own filters; irrelevant controls are never
     * rendered, which is what keeps the builder simple.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function filtersFor(string $key): array
    {
        $definitions = self::filterDefinitions();
        $filters = [];

        foreach (self::definition($key)['filters'] as $filterKey) {
            if (isset($definitions[$filterKey])) {
                $filters[$filterKey] = $definitions[$filterKey];
            }
        }

        return $filters;
    }
}
