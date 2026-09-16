<?php

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use Illuminate\Database\Seeder;

/**
 * Seeds the one authoritative CSPC organizational-unit hierarchy.
 *
 * Runtime forms, requests, reports, and analytics query
 * organizational_units; this seed definition is only the idempotent bootstrap
 * and migration source for that table.  It must not be copied into another
 * runtime catalogue.
 */
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

        /** @var array<string, int> $ids */
        $ids = ['CSPC' => (int) $institution->id];

        foreach ($this->hierarchy() as $unit) {
            $parentId = $ids[$unit['parent']] ?? OrganizationalUnit::query()
                ->where('unit_code', $unit['parent'])
                ->value('id');

            if (! $parentId) {
                throw new \LogicException("Organizational parent [{$unit['parent']}] was not seeded.");
            }

            $record = OrganizationalUnit::query()->updateOrCreate(
                ['unit_code' => $unit['code']],
                [
                    'parent_unit_id' => $parentId,
                    'unit_name' => $unit['name'],
                    'unit_type' => $unit['type'],
                    'active' => true,
                ],
            );

            $ids[$unit['code']] = (int) $record->id;
        }

        /*
         * These records have no unambiguous place in the approved final
         * active hierarchy.  They are retained by ID for existing users,
         * requests, and audit/history records but removed from new choices.
         *
         * Includes both the original pre-finalization office codes (first
         * group) and legacy duplicate codes that predate the codes above and
         * would otherwise sit alongside their finalized replacement as a
         * second, redundant active selection (second group, e.g. legacy
         * 'GS' / 'Graduate School' duplicating finalized 'GRADSCHOOL' /
         * 'Graduate School (GS)'). 'GSU' and 'VPAF' match no row in current
         * data and are kept here only because a historical dataset may still
         * carry them.
         */
        OrganizationalUnit::query()
            ->whereIn('unit_code', [
                'OVPAF',
                'OVPAA',
                'OVPRIC',
                'PPDO',
                'SECURITY',
                'GSU',
                'VPAF',
                'LAUNDRY',
                'LAO',
                'CAO',
                'HRMDU',
                'RFOIU',
                'HSU',
                'SAO',
                'CASH',
                'MIS',
                'NICM',
                'ICTRAM',
                'BAC',
                'GS',
                'BUHI',
            ])
            ->update(['active' => false]);
    }

    /**
     * Ordered parent-before-child master data for the existing
     * organizational_units table.
     *
     * @return list<array{code:string,parent:string,name:string,type:string}>
     */
    private function hierarchy(): array
    {
        return [
            // The three persisted classifications; labels are user-facing.
            ['code' => 'ADMINISTRATION', 'parent' => 'CSPC', 'name' => 'Administrative', 'type' => OrganizationalUnit::TYPE_CLASSIFICATION],
            ['code' => 'ACADEMIC', 'parent' => 'CSPC', 'name' => 'Academic', 'type' => OrganizationalUnit::TYPE_CLASSIFICATION],
            ['code' => 'RESEARCH_INNOVATION_COLLABORATION', 'parent' => 'CSPC', 'name' => 'Research, Innovation, & Collaboration', 'type' => OrganizationalUnit::TYPE_CLASSIFICATION],

            // Administrative branch context.
            ['code' => 'OPRES', 'parent' => 'ADMINISTRATION', 'name' => 'Office of the President', 'type' => OrganizationalUnit::TYPE_BRANCH],
            ['code' => 'ADMIN_FINANCE', 'parent' => 'ADMINISTRATION', 'name' => 'Administrative & Finance Division', 'type' => OrganizationalUnit::TYPE_BRANCH],
            ['code' => 'CHIEF_ADMIN_OFFICE', 'parent' => 'ADMIN_FINANCE', 'name' => 'Chief Administrative Office', 'type' => OrganizationalUnit::TYPE_BRANCH],
            ['code' => 'SUPERVISING_ADMIN_OFFICE', 'parent' => 'ADMIN_FINANCE', 'name' => 'Supervising Administrative Office', 'type' => OrganizationalUnit::TYPE_BRANCH],

            // Administrative selectable Office / Unit leaves.
            ['code' => 'BOARDSEC', 'parent' => 'OPRES', 'name' => 'Board Secretary', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'IAU', 'parent' => 'OPRES', 'name' => 'Internal Audit Unit (IAU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'IPDU', 'parent' => 'OPRES', 'name' => 'Institutional Planning and Development Unit (IPDU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'CIRL', 'parent' => 'OPRES', 'name' => 'Center for International Relations, and Linkages (CIRL)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'LEGAL', 'parent' => 'OPRES', 'name' => 'Legal Affairs Unit (LAU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'HRMO', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Human Resource Management and Development Unit (HRMDU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'RECORDS', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Records and Freedom of Information Unit (RFOIU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'MEDICAL', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Health Services Unit (HSU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'GEN_SERVICES', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'General Services Unit (GSU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'SPMU', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Supply and Property Management Unit (SPMU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'ACCOUNTING', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Accounting Unit', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'BUDGET', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Budget Unit', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'CASHIER', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Cash Unit', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'PROCUREMENT', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Procurement Unit', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'PMU', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Project Management Unit (PMU)', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'BUHI_ADMIN', 'parent' => 'CHIEF_ADMIN_OFFICE', 'name' => 'Buhi Campus - Admin', 'type' => 'ADMINISTRATIVE_UNIT'],
            ['code' => 'ICTU', 'parent' => 'SUPERVISING_ADMIN_OFFICE', 'name' => 'Information and Communications Technology Unit (ICTU)', 'type' => 'ADMINISTRATIVE_UNIT'],

            // Academic branch context.
            ['code' => 'COLLEGES', 'parent' => 'ACADEMIC', 'name' => 'Colleges', 'type' => OrganizationalUnit::TYPE_BRANCH],
            ['code' => 'ACAD_RELATED', 'parent' => 'ACADEMIC', 'name' => 'ACAD Related', 'type' => OrganizationalUnit::TYPE_BRANCH],

            // Academic selectable Office / College / Unit leaves.
            ['code' => 'GRADSCHOOL', 'parent' => 'COLLEGES', 'name' => 'Graduate School (GS)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CEA', 'parent' => 'COLLEGES', 'name' => 'College of Engineering and Architecture (CEA)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CAS', 'parent' => 'COLLEGES', 'name' => 'College of Arts and Sciences (CAS)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CTDE', 'parent' => 'COLLEGES', 'name' => 'College of Technological and Developmental Education (CTDE)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CCS', 'parent' => 'COLLEGES', 'name' => 'College of Computer Studies (CCS)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CHS', 'parent' => 'COLLEGES', 'name' => 'College of Health Sciences (CHS)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CTHBM', 'parent' => 'COLLEGES', 'name' => 'College of Tourism, Hospitality, and Business Management (CTHBM)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'BUHI_CAMPUS', 'parent' => 'ACAD_RELATED', 'name' => 'Buhi Campus', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'IAAU', 'parent' => 'ACAD_RELATED', 'name' => 'Information and Alumni Affairs Unit (IAAU)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'BROADCAST_CENTER', 'parent' => 'ACAD_RELATED', 'name' => 'Broadcast Center (BC)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CHRE', 'parent' => 'ACAD_RELATED', 'name' => 'Center for Human Rights Education (CHRE)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CQA', 'parent' => 'ACAD_RELATED', 'name' => 'Center for Quality Assurance (CQA)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CGAD', 'parent' => 'ACAD_RELATED', 'name' => 'Center for Gender and Development (CGAD)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'REGISTRAR', 'parent' => 'ACAD_RELATED', 'name' => 'Student Registration and Records (SRR)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'STA', 'parent' => 'ACAD_RELATED', 'name' => 'Student Testing and Admission (STA)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'LIBRARY', 'parent' => 'ACAD_RELATED', 'name' => 'Learning Resources and Development Services (LRDS)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'ACCESS', 'parent' => 'ACAD_RELATED', 'name' => 'Academic Center for Continuing Enrichment for Students (ACCESS)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'COMPETENCY_ASSESSMENT_TESDA', 'parent' => 'ACAD_RELATED', 'name' => 'Competency Assessment Center / TESDA', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'NSTP', 'parent' => 'ACAD_RELATED', 'name' => 'National Service Training Program (NSTP)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'STUDENT_AFFAIRS', 'parent' => 'ACAD_RELATED', 'name' => 'Student Affairs and Services (SAS)', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'GUIDANCE', 'parent' => 'ACAD_RELATED', 'name' => 'Guidance and Counseling', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'SPORTS_DEVELOPMENT', 'parent' => 'ACAD_RELATED', 'name' => 'Sports Development', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CULTURAL_AFFAIRS', 'parent' => 'ACAD_RELATED', 'name' => 'Cultural Affairs', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'CAFETERIA_FOOD_SERVICES', 'parent' => 'ACAD_RELATED', 'name' => 'Cafeteria / Food Services', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'STUDENT_PUBLICATION', 'parent' => 'ACAD_RELATED', 'name' => 'Student Publication', 'type' => 'ACADEMIC_UNIT'],
            ['code' => 'STUDENT_ORGANIZATION', 'parent' => 'ACAD_RELATED', 'name' => 'Student Organization', 'type' => 'ACADEMIC_UNIT'],

            // Research, Innovation, & Collaboration selectable leaves.
            ['code' => 'RDSO', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Research Development Services Office (RDSO)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'ECSO', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Extension and Community Services Office (ECSO)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'PAXS', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Production and Auxilliary Services Office (PAxSO)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'TECHTRO', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Technology Transfer Office (TechTrO)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'CRCA', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Center for Rinconada Culture and Arts (CRCA)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'RICES', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Rinconada Center for Environmental Sustainability (RiCeS)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'AIRCODE', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'AI Research Center for Community Development (AIRCoDe)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'CFTSF', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Center for Futures Thinking and Strategic Foresight (CFTSF)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'CFEST', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Center for Future and Energy and Sustainable Technology (CREST)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'CRIS3P', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Center for Research in Integrative, Social and Special Sciences and Policy (CRIS3P)', 'type' => 'RESEARCH_UNIT'],
            ['code' => 'REB', 'parent' => 'RESEARCH_INNOVATION_COLLABORATION', 'name' => 'Research Ethics Board (REB)', 'type' => 'RESEARCH_UNIT'],
        ];
    }
}
