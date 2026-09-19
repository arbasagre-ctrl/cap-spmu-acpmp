<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AcademicPeriod;
use App\Models\Allocation;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\BorrowerViolation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\Payment;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\Sanction;
use App\Models\SanctionRule;
use App\Models\StoredFile;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\UserSignature;
use App\Reports\Builders\AccountabilityCasesReport;
use App\Reports\ReportCatalogue;
use App\Reports\ReportFilters;
use App\Services\InventoryService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RsldppWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        // Seeded so Head/Officer users carry a real Spmu role pivot row -
        // AccountabilityController::authorizeSpmu() checks hasRole(), which
        // is independent of access_classification.
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow(Carbon::create(2026, 9, 20, 9));
        $this->now = Carbon::create(2026, 9, 20, 9);

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'RSLDDP',
            'unit_name' => 'RSLDDP Test Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_head_compliance_decision_requires_a_specific_action_that_matches_the_finding(): void
    {
        [$custody, $line] = $this->custody('Damaged Test Fixture');
        [$head, $officer] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $officer, 'DAMAGED');

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_REQUIRED',
                'resolution_remarks' => 'Physical compliance required.',
                'requires_rslddp' => '0',
                'count_as_offense' => '0',
                '_action_token' => 'test-compliance-action-required',
            ])
            ->assertSessionHasErrors('compliance_action');

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_REQUIRED',
                'compliance_action' => 'RECOVERY',
                'resolution_remarks' => 'Physical compliance required.',
                'requires_rslddp' => '0',
                'count_as_offense' => '0',
                '_action_token' => 'test-compliance-action-mismatch',
            ])
            ->assertSessionHasErrors('compliance_action');

        $this->assertSame('OPEN', $incident->fresh()->status);
    }

    public function test_compliance_case_with_requires_rslddp_generates_rslddp_and_still_resolves_via_ao_verification(): void
    {
        [$custody, $line] = $this->custody('Rectangular Table');
        [$head, $officer] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $officer, 'DAMAGED');

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_REQUIRED',
                'compliance_action' => 'REPAIR',
                'resolution_remarks' => 'Repair required.',
                'requires_rslddp' => '1',
                'count_as_offense' => '0',
                '_action_token' => 'test-rslddp-compliance-decide-1',
            ])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('COMPLIANCE_RSLDDP_PENDING', $incident->status);
        $this->assertSame('REPAIR', $incident->compliance_action);
        $this->assertTrue((bool) $incident->requires_rslddp);
        $this->assertDatabaseHas('generated_documents', [
            'subject_type' => Incident::class,
            'subject_id' => $incident->id,
            'document_type' => 'RSLDDP',
            'status' => 'FINAL',
        ]);
        // No Compliance Notice generated for a new case.
        $this->assertDatabaseMissing('generated_documents', [
            'subject_type' => Incident::class,
            'subject_id' => $incident->id,
            'document_type' => 'ACCOUNTABILITY_COMPLIANCE_NOTICE',
        ]);

        // AO cannot verify compliance while RSLDDP is still pending.
        $this->actingAs($officer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_COMPLETED',
                '_action_token' => 'test-rslddp-compliance-verify-attempt-1',
            ])
            ->assertSessionHasErrors('incident');

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.rslddp.upload', $incident), [
                'evidence' => UploadedFile::fake()->create('accomplished-rslddp.pdf', 5, 'application/pdf'),
                '_action_token' => 'test-rslddp-upload-1',
            ])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('COMPLIANCE_REQUIRED', $incident->status);

        // Existing, unmodified AO verification now resumes.
        $this->actingAs($officer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_COMPLETED',
                '_action_token' => 'test-rslddp-compliance-verify-attempt-2',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('RESOLVED', $incident->fresh()->status);
        $item = $line->requestItem->inventoryItem;
        $balance = app(InventoryService::class)->availability($item->fresh(), now(), now()->addMinute());
        $this->assertSame(0.0, $balance['incident']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_INCIDENT_RESTORATION',
            'source_type' => Incident::class,
            'source_id' => $incident->id,
            'actor_user_id' => $officer->id,
        ]);
        $this->assertDatabaseHas('borrower_restrictions', [
            'incident_id' => $incident->id,
            'status' => 'LIFTED',
        ]);
    }

    public function test_compliance_case_without_requires_rslddp_behaves_exactly_as_before(): void
    {
        [$custody, $line] = $this->custody('Monoblock Chair');
        [$head, $officer] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $officer, 'DAMAGED');

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_REQUIRED',
                'compliance_action' => 'REPAIR',
                'resolution_remarks' => 'Repair required.',
                'requires_rslddp' => '0',
                'count_as_offense' => '0',
                '_action_token' => 'test-rslddp-compliance-decide-2',
            ])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('COMPLIANCE_REQUIRED', $incident->status);
        $this->assertFalse((bool) $incident->requires_rslddp);
        $this->assertDatabaseMissing('generated_documents', [
            'subject_type' => Incident::class,
            'subject_id' => $incident->id,
            'document_type' => 'RSLDDP',
        ]);

        $this->actingAs($officer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_COMPLETED',
                '_action_token' => 'test-rslddp-compliance-verify-attempt-3',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('RESOLVED', $incident->fresh()->status);
    }

    public function test_replacement_compliance_updates_inventory_only_after_ao_verification(): void
    {
        [$custody, $line] = $this->custody('Portable Sound System');
        [$head, $officer] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $officer, 'LOST');
        $item = $line->requestItem->inventoryItem;
        $inventory = app(InventoryService::class);

        $before = $inventory->availability($item, now(), now()->addMinute());
        $this->assertSame(1.0, $before['incident']);
        $this->assertSame(99.0, $before['borrower_available']);

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_REQUIRED',
                'compliance_action' => 'REPLACEMENT',
                'resolution_remarks' => 'One-for-one replacement required.',
                'requires_rslddp' => '0',
                'count_as_offense' => '0',
                '_action_token' => 'test-replacement-connected-decision',
            ])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('COMPLIANCE_REQUIRED', $incident->status);
        $this->assertSame('REPLACEMENT', $incident->compliance_action);

        // Head decision alone does not change physical stock.
        $afterDecision = $inventory->availability($item->fresh(), now(), now()->addMinute());
        $this->assertSame(1.0, $afterDecision['incident']);
        $this->assertSame(100.0, (float) $item->fresh()->total_quantity);

        $this->actingAs($officer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'COMPLIANCE_COMPLETED',
                '_action_token' => 'test-replacement-connected-verification',
            ])
            ->assertSessionHasNoErrors();

        $afterVerification = $inventory->availability($item->fresh(), now(), now()->addMinute());
        $this->assertSame('RESOLVED', $incident->fresh()->status);
        $this->assertSame(0.0, $afterVerification['incident']);
        $this->assertSame(100.0, (float) $item->fresh()->total_quantity);
        $this->assertSame(100.0, $afterVerification['borrower_available']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_INCIDENT_RESTORATION',
            'source_type' => Incident::class,
            'source_id' => $incident->id,
            'actor_user_id' => $officer->id,
            'reason' => 'Replacement Received',
        ]);
    }

    public function test_official_billing_statement_can_be_corrected_before_any_payment(): void
    {
        [$custody, $line] = $this->custody('Steel Cabinet');
        [$head] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $head, 'DAMAGED');
        $incident->update(['status' => 'RSLDDP_FOR_ACCOUNTING_PROCESSING', 'requires_rslddp' => true]);

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.rslddp.billing', $incident), [
                'evidence' => UploadedFile::fake()->create('official-billing.pdf', 5, 'application/pdf'),
                'amount' => 800,
                'billing_reference' => 'ACCTG-001',
                '_action_token' => 'test-rslddp-billing-first',
            ])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('RSLDDP_PAYMENT_REQUIRED', $incident->status);
        $firstBilling = BillingStatement::where('borrower_user_id', $incident->borrower_user_id)
            ->where('source', 'ACCOUNTING_OFFICE')->firstOrFail();
        $this->assertSame(800.0, (float) $firstBilling->total_amount);

        // Correction before any payment: silently replaces in place.
        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.rslddp.billing', $incident), [
                'evidence' => UploadedFile::fake()->create('corrected-billing.pdf', 5, 'application/pdf'),
                'amount' => 650,
                'billing_reference' => 'ACCTG-002-CORRECTED',
                '_action_token' => 'test-rslddp-billing-correction',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('VOID', $firstBilling->fresh()->status);
        $this->assertDatabaseHas('generated_documents', [
            'subject_type' => BillingStatement::class,
            'subject_id' => $firstBilling->id,
            'status' => 'SUPERSEDED',
        ]);

        $currentBilling = BillingStatement::where('borrower_user_id', $incident->borrower_user_id)
            ->where('source', 'ACCOUNTING_OFFICE')
            ->where('status', '!=', 'VOID')
            ->firstOrFail();
        $this->assertSame(650.0, (float) $currentBilling->total_amount);

        $incident->refresh();
        $this->assertSame('RSLDDP_PAYMENT_REQUIRED', $incident->status);
    }

    public function test_official_billing_statement_correction_is_blocked_once_a_payment_exists(): void
    {
        [$custody, $line] = $this->custody('Office Chair');
        [$head, $officer] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $head, 'DAMAGED');
        $incident->update(['status' => 'RSLDDP_FOR_ACCOUNTING_PROCESSING', 'requires_rslddp' => true]);

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.rslddp.billing', $incident), [
                'evidence' => UploadedFile::fake()->create('official-billing.pdf', 5, 'application/pdf'),
                'amount' => 400,
                'billing_reference' => 'ACCTG-010',
                '_action_token' => 'test-rslddp-billing-pre-payment',
            ])
            ->assertSessionHasNoErrors();

        $billing = BillingStatement::where('borrower_user_id', $incident->borrower_user_id)
            ->where('source', 'ACCOUNTING_OFFICE')->firstOrFail();

        $this->actingAs($officer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('payments.store', $billing), [
                'evidence' => UploadedFile::fake()->create('receipt.pdf', 5, 'application/pdf'),
                'official_receipt_no' => 'OR-010',
                'receipt_date' => now()->format('Y-m-d'),
                'amount' => 400,
                '_action_token' => 'test-rslddp-cashier-payment',
            ])
            ->assertSessionHasNoErrors();

        $billing->refresh();
        $this->assertSame('SETTLED', $billing->status);
        $incident->refresh();
        $this->assertSame('RSLDDP_FOR_RESOLUTION', $incident->status);
        $paymentCountBefore = Payment::where('billing_statement_id', $billing->id)->count();

        // Correction after payment: permanently blocked, nothing changes.
        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.rslddp.billing', $incident), [
                'evidence' => UploadedFile::fake()->create('attempted-correction.pdf', 5, 'application/pdf'),
                'amount' => 350,
                'billing_reference' => 'ACCTG-011-ATTEMPT',
                '_action_token' => 'test-rslddp-billing-post-payment-attempt',
            ])
            ->assertSessionHasErrors('incident');

        $this->assertSame('SETTLED', $billing->fresh()->status);
        $this->assertSame(400.0, (float) $billing->fresh()->total_amount);
        $this->assertSame($paymentCountBefore, Payment::where('billing_statement_id', $billing->id)->count());
        $this->assertSame('RSLDDP_FOR_RESOLUTION', $incident->fresh()->status);
        $this->assertDatabaseHas('borrower_restrictions', [
            'incident_id' => $incident->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_sanction_notice_title_reflects_borrowing_suspension_vs_written_reprimand(): void
    {
        $renderData = [
            'sanction' => new Sanction(),
            'logoDataUri' => 'data:image/png;base64,',
            'offenseLabel' => '1st Offense',
            'confirmedDate' => '20 September 2026',
            'effectiveFrom' => '20 September 2026',
            'effectiveTo' => null,
            'academicPeriod' => '—',
            'officeUnit' => 'Test Office',
            'requestNo' => 'BR-0001',
            'custodyNo' => 'CUS-0001',
            'reasonText' => 'Confirmed borrowing accountability offense',
            'headName' => 'SPMU Head',
            'headDesignation' => '',
            'headSignatureHtml' => '',
        ];

        $suspensionHtml = view('documents.accountability.sanction-notice', $renderData + ['hasBorrowingSuspension' => true])->render();
        $this->assertStringContainsString('Suspension Notice', $suspensionHtml);
        $this->assertStringNotContainsString('<h1>Administrative Sanction Notice</h1>', $suspensionHtml);

        $reprimandHtml = view('documents.accountability.sanction-notice', $renderData + ['hasBorrowingSuspension' => false])->render();
        $this->assertStringContainsString('Administrative Sanction Notice', $reprimandHtml);
        $this->assertStringNotContainsString('<h1>Suspension Notice</h1>', $reprimandHtml);
    }

    public function test_resolve_incident_only_generates_suspension_notice_for_borrowing_suspension_sanction_code(): void
    {
        /*
         * The gate in AccountabilityController::resolveIncident() is a
         * simple, directly-readable condition
         * (strtoupper($recordedSanction->sanction_code) === 'BORROWING_SUSPENSION')
         * - verified by inspection. This test instead verifies the
         * DocumentService generator it calls only ever produces a document
         * for a sanction actually passed to it (never generates on its own),
         * and that historical Written Reprimand sanctions remain confirmable
         * and readable without a notice.
         */
        [$reprimandRule] = $this->sanctionRules();
        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        $violation = \App\Models\BorrowerViolation::query()->create([
            'borrower_user_id' => $borrower->id,
            'violation_code' => 'MINOR_INFRACTION',
            'status' => 'CONFIRMED',
            'detected_at' => $this->now,
        ]);

        $reprimand = Sanction::query()->create([
            'borrower_violation_id' => $violation->id,
            'borrower_user_id' => $borrower->id,
            'sanction_rule_id' => $reprimandRule->id,
            'offense_no' => 1,
            'sanction_code' => 'WRITTEN_REPRIMAND',
            'sanction_label' => 'Written Reprimand',
            'effective_from' => $this->now,
            'status' => 'ACTIVE',
            'confirmed_by_user_id' => $head->id,
            'confirmed_at' => $this->now,
        ]);

        $this->assertDatabaseMissing('generated_documents', [
            'subject_type' => Sanction::class,
            'subject_id' => $reprimand->id,
        ]);
    }

    public function test_documents_preview_authorizes_owner_and_spmu_but_not_another_borrower(): void
    {
        [$custody, $line] = $this->custody('Filing Cabinet');
        [$head] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $head, 'LOST');

        $restriction = BorrowerRestriction::query()->create([
            'borrower_user_id' => $incident->borrower_user_id,
            'incident_id' => $incident->id,
            'restriction_type' => 'UNRESOLVED_INCIDENT',
            'reason' => 'Unresolved lost-property incident.',
            'status' => 'ACTIVE',
            'effective_from' => $this->now,
            'imposed_by_user_id' => $head->id,
        ]);

        $document = app(\App\Services\DocumentService::class)->restrictionNotice($restriction);

        $owner = User::find($incident->borrower_user_id);
        $this->actingAs($owner)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('documents.preview', $document))
            ->assertOk()
            ->assertDontSee('Open original');

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('documents.preview', $document))
            ->assertOk();

        $otherBorrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
        $this->actingAs($otherBorrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('documents.preview', $document))
            ->assertForbidden();
    }

    public function test_ao_and_borrower_are_blocked_from_head_only_rslddp_endpoints(): void
    {
        [$custody, $line] = $this->custody('Projector');
        [$head, $officer] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $head, 'DAMAGED');
        $incident->update(['status' => 'RSLDDP_AWAITING_UPLOAD', 'requires_rslddp' => true]);
        $borrower = User::find($incident->borrower_user_id);

        $this->actingAs($officer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.rslddp.upload', $incident), [
                'evidence' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->post(route('incidents.rslddp.upload', $incident), [
                'evidence' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->actingAs($officer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('incidents.rslddp.resolve', $incident), ['resolution_remarks' => 'x'])
            ->assertForbidden();
    }

    public function test_review_violation_written_reprimand_creates_no_new_notice(): void
    {
        [$head] = $this->headAndOfficer();
        $violation = $this->pendingViolation();

        $response = $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('accountability.violations.review', $violation), [
                'decision' => 'CONFIRMED',
                'sanction_code' => 'WRITTEN_REPRIMAND',
                '_action_token' => 'test-review-violation-written-reprimand',
            ]);

        $response->assertSessionHasNoErrors();

        $violation->refresh();
        $this->assertSame('CONFIRMED', $violation->status);

        $sanction = Sanction::where('borrower_violation_id', $violation->id)->firstOrFail();
        $this->assertSame('WRITTEN_REPRIMAND', $sanction->sanction_code);
        $this->assertSame('ACTIVE', $sanction->status);

        // The sanction/offense record is created, but no paper notice is
        // generated for a Written Reprimand.
        $this->assertDatabaseMissing('generated_documents', [
            'subject_type' => Sanction::class,
            'subject_id' => $sanction->id,
        ]);
    }

    public function test_review_violation_borrowing_suspension_generates_suspension_notice(): void
    {
        [$head] = $this->headAndOfficer();
        $violation = $this->pendingViolation();

        $response = $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('accountability.violations.review', $violation), [
                'decision' => 'CONFIRMED',
                'sanction_code' => 'BORROWING_SUSPENSION',
                'effective_to' => $this->now->copy()->addMonth()->toDateString(),
                '_action_token' => 'test-review-violation-borrowing-suspension',
            ]);

        $response->assertSessionHasNoErrors();

        $sanction = Sanction::where('borrower_violation_id', $violation->id)->firstOrFail();
        $this->assertSame('BORROWING_SUSPENSION', $sanction->sanction_code);

        $document = GeneratedDocument::where('subject_type', Sanction::class)
            ->where('subject_id', $sanction->id)
            ->where('status', 'FINAL')
            ->firstOrFail();

        // Only a formal borrowing suspension gets a printed notice at all;
        // the title itself ("Suspension Notice" vs "Administrative Sanction
        // Notice") is covered by test_sanction_notice_title_reflects_...
        // above, which renders the underlying template directly rather than
        // the compiled PDF.
        $this->assertSame('ADMINISTRATIVE_SANCTION_NOTICE', $document->document_type);
    }

    public function test_status_badge_shows_canonical_labels_and_tones_for_rslddp_statuses(): void
    {
        $expected = [
            'RSLDDP_AWAITING_UPLOAD' => ['RSLDDP Processing', 'info'],
            'RSLDDP_FOR_ACCOUNTING_PROCESSING' => ['For Accounting Processing', 'info'],
            'RSLDDP_PAYMENT_REQUIRED' => ['Payment Required', 'warning'],
            'RSLDDP_FOR_RESOLUTION' => ['For Resolution', 'info'],
            'COMPLIANCE_RSLDDP_PENDING' => ['Compliance - RSLDDP Pending', 'info'],
        ];

        foreach ($expected as $status => [$label, $tone]) {
            $html = \Illuminate\Support\Facades\Blade::render('<x-status-badge :status="$status" />', ['status' => $status]);

            $this->assertStringContainsString($label, $html, "Expected label \"{$label}\" for status {$status}.");
            $this->assertStringContainsString('status-'.$tone, $html, "Expected tone \"{$tone}\" for status {$status}.");
            $this->assertStringNotContainsString(
                str($status)->replace('_', ' ')->lower()->title()->toString(),
                $html,
                "Raw auto-formatted key leaked into the badge for {$status}."
            );
        }
    }

    public function test_accountability_oversight_page_shows_canonical_rslddp_label_not_raw_status_key(): void
    {
        [$custody, $line] = $this->custody('Whiteboard');
        [$head] = $this->headAndOfficer();
        $incident = $this->openIncident($custody, $line, $head, 'DAMAGED');
        $incident->update(['status' => 'RSLDDP_FOR_ACCOUNTING_PROCESSING', 'requires_rslddp' => true]);

        $this->actingAs($head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('accountability.index'))
            ->assertOk()
            ->assertSee('For Accounting Processing', false)
            ->assertDontSee('Rslddp For Accounting Processing', false);
    }

    public function test_accountability_cases_report_filter_includes_current_rslddp_statuses_and_marks_legacy_separately(): void
    {
        $options = (ReportCatalogue::filterDefinitions()['accountability_status']['options'])();

        foreach ([
            'RSLDDP_AWAITING_UPLOAD' => 'RSLDDP Processing',
            'RSLDDP_FOR_ACCOUNTING_PROCESSING' => 'For Accounting Processing',
            'RSLDDP_PAYMENT_REQUIRED' => 'Payment Required',
            'RSLDDP_FOR_RESOLUTION' => 'For Resolution',
            'COMPLIANCE_RSLDDP_PENDING' => 'Compliance - RSLDDP Pending',
        ] as $key => $label) {
            $this->assertArrayHasKey($key, $options);
            $this->assertSame($label, $options[$key]);
        }

        // Legacy statuses remain selectable for historical searches, but are
        // labeled distinctly and never present as a normal current choice.
        foreach ([
            'BILLING_PENDING' => 'Billing Pending (Legacy)',
            'FOR_BILLING' => 'Billing Statement Pending (Legacy)',
            'RETURNED_PENDING_SETTLEMENT' => 'Returned for Correction (Legacy)',
        ] as $key => $label) {
            $this->assertArrayHasKey($key, $options);
            $this->assertSame($label, $options[$key]);
        }
    }

    public function test_custody_return_filter_dropdown_includes_current_rslddp_statuses_and_marks_legacy(): void
    {
        [, $officer] = $this->headAndOfficer();

        $response = $this->actingAs($officer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('custody.return.index'));

        $response->assertOk();
        $response->assertSee('RSLDDP Processing', false);
        $response->assertSee('For Accounting Processing', false);
        $response->assertSee('Payment Required', false);
        $response->assertSee('For Resolution', false);
        $response->assertSee('Compliance - RSLDDP Pending', false);
        $response->assertSee('Billing Statement Pending (Legacy)', false);
        $response->assertSee('Billing Pending (Legacy)', false);
    }

    public function test_legacy_property_incident_status_remains_filterable_in_accountability_cases_report(): void
    {
        [$legacyCustody, $legacyLine] = $this->custody('Legacy Steel Cabinet');
        [$head] = $this->headAndOfficer();
        $legacyIncident = $this->openIncident($legacyCustody, $legacyLine, $head, 'DAMAGED');
        $legacyIncident->update(['status' => 'FOR_BILLING']);

        [$currentCustody, $currentLine] = $this->custody('Current Monitor');
        $currentIncident = $this->openIncident($currentCustody, $currentLine, $head, 'DAMAGED');
        $currentIncident->update(['status' => 'RSLDDP_AWAITING_UPLOAD']);

        $filters = ReportFilters::fromRequest(
            Request::create('/', 'GET', ['accountability_status' => 'FOR_BILLING']),
            'accountability-cases',
            $this->now->copy()->startOfMonth(),
            $this->now->copy()->endOfMonth(),
            'custom'
        );

        $dataset = (new AccountabilityCasesReport())->build($filters);

        $this->assertCount(1, $dataset->rows, 'The legacy filter must not be silently dropped or match unrelated current cases.');
        $row = $dataset->rows->first();
        $this->assertSame((string) $legacyIncident->incident_no, $row['case_reference']);
        $this->assertSame('Billing Statement Pending (Legacy)', $row['final_outcome']);
    }

    private function pendingViolation(): BorrowerViolation
    {
        AcademicPeriod::query()->firstOrCreate(
            ['academic_year' => '2026-2027', 'term_code' => 'RSLDDP-TEST-TERM'],
            [
                'term_name' => 'RSLDDP Test Term',
                'start_date' => $this->now->copy()->startOfMonth()->toDateString(),
                'end_date' => $this->now->copy()->endOfMonth()->toDateString(),
                'status' => 'ACTIVE',
            ]
        );

        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);

        return BorrowerViolation::query()->create([
            'borrower_user_id' => $borrower->id,
            'violation_code' => 'LATE_RETURN',
            'details_json' => ['reasons' => ['LATE_RETURN']],
            'status' => 'PENDING_REVIEW',
            'detected_at' => $this->now,
        ]);
    }

    /**
     * @return array{0:CustodyTransaction,1:CustodyLine}
     */
    private function custody(string $description): array
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'RSLDDPFX'],
            ['category_name' => 'RSLDDP Fixture', 'active' => true]
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
            'request_no' => 'BR-RSLDDP-'.fake()->unique()->numberBetween(1000, 9999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'RSLDDP workflow fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'Test Office',
            'schedule_date' => $this->now->copy()->addDay()->toDateString(),
            'return_date' => $this->now->copy()->addDays(5)->toDateString(),
            'needed_from' => $this->now->copy()->addDay(),
            'return_due_at' => $this->now->copy()->addDays(5)->endOfDay(),
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => 1,
            'approved_quantity' => 1,
        ]);
        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $this->now->copy()->addDay(),
            'period_end' => $this->now->copy()->addDays(5),
            'allocated_quantity' => 1,
            'released_quantity' => 1,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => $this->now->copy()->addDay(),
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-RSLDDP-'.fake()->unique()->numberBetween(1000, 9999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'released_at' => $this->now->copy()->addDay(),
            'due_at' => $this->now->copy()->addDays(5)->endOfDay(),
        ]);

        $line = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 1,
            'returned_quantity' => 1,
        ]);

        return [$custody->fresh(), $line];
    }

    /** @return array{0:User,1:User} */
    private function headAndOfficer(): array
    {
        $head = User::where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();
        $officer = User::where('access_classification', AccessClassification::SpmuOfficer->value)->firstOrFail();

        $this->registerSignature($head);
        $this->registerSignature($officer);

        return [$head, $officer];
    }

    private function registerSignature(User $user): void
    {
        if (UserSignature::where('user_id', $user->id)->where('status', 'ACTIVE')->exists()) {
            return;
        }

        $bytes = "\x89PNG\r\n\x1a\n".'signature-ink-'.$user->id;
        $path = 'tests/signatures/'.$user->id.'/signature.png';

        Storage::disk('local')->put($path, $bytes);

        $file = StoredFile::create([
            'uploaded_by_user_id' => $user->id,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'signature.png',
            'mime_type' => 'image/png',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'SIGNATURE',
        ]);

        UserSignature::query()->create([
            'user_id' => $user->id,
            'stored_file_id' => $file->id,
            'effective_from' => now()->subMinute(),
            'effective_to' => null,
            'status' => 'ACTIVE',
        ]);
    }

    private function openIncident(CustodyTransaction $custody, CustodyLine $line, User $officer, string $type): Incident
    {
        $incident = Incident::query()->create([
            'incident_no' => 'INC-RSLDDP-'.fake()->unique()->numberBetween(1000, 9999),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'reported_by_user_id' => $officer->id,
            'incident_type' => $type,
            'reported_at' => $this->now,
            'status' => 'OPEN',
        ]);

        IncidentLine::query()->create([
            'incident_id' => $incident->id,
            'custody_line_id' => $line->id,
            'quantity' => 1,
            'observed_condition' => $type,
            'disposition_state' => $type === 'DAMAGED' ? 'DAMAGED_MAINTENANCE' : 'REPLACEMENT_REQUIRED',
        ]);

        BorrowerRestriction::query()->create([
            'borrower_user_id' => $custody->borrower_user_id,
            'custody_transaction_id' => $custody->id,
            'incident_id' => $incident->id,
            'restriction_type' => 'UNRESOLVED_INCIDENT',
            'reason' => 'Unresolved '.$type.' incident.',
            'status' => 'ACTIVE',
            'effective_from' => $this->now,
            'imposed_by_user_id' => $officer->id,
        ]);

        return $incident->fresh();
    }

    /** @return array{0:SanctionRule,1:SanctionRule} */
    private function sanctionRules(): array
    {
        return [
            SanctionRule::query()->firstOrCreate(
                ['sanction_code' => 'WRITTEN_REPRIMAND', 'offense_no' => 1],
                ['sanction_label' => 'Written Reprimand', 'duration_mode' => 'NONE', 'status' => 'ACTIVE']
            ),
            SanctionRule::query()->firstOrCreate(
                ['sanction_code' => 'BORROWING_SUSPENSION', 'offense_no' => 2],
                ['sanction_label' => '1-Month Borrowing Suspension', 'duration_mode' => 'MONTHS', 'duration_value' => 1, 'status' => 'ACTIVE']
            ),
        ];
    }
}
