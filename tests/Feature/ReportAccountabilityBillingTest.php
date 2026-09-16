<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BillingLine;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\OverdueCase;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Services\LateReturnService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReportAccountabilityBillingTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'ACCRPT',
            'unit_name' => 'Accountability Report Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_accountability_report_contains_property_and_confirmed_late_return_cases(): void
    {
        [$propertyCustody, $propertyLine] = $this->custody('ACADEMIC', 'College of Computer Studies', 'Rectangular Table', 10, 10);
        $officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer, 'full_name' => 'Action Officer']);

        $incident = Incident::query()->create([
            'incident_no' => 'INC-ACCT-001',
            'custody_transaction_id' => $propertyCustody->id,
            'borrower_user_id' => $propertyCustody->borrower_user_id,
            'reported_by_user_id' => $officer->id,
            'incident_type' => 'DAMAGED',
            'reported_at' => $this->from->copy()->addDays(5),
            'status' => 'COMPLIANCE_REQUIRED',
        ]);

        IncidentLine::query()->create([
            'incident_id' => $incident->id,
            'custody_line_id' => $propertyLine->id,
            'quantity' => 1,
            'observed_condition' => 'DAMAGED',
            'disposition_state' => 'DAMAGED_MAINTENANCE',
        ]);

        BorrowerRestriction::query()->create([
            'borrower_user_id' => $propertyCustody->borrower_user_id,
            'incident_id' => $incident->id,
            'restriction_type' => 'UNRESOLVED_INCIDENT',
            'reason' => 'Property case remains open.',
            'status' => 'ACTIVE',
            'imposed_by_user_id' => $officer->id,
        ]);

        [$lateCustody] = $this->custody('ADMINISTRATION', 'Library', 'Monoblock Chair', 5, 5);
        $lateCase = OverdueCase::query()->create([
            'custody_transaction_id' => $lateCustody->id,
            'borrower_user_id' => $lateCustody->borrower_user_id,
            'grace_expires_at' => $this->from->copy()->addDays(3),
            'overdue_started_at' => $this->from->copy()->addDays(4),
            'actual_return_date' => $this->from->copy()->addDays(8)->toDateString(),
            'return_date_source' => 'RETURN_INSPECTION',
            'late_days' => 3,
            'offense_level' => 1,
            'rate_snapshot' => 25,
            'accrued_amount' => 75,
            'ao_confirmed_by_user_id' => $officer->id,
            'ao_confirmed_at' => $this->from->copy()->addDays(8),
            'status' => LateReturnService::STATUS_FOR_HEAD_APPROVAL,
        ]);

        $dataset = $this->generate('accountability-cases');

        $this->assertSame(2, $dataset->count());
        $this->assertSame(1, $dataset->rows->where('case_type', 'Property Accountability')->count());
        $this->assertSame(1, $dataset->rows->where('case_type', 'Late Return')->count());

        $property = $dataset->rows->firstWhere('case_reference', $incident->incident_no);
        $this->assertSame('Damaged', $property['finding']);
        $this->assertSame('1', $property['affected_quantity']);
        $this->assertSame('Active', $property['restriction_status']);
        $this->assertSame('Compliance Required', $property['final_outcome']);

        $late = $dataset->rows->firstWhere('custody_no', $lateCustody->custody_no);
        $this->assertSame('Late Return', $late['finding']);
        $this->assertSame('For Head/Admin Decision', $late['final_outcome']);

        $this->assertSame(1, $this->generate('accountability-cases', ['accountability_finding' => 'DAMAGED'])->count());
        $this->assertSame(1, $this->generate('accountability-cases', ['accountability_finding' => 'LATE_RETURN'])->count());
        $this->assertSame(1, $this->generate('accountability-cases', ['accountability_status' => 'COMPLIANCE_REQUIRED'])->count());
    }

    public function test_billing_settlement_report_shows_verified_cashier_payment_and_remaining_balance(): void
    {
        [$custody, $custodyLine] = $this->custody('ACADEMIC', 'College of Computer Studies', 'Rectangular Table', 10, 10);
        $officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer, 'full_name' => 'Action Officer']);
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead, 'full_name' => 'SPMU Head']);

        $incident = Incident::query()->create([
            'incident_no' => 'INC-BILL-001',
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'reported_by_user_id' => $officer->id,
            'incident_type' => 'DAMAGED',
            'reported_at' => $this->from->copy()->addDays(5),
            'status' => 'BILLING_PENDING',
        ]);

        IncidentLine::query()->create([
            'incident_id' => $incident->id,
            'custody_line_id' => $custodyLine->id,
            'quantity' => 1,
            'observed_condition' => 'DAMAGED',
            'disposition_state' => 'DAMAGED_MAINTENANCE',
        ]);

        $billing = BillingStatement::query()->create([
            'billing_no' => 'BILL-ACCT-001',
            'borrower_user_id' => $custody->borrower_user_id,
            'responsible_spmu_user_id' => $head->id,
            'issued_at' => $this->from->copy()->addDays(6),
            'due_at' => $this->from->copy()->addDays(12),
            'total_amount' => 500,
            'status' => 'ISSUED',
        ]);

        BillingLine::query()->create([
            'billing_statement_id' => $billing->id,
            'incident_id' => $incident->id,
            'line_type' => 'PROPERTY_ACCOUNTABILITY',
            'description' => 'Damaged Rectangular Table',
            'basis' => 'Property accountability assessment',
            'amount' => 500,
        ]);

        Payment::query()->create([
            'billing_statement_id' => $billing->id,
            'recorded_by_user_id' => $officer->id,
            'verified_by_user_id' => $officer->id,
            'official_receipt_no' => 'OR-12345',
            'receipt_date' => $this->from->copy()->addDays(8)->toDateString(),
            'amount' => 200,
            'status' => 'VERIFIED',
            'submitted_at' => $this->from->copy()->addDays(8),
            'verified_at' => $this->from->copy()->addDays(8),
        ]);

        $dataset = $this->generate('billing-settlement');
        $row = $dataset->rows->first();

        $this->assertSame(1, $dataset->count());
        $this->assertSame('BILL-ACCT-001', $row['billing_no']);
        $this->assertSame('INC-BILL-001', $row['case_reference']);
        $this->assertSame('Rectangular Table', $row['affected_property']);
        $this->assertSame('Damaged', $row['basis']);
        $this->assertSame('500.00', $row['amount_assessed']);
        $this->assertSame('200.00', $row['amount_paid']);
        $this->assertSame('300.00', $row['remaining_balance']);
        $this->assertSame('OR-12345', $row['official_receipt_no']);
        $this->assertSame('Partially Paid', $row['payment_status']);

        $this->assertSame(1, $this->generate('billing-settlement', ['billing_status' => 'ISSUED'])->count());
        $this->assertSame(1, $this->generate('billing-settlement', ['payment_status' => 'PARTIALLY_PAID'])->count());
        $this->assertSame(0, $this->generate('billing-settlement', ['payment_status' => 'PAID'])->count());
    }

    public function test_new_accountability_reports_use_the_same_rows_for_csv(): void
    {
        [$custody, $custodyLine] = $this->custody('ACADEMIC', 'College of Computer Studies', 'Rectangular Table', 10, 10);
        $officer = User::factory()->create(['access_classification' => AccessClassification::SpmuOfficer]);

        $incident = Incident::query()->create([
            'incident_no' => 'INC-CSV-001',
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'reported_by_user_id' => $officer->id,
            'incident_type' => 'MISSING',
            'reported_at' => $this->from->copy()->addDays(5),
            'status' => 'OPEN',
        ]);

        IncidentLine::query()->create([
            'incident_id' => $incident->id,
            'custody_line_id' => $custodyLine->id,
            'quantity' => 1,
            'observed_condition' => 'MISSING',
            'disposition_state' => 'LOST',
        ]);

        $dataset = $this->generate('accountability-cases');

        $handle = fopen('php://memory', 'r+');
        app(ReportService::class)->writeCsv($dataset, $handle);
        rewind($handle);
        fgetcsv($handle); // headers
        $record = fgetcsv($handle);
        fclose($handle);

        $this->assertSame($dataset->records()[0], $record);
    }

    private function generate(string $report, array $input = []): ReportDataset
    {
        return app(ReportService::class)->generate(
            ReportFilters::fromRequest(
                Request::create('/reports', 'GET', $input),
                $report,
                $this->from,
                $this->to,
                'month'
            )
        );
    }

    /**
     * @return array{0:CustodyTransaction,1:CustodyLine}
     */
    private function custody(string $division, string $office, string $description, int $released, int $returned): array
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'ACCRPT'],
            ['category_name' => 'Accountability Fixture', 'active' => true]
        );
        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );
        $item = InventoryItem::query()->firstOrCreate(
            ['unique_description' => $description],
            [
                'category_id' => $category->id,
                'unit_id' => $measure->id,
                'total_quantity' => 100,
                'condition_code' => 'SERVICEABLE',
                'borrowable' => true,
                'off_campus_allowed' => false,
                'laundry_required' => false,
                'provisional' => false,
                'active' => true,
            ]
        );

        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-ACCRPT-'.fake()->unique()->numberBetween(1000, 9999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);
        $request->forceFill(['created_at' => $this->from->copy()->addDay(), 'updated_at' => $this->from->copy()->addDay()])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Accountability report fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $office,
            'schedule_date' => $this->from->copy()->addDay()->toDateString(),
            'return_date' => $this->from->copy()->addDays(5)->toDateString(),
            'needed_from' => $this->from->copy()->addDay(),
            'return_due_at' => $this->from->copy()->addDays(5)->endOfDay(),
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => $released,
            'approved_quantity' => $released,
        ]);
        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $this->from->copy()->addDay(),
            'period_end' => $this->from->copy()->addDays(5),
            'allocated_quantity' => $released,
            'released_quantity' => $released,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => $this->from->copy()->addDay(),
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-ACCRPT-'.fake()->unique()->numberBetween(1000, 9999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $returned >= $released ? 'CLOSED' : 'ACTIVE',
            'released_at' => $this->from->copy()->addDay(),
            'due_at' => $this->from->copy()->addDays(5)->endOfDay(),
            'closed_at' => $returned >= $released ? $this->from->copy()->addDays(8) : null,
        ]);
        $custody->forceFill(['created_at' => $this->from->copy()->addDay(), 'updated_at' => $this->from->copy()->addDay()])->save();

        $line = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => $released,
            'quantity_to_receive' => $released,
            'actual_released_quantity' => $released,
            'returned_quantity' => $returned,
        ]);

        return [$custody->fresh(), $line];
    }
}
