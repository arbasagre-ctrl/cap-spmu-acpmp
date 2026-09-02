<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Stage 2: the Approval & Decision Report.
 *
 * Every expectation here is written against approval_steps, because that is
 * the authoritative approval history. A request whose status column says one
 * thing and whose steps say another must be reported from the steps.
 *
 * The workflow being reported (not changed) is the existing two-step SPMU
 * routing: off-campus requests open an Action Officer verification step at
 * sequence 1, on-campus requests go straight to the Head at sequence 2.
 */
class ReportApprovalDecisionTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'APPR',
            'unit_name' => 'Approval Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_on_campus_request_reports_verification_as_not_required(): void
    {
        $this->request(
            division: 'ACADEMIC',
            unit: 'College of Computer Studies',
            offCampus: false,
            headDecision: null
        );

        $row = $this->generate()->rows->first();

        $this->assertSame('Not required (on-campus)', $row['verification']);
        $this->assertSame('Awaiting decision', $row['decision']);
    }

    public function test_off_campus_request_awaiting_verification_is_reported_as_pending(): void
    {
        $this->request(
            division: 'ACADEMIC',
            unit: 'College of Computer Studies',
            offCampus: true,
            verification: 'RECEIVED',
            headDecision: null
        );

        $row = $this->generate()->rows->first();

        $this->assertSame('Awaiting verification', $row['verification']);
        $this->assertSame('Awaiting decision', $row['decision']);
    }

    public function test_verified_and_approved_request_reports_both_actors_and_times(): void
    {
        $officer = $this->staff('Officer Uno');
        $head = $this->staff('Head Dos');

        $this->request(
            division: 'ACADEMIC',
            unit: 'College of Computer Studies',
            offCampus: true,
            verification: 'VERIFIED',
            verifier: $officer,
            headDecision: 'APPROVED',
            approver: $head,
            status: RequestStatus::ApprovedReadyForRelease
        );

        $row = $this->generate()->rows->first();

        $this->assertSame('Verified', $row['verification']);
        $this->assertSame('Officer Uno', $row['verified_by']);
        $this->assertSame('Approved', $row['decision']);
        $this->assertSame('Head Dos', $row['decided_by']);
        $this->assertNotSame('', $row['decided_at']);
    }

    public function test_returned_for_correction_and_denied_decisions_are_distinguished(): void
    {
        $this->request(
            division: 'ACADEMIC',
            unit: 'Graduate School',
            offCampus: false,
            headDecision: 'RETURNED_FOR_REVISION',
            remarks: 'Dates do not match the signed letter.'
        );

        $this->request(
            division: 'ADMINISTRATION',
            unit: 'Library',
            offCampus: false,
            headDecision: 'REJECTED',
            remarks: 'Activity did not push through.'
        );

        $dataset = $this->generate();

        $labels = $dataset->rows->pluck('decision')->sort()->values()->all();

        $this->assertSame(['Denied', 'Returned for correction'], $labels);
        $this->assertSame(1, $dataset->summary['Returned for correction']);
        $this->assertSame(1, $dataset->summary['Denied']);
    }

    public function test_decision_is_read_from_approval_steps_not_the_request_status_column(): void
    {
        /*
         * A request that was approved and has since moved on still carries
         * APPROVED in its steps. The status column is deliberately different
         * here so a builder reading it would fail this test.
         */
        $this->request(
            division: 'ACADEMIC',
            unit: 'College of Computer Studies',
            offCampus: false,
            headDecision: 'APPROVED',
            status: RequestStatus::UnderSpmu
        );

        $this->assertSame('Approved', $this->generate()->rows->first()['decision']);
    }

    public function test_review_turnaround_is_measured_from_received_to_decided(): void
    {
        $created = $this->from->copy()->addDays(2);

        $this->request(
            division: 'ACADEMIC',
            unit: 'College of Computer Studies',
            offCampus: false,
            headDecision: 'APPROVED',
            createdAt: $created,
            receivedAt: $created->copy(),
            decidedAt: $created->copy()->addHours(2)->addMinutes(30)
        );

        $this->assertSame('2h 30m', $this->generate()->rows->first()['turnaround']);
    }

    public function test_report_filters_by_division_unit_verification_and_decision(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', false, headDecision: 'APPROVED');
        $this->request('ACADEMIC', 'Graduate School', false, headDecision: 'REJECTED');
        $this->request('ADMINISTRATION', 'Library', true, verification: 'VERIFIED', headDecision: 'APPROVED');

        $this->assertSame(2, $this->generate(['division' => 'ACADEMIC'])->count());

        $this->assertSame(
            1,
            $this->generate([
                'division' => 'ACADEMIC',
                'unit' => 'Graduate School',
            ])->count()
        );

        $this->assertSame(2, $this->generate(['decision' => 'APPROVED'])->count());
        $this->assertSame(1, $this->generate(['decision' => 'REJECTED'])->count());
        $this->assertSame(1, $this->generate(['verification' => 'VERIFIED'])->count());
        $this->assertSame(2, $this->generate(['verification' => 'NOT_REQUIRED'])->count());
    }

    public function test_requests_outside_the_period_are_excluded(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', false, headDecision: 'APPROVED');
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            false,
            headDecision: 'APPROVED',
            createdAt: $this->from->copy()->subMonth()
        );

        $this->assertSame(1, $this->generate()->count());
    }

    public function test_screen_and_csv_records_are_identical(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', false, headDecision: 'APPROVED');
        $this->request('ADMINISTRATION', 'Library', true, verification: 'VERIFIED', headDecision: 'APPROVED');

        $service = app(ReportService::class);
        $dataset = $this->generate();

        $handle = fopen('php://memory', 'r+');
        $service->writeCsv($dataset, $handle);
        rewind($handle);

        $csv = [];
        while (($line = fgetcsv($handle)) !== false) {
            $csv[] = $line;
        }
        fclose($handle);

        $headerIndex = null;
        foreach ($csv as $index => $line) {
            if ($line === $dataset->columnLabels()) {
                $headerIndex = $index;

                break;
            }
        }

        $this->assertNotNull($headerIndex);
        $this->assertSame($dataset->records(), array_values(array_slice($csv, $headerIndex + 1)));
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function generate(array $input = []): \App\Reports\ReportDataset
    {
        return app(ReportService::class)->generate(
            ReportFilters::fromRequest(
                Request::create('/reports', 'GET', $input),
                'approval',
                $this->from,
                $this->to,
                'month'
            )
        );
    }

    private function staff(string $name): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
            'full_name' => $name,
        ]);
    }

    private function request(
        string $division,
        string $unit,
        bool $offCampus,
        ?string $verification = null,
        ?User $verifier = null,
        ?string $headDecision = null,
        ?User $approver = null,
        RequestStatus $status = RequestStatus::UnderSpmu,
        ?Carbon $createdAt = null,
        ?Carbon $receivedAt = null,
        ?Carbon $decidedAt = null,
        ?string $remarks = null,
    ): BorrowingRequest {
        $createdAt ??= $this->from->copy()->addDays(2);

        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.$createdAt->format('YmdHis').'-'.fake()->unique()->numberBetween(1000, 99999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Approval fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'off_campus' => $offCampus,
            'submitted_at' => $createdAt,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        /* Sequence 1 exists only for off-campus requests. */
        if ($offCampus && $verification !== null) {
            ApprovalStep::query()->create([
                'request_version_id' => $version->id,
                'approver_user_id' => $verifier?->id,
                'stage_code' => ApprovalStage::Spmu,
                'sequence_no' => 1,
                'received_at' => $createdAt,
                'decision' => $verification,
                'decided_at' => in_array($verification, ['PENDING', 'RECEIVED'], true)
                    ? null
                    : $createdAt->copy()->addHour(),
            ]);
        }

        /* Sequence 2 is the Head decision step; it always exists once filed. */
        ApprovalStep::query()->create([
            'request_version_id' => $version->id,
            'approver_user_id' => $headDecision === null ? null : $approver?->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => 2,
            'received_at' => $receivedAt ?: $createdAt,
            'decision' => $headDecision ?: 'RECEIVED',
            'decided_at' => $headDecision === null
                ? null
                : ($decidedAt ?: $createdAt->copy()->addHours(3)),
            'remarks' => $remarks,
        ]);

        return $request;
    }
}
