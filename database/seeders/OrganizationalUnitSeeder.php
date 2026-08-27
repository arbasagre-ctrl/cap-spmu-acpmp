<?php

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use Illuminate\Database\Seeder;

class OrganizationalUnitSeeder extends Seeder
{
    public function run(): void
    {
        $institution = OrganizationalUnit::query()->firstOrCreate(
            ['unit_code' => 'CSPC'],
            [
                'unit_name' => 'Camarines Sur Polytechnic Colleges',
                'unit_type' => 'INSTITUTION',
                'active' => true,
            ],
        );

        foreach ($this->operationalUnits() as $code => $name) {
            OrganizationalUnit::query()->firstOrCreate(
                ['unit_code' => $code],
                [
                    'parent_unit_id' => $institution->id,
                    'unit_name' => $name,
                    'unit_type' => 'OPERATIONAL_UNIT',
                    'active' => true,
                ],
            );
        }

        /*
         * GSU and VPAF are physical request-letter signatories only.
         * They are not active system portals or account-assignment units.
         */
        OrganizationalUnit::query()
            ->whereIn('unit_code', ['GSU', 'VPAF', 'LAUNDRY'])
            ->update(['active' => false]);

        foreach ($this->borrowerColleges() as $code => $name) {
            OrganizationalUnit::query()->firstOrCreate(
                ['unit_code' => $code],
                [
                    'parent_unit_id' => $institution->id,
                    'unit_name' => $name,
                    'unit_type' => 'ACADEMIC_UNIT',
                    'active' => true,
                ],
            );
        }
    }

    /** @return array<string, string> */
    private function operationalUnits(): array
    {
        return [
            'SPMU' => 'Supply and Property Management Unit',
            'ICTU' => 'Information and Communications Technology Unit',
        ];
    }

    /** @return array<string, string> */
    private function borrowerColleges(): array
    {
        return [
            'CHS' => 'College of Health and Sciences',
            'CEA' => 'College of Engineering and Architecture',
            'CTHBM' => 'College of Tourism, Hospitality and Business Management',
            'CCS' => 'College of Computer Studies',
            'CAS' => 'College of Arts and Sciences',
            'CTDE' => 'College of Technological Developmental Education',
        ];
    }
}
