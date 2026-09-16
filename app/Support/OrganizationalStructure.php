<?php

namespace App\Support;

use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use Illuminate\Support\Facades\Schema;

/**
 * Read-model helpers for the organizational_units master hierarchy.
 *
 * This is intentionally not a second office catalogue.  Active choices come
 * from OrganizationalUnit; immutable request-version values are appended only
 * so reports can still filter and display historical transactions after a
 * master-data rename or deactivation.
 */
final class OrganizationalStructure
{
    /** @return array<string, string> */
    public static function divisions(): array
    {
        $labels = OrganizationalUnit::classificationLabels();

        if (! Schema::hasTable('organizational_units')) {
            return [];
        }

        $activeCodes = OrganizationalUnit::query()
            ->where('active', true)
            ->where('unit_type', OrganizationalUnit::TYPE_CLASSIFICATION)
            ->pluck('unit_code')
            ->all();

        return array_filter(
            $labels,
            static fn (string $label, string $code): bool => in_array($code, $activeCodes, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return list<string> */
    public static function divisionCodes(): array
    {
        return array_keys(self::divisions());
    }

    /**
     * Active selectable leaves grouped by their resolved classification,
     * followed by distinct immutable historical snapshot names.
     *
     * @return array<string, list<string>>
     */
    public static function unitsByDivision(): array
    {
        $units = array_map(
            static fn (): array => [],
            self::divisions(),
        );

        if ($units === []) {
            return [];
        }

        foreach (OrganizationalUnit::query()->activeSelectable()->orderBy('unit_name')->get() as $unit) {
            $divisionCode = $unit->divisionCode();

            if ($divisionCode !== null && array_key_exists($divisionCode, $units)) {
                $units[$divisionCode][] = $unit->unit_name;
            }
        }

        /*
         * request_versions stores the affiliation as an intentional historic
         * snapshot.  It has no unit FK by design, so retaining these names in
         * filter choices is the compatible path for records filed before a
         * later master-data rename/deactivation.
         */
        if (Schema::hasTable('request_versions')) {
            RequestVersion::query()
                ->select('division_code', 'office_unit')
                ->whereNotNull('division_code')
                ->whereNotNull('office_unit')
                ->distinct()
                ->orderBy('office_unit')
                ->get()
                ->each(function (RequestVersion $version) use (&$units): void {
                    $divisionCode = (string) $version->division_code;
                    $officeUnit = trim((string) $version->office_unit);

                    if ($officeUnit !== '' && array_key_exists($divisionCode, $units)) {
                        $units[$divisionCode][] = $officeUnit;
                    }
                });
        }

        foreach ($units as $divisionCode => $names) {
            $unique = [];

            foreach ($names as $name) {
                $key = mb_strtolower($name);
                $unique[$key] ??= $name;
            }

            $units[$divisionCode] = array_values($unique);
            sort($units[$divisionCode], SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $units;
    }

    /** The official display label for a persisted classification code. */
    public static function label(?string $code): string
    {
        return OrganizationalUnit::classificationLabels()[(string) $code]
            ?? 'Unclassified organizational record';
    }

    /**
     * Narrow screens use the official label too: abbreviated codes are not a
     * user-facing organizational classification.
     */
    public static function shortLabel(?string $code): string
    {
        return self::label($code);
    }

    /**
     * Reverse lookup for report filter client-side cascading.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function divisionAndUnitFor(?string $unitName): array
    {
        $unitName = trim((string) $unitName);

        if ($unitName === '') {
            return [null, null];
        }

        foreach (self::unitsByDivision() as $division => $units) {
            foreach ($units as $unit) {
                if (strcasecmp($unit, $unitName) === 0) {
                    return [$division, $unit];
                }
            }
        }

        return [null, null];
    }
}
