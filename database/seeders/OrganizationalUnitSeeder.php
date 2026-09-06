<?php

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use Illuminate\Database\Seeder;

class OrganizationalUnitSeeder extends Seeder
{
    public function run(): void
    {
        $institution = OrganizationalUnit::query()->updateOrCreate(
            ['unit_code' => 'CSPC'],
            [
                'parent_unit_id' => null,
                'unit_name' => 'Camarines Sur Polytechnic Colleges',
                'unit_type' => 'INSTITUTION',
                'active' => true,
            ],
        );

        foreach ($this->units() as $unit) {
            OrganizationalUnit::query()->updateOrCreate(
                ['unit_code' => $unit['code']],
                [
                    'parent_unit_id' => $institution->id,
                    'unit_name' => $unit['name'],
                    'unit_type' => $unit['type'],
                    'active' => true,
                ],
            );
        }

        /*
         * Legacy GSU/VPAF/Laundry records are physical/offline context only.
         * They are not assignable portal organizational units.
         */
        OrganizationalUnit::query()
            ->whereIn('unit_code', ['GSU', 'VPAF', 'LAUNDRY'])
            ->update(['active' => false]);
    }

    /** @return list<array{code:string,name:string,type:string}> */
    private function units(): array
    {
        return [
            // Administrative
            ['code' => 'OPRES', 'name' => 'Office of the President', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'OVPAF', 'name' => 'Office of the Vice President for Administration and Finance', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'OVPAA', 'name' => 'Office of the Vice President for Academic Affairs', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'OVPRIC', 'name' => 'Office of the Vice President for Research, Innovation and Collaboration', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'IAU', 'name' => 'Internal Audit Unit', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'LEGAL', 'name' => 'Legal Affairs Office', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'IPDU', 'name' => 'Institutional Planning and Development Unit', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'BOARDSEC', 'name' => 'Board Secretary', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'HRMO', 'name' => 'Human Resource Management Office', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'BUDGET', 'name' => 'Budget Office', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'ACCOUNTING', 'name' => 'Accounting Office', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'CASHIER', 'name' => "Cashier's Office", 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'PROCUREMENT', 'name' => 'Procurement Office', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'SPMU', 'name' => 'Supply and Property Management Unit', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'ICTU', 'name' => 'Information and Communications Technology Unit', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'GEN_SERVICES', 'name' => 'General Services', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'PPDO', 'name' => 'Physical Planning and Development Office', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'RECORDS', 'name' => 'Records Management / College Archives', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'SECURITY', 'name' => 'Safety and Security Services', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'REGISTRAR', 'name' => "Registrar's Office", 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'LIBRARY', 'name' => 'Library', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'GUIDANCE', 'name' => 'Guidance and Counseling Office', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'STUDENT_AFFAIRS', 'name' => 'Student Affairs and Services', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'MEDICAL', 'name' => 'Medical and Dental Services', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'CIRL', 'name' => 'Center for International Relations and Linkages', 'type' => 'ADMINISTRATIVE_UNIT'],

            // Academic
            ['code' => 'GRADSCHOOL', 'name' => 'Graduate School', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CAS', 'name' => 'College of Arts and Sciences', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CCS', 'name' => 'College of Computer Studies', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CEA', 'name' => 'College of Engineering and Architecture', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CHS', 'name' => 'College of Health and Sciences', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CTDE', 'name' => 'College of Technological Developmental Education', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CTHBM', 'name' => 'College of Tourism, Hospitality and Business Management', 'type' => 'ACADEMIC_UNIT'],

            // Research, Innovation and Collaboration (RIC)
            ['code' => 'RDSO', 'name' => 'Research and Development Services Office (RDSO)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'ECSO', 'name' => 'Extension and Community Services Office (ECSO)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'PAXS', 'name' => 'Production and Auxiliary Services (PAxS)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'TECHTRO', 'name' => 'Technology Transfer Office (TechTro)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'AIRCODE', 'name' => 'AI Research Center for Community Development (AIRCoDe)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'CFEST', 'name' => 'Center for Future Energy and Sustainable Technology (CFEST)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'CFTSF', 'name' => 'Center for Future Thinking and Strategic Foresight (CFTSF)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'CRIS3P', 'name' => 'Center for Research in Integrative, Social and Special Sciences and Policy (CRIS3P)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'CRCA', 'name' => 'Center for Rinconada Culture and Arts (CRCA)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'RICES', 'name' => 'Rinconada Center for Environmental Sustainability (RiCES)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'REB', 'name' => 'Research Ethics Board', 'type' => 'RESEARCH_UNIT'],
        ];
    }
}
