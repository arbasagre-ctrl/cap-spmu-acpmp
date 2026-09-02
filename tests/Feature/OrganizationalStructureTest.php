<?php

namespace Tests\Feature;

use App\Services\AnalyticsService;
use App\Support\OrganizationalStructure;
use Tests\TestCase;

/**
 * The division and office/unit structure is stored on every request version,
 * so it is a data contract rather than presentation. These tests pin the parts
 * that would silently orphan historical requests if they drifted.
 */
class OrganizationalStructureTest extends TestCase
{
    public function test_division_codes_are_the_three_stored_values(): void
    {
        $this->assertSame(
            ['ADMINISTRATION', 'ACADEMIC', 'RESEARCH_INNOVATION_COLLABORATION'],
            OrganizationalStructure::divisionCodes()
        );
    }

    public function test_research_innovation_collaboration_is_its_own_division(): void
    {
        /*
         * No rule folds Research, Innovation and Collaboration into Academic
         * or Administrative, so it must survive as a peer everywhere it is
         * reported.
         */
        $this->assertArrayHasKey(
            'RESEARCH_INNOVATION_COLLABORATION',
            OrganizationalStructure::DIVISIONS
        );

        $this->assertArrayHasKey(
            'RESEARCH_INNOVATION_COLLABORATION',
            AnalyticsService::DIVISIONS
        );

        $this->assertNotEmpty(
            OrganizationalStructure::unitsByDivision()['RESEARCH_INNOVATION_COLLABORATION']
        );
    }

    public function test_analytics_and_the_request_form_read_the_same_divisions(): void
    {
        $this->assertSame(
            array_keys(OrganizationalStructure::DIVISIONS),
            array_keys(AnalyticsService::DIVISIONS),
            'Analytics must report exactly the divisions a request can be filed under.'
        );
    }

    public function test_every_division_carries_at_least_one_selectable_unit(): void
    {
        foreach (OrganizationalStructure::unitsByDivision() as $division => $units) {
            $this->assertNotEmpty($units, "{$division} has no selectable unit.");
            $this->assertSame(
                array_unique($units),
                $units,
                "{$division} lists a duplicate unit."
            );
        }
    }

    public function test_no_unit_name_belongs_to_two_divisions(): void
    {
        $all = array_merge(...array_values(OrganizationalStructure::unitsByDivision()));

        $this->assertSame(
            count($all),
            count(array_unique($all)),
            'A unit that appears twice would make its division ambiguous in Analytics.'
        );
    }

    public function test_reverse_lookup_finds_the_division_of_a_known_unit(): void
    {
        $units = OrganizationalStructure::unitsByDivision();

        foreach ($units as $division => $unitNames) {
            $sample = $unitNames[0];

            $this->assertSame(
                [$division, $sample],
                OrganizationalStructure::divisionAndUnitFor($sample)
            );

            // Profile data is hand-entered, so matching ignores case.
            $this->assertSame(
                [$division, $sample],
                OrganizationalStructure::divisionAndUnitFor(strtoupper($sample))
            );
        }
    }

    public function test_reverse_lookup_returns_nothing_for_an_unknown_or_blank_unit(): void
    {
        $this->assertSame([null, null], OrganizationalStructure::divisionAndUnitFor(null));
        $this->assertSame([null, null], OrganizationalStructure::divisionAndUnitFor('   '));
        $this->assertSame([null, null], OrganizationalStructure::divisionAndUnitFor('Not A Real Office'));
    }

    public function test_labels_exist_for_every_division_code(): void
    {
        foreach (OrganizationalStructure::divisionCodes() as $code) {
            $this->assertNotSame($code, OrganizationalStructure::label($code));
            $this->assertNotSame('', OrganizationalStructure::shortLabel($code));
        }
    }
}
