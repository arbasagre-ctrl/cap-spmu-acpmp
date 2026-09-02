<?php

namespace App\Support;

/**
 * The institution's canonical division and office/unit structure.
 *
 * This is the single source of truth for the values a borrowing request may
 * carry in request_versions.division_code and request_versions.office_unit.
 * The request form writes them, and Analytics reads them back, so both must
 * agree on the same list and the same labels.
 *
 * Codes are stored in the database and must never change. Labels are display
 * text only and are safe to reword.
 *
 * Research, Innovation and Collaboration is a peer division, not a subset of
 * Academic or Administrative. No rule anywhere in the system folds it into
 * either of the other two, so it is reported on its own throughout.
 */
final class OrganizationalStructure
{
    /**
     * Division code => display label, in reporting display order.
     *
     * @var array<string, string>
     */
    public const DIVISIONS = [
        'ACADEMIC' => 'Academic',
        'ADMINISTRATION' => 'Administrative',
        'RESEARCH_INNOVATION_COLLABORATION' => 'Research, Innovation and Collaboration',
    ];

    /**
     * Short labels for places where the full division name will not fit, such
     * as chart axes and narrow table headers.
     *
     * @var array<string, string>
     */
    public const SHORT_LABELS = [
        'ACADEMIC' => 'Academic',
        'ADMINISTRATION' => 'Administrative',
        'RESEARCH_INNOVATION_COLLABORATION' => 'Research & Innovation',
    ];

    /**
     * Every selectable office, college and unit, grouped by division.
     *
     * The order is the order the borrowing request form presents, so moving an
     * entry changes what borrowers see. Entries are matched against stored
     * office_unit values, so renaming one orphans historical requests.
     *
     * @return array<string, list<string>>
     */
    public static function unitsByDivision(): array
    {
        return [
            'ADMINISTRATION' => [
                'Office of the President',
                'Office of the Vice President for Administration and Finance',
                'Office of the Vice President for Academic Affairs',
                'Office of the Vice President for Research, Innovation and Collaboration',
                'Internal Audit Unit',
                'Legal Affairs Office',
                'Institutional Planning and Development Unit',
                'Board Secretary',
                'Human Resource Management Office',
                'Budget Office',
                'Accounting Office',
                "Cashier's Office",
                'Procurement Office',
                'Supply and Property Management Unit',
                'General Services',
                'Physical Planning and Development Office',
                'Records Management / College Archives',
                'Safety and Security Services',
                "Registrar's Office",
                'Library',
                'Guidance and Counseling Office',
                'Student Affairs and Services',
                'Medical and Dental Services',
                'Center for International Relations and Linkages',
            ],
            'ACADEMIC' => [
                'Graduate School',
                'College of Arts and Sciences',
                'College of Computer Studies',
                'College of Engineering and Architecture',
                'College of Health and Sciences',
                'College of Technological Developmental Education',
                'College of Tourism, Hospitality and Business Management',
            ],
            'RESEARCH_INNOVATION_COLLABORATION' => [
                'Research and Development Services Office (RDSO)',
                'Extension and Community Services Office (ECSO)',
                'Production and Auxiliary Services (PAxS)',
                'Technology Transfer Office (TechTro)',
                'AI Research Center for Community Development (AIRCoDe)',
                'Center for Future Energy and Sustainable Technology (CFEST)',
                'Center for Future Thinking and Strategic Foresight (CFTSF)',
                'Center for Research in Integrative, Social and Special Sciences and Policy (CRIS3P)',
                'Center for Rinconada Culture and Arts (CRCA)',
                'Rinconada Center for Environmental Sustainability (RiCES)',
                'Research Ethics Board',
            ],
        ];
    }

    /**
     * Division codes accepted by validation, in storage order.
     *
     * @return list<string>
     */
    public static function divisionCodes(): array
    {
        return array_keys(self::unitsByDivision());
    }

    /** The display label for a stored division code. */
    public static function label(?string $code): string
    {
        return self::DIVISIONS[(string) $code] ?? (string) $code;
    }

    /** The short display label for a stored division code. */
    public static function shortLabel(?string $code): string
    {
        return self::SHORT_LABELS[(string) $code] ?? self::label($code);
    }

    /**
     * Reverse-lookup: which division contains this office/unit name?
     *
     * Used to prefill a new request from the borrower's home unit. Matching is
     * case-insensitive because profile data is entered by hand.
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
