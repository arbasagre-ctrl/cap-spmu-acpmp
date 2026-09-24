<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ItemPreparationStep2ContractTest extends TestCase
{
    #[Test]
    public function controller_keeps_normal_preparation_and_exposes_both_head_resolution_outcomes(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/CustodyController.php'));

        $prepareStart = strpos($source, 'public function prepare(');
        $prepareEnd = strpos($source, 'public function release(', $prepareStart ?: 0);
        $this->assertNotFalse($prepareStart);
        $this->assertNotFalse($prepareEnd);
        $prepare = substr($source, $prepareStart, $prepareEnd - $prepareStart);

        $this->assertStringContainsString('$service->prepare($custody, $request->user());', $prepare);
        $this->assertStringNotContainsString("'quantities' =>", $prepare);
        $this->assertStringContainsString('Items prepared.', $prepare);

        $this->assertStringContainsString('public function reportPreparationIssue(', $source);
        $this->assertStringContainsString("Rule::requiredIf(fn () => \$request->input('issue_type') === 'QUANTITY_AVAILABILITY')", $source);
        $this->assertStringContainsString("Rule::requiredIf(fn () => \$request->input('issue_type') === 'PHYSICAL_CONDITION')", $source);
        $this->assertStringContainsString("Rule::requiredIf(fn () => \$request->input('issue_type') === 'OTHER')", $source);
        $this->assertStringContainsString('$service->reportPreparationIssue(', $source);
        $this->assertStringContainsString('public function resolvePreparationIssue(', $source);
        $this->assertStringContainsString("'INVENTORY_REVIEW_COMPLETE'", $source);
        $this->assertStringContainsString("'UNABLE_TO_FULFILL_APPROVED_REQUEST'", $source);
        $this->assertStringContainsString('$service->resolvePreparationIssue(', $source);
        $this->assertStringContainsString('$workflow->cancelApprovedForPreparationDiscrepancy(', $source);
        $this->assertStringContainsString("'inventory_item_id' => \$inventoryItemId", $source);
    }

    #[Test]
    public function ao_report_records_inventory_discrepancy_without_editing_inventory_or_approved_quantity(): void
    {
        $source = (string) file_get_contents(app_path('Services/CustodyService.php'));
        $start = strpos($source, 'public function reportPreparationIssue(');
        $end = strpos($source, 'public function resolvePreparationIssue(', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $method = substr($source, $start, $end - $start);

        $this->assertStringContainsString("'PREPARATION_ISSUE_REPORTED'", $method);
        $this->assertStringContainsString("'observed_usable_quantity' => \$observedUsableQuantity", $method);
        $this->assertStringContainsString("'condition_observed' => \$conditionObserved !== '' ? \$conditionObserved : null", $method);
        $this->assertStringContainsString("'details' => \$details !== '' ? \$details : null", $method);
        $this->assertStringContainsString("'prepared_at' => null", $method);
        $this->assertStringContainsString("'prepared_by_user_id' => null", $method);
        $this->assertStringNotContainsString('InventoryItem::', $method);
        $this->assertDoesNotMatchRegularExpression(
            "/->update\(\[[^\]]*'approved_quantity'/s",
            $method
        );
    }

    #[Test]
    public function unresolved_inventory_discrepancy_blocks_prepare_release_and_missed_pickup_handling(): void
    {
        $custodySource = (string) file_get_contents(app_path('Services/CustodyService.php'));
        $workflowSource = (string) file_get_contents(app_path('Services/RequestWorkflowService.php'));

        $prepareStart = strpos($custodySource, 'public function prepare(');
        $prepareEnd = strpos($custodySource, 'public function release(', $prepareStart ?: 0);
        $prepare = substr($custodySource, $prepareStart, $prepareEnd - $prepareStart);

        $releaseStart = strpos($custodySource, 'public function release(');
        $releaseEnd = strpos($custodySource, 'public function receiveReturn(', $releaseStart ?: 0);
        $release = substr($custodySource, $releaseStart, ($releaseEnd ?: strlen($custodySource)) - $releaseStart);

        $this->assertStringContainsString('unresolvedPreparationIssueEvents($custody)->isNotEmpty()', $prepare);
        $this->assertStringContainsString('Wait for the reported inventory discrepancy to be reviewed before confirming Items Prepared.', $prepare);
        $this->assertStringContainsString('unresolvedPreparationIssueEvents($custody)->isNotEmpty()', $release);
        $this->assertStringContainsString('Physical Release is unavailable while an inventory discrepancy is under review.', $release);
        $this->assertStringContainsString("'PICKUP_HELD_FOR_PREPARATION_ISSUE'", $custodySource);
        $this->assertStringContainsString('borrower_missed_pickup', $custodySource);
        $this->assertStringContainsString('$this->custody->hasPreparationExceptionPendingRelease($locked)', $workflowSource);
    }

    #[Test]
    public function inventory_review_cannot_silently_change_an_expired_approved_pickup_schedule(): void
    {
        $source = (string) file_get_contents(app_path('Services/CustodyService.php'));
        $start = strpos($source, 'public function resolvePreparationIssue(');
        $end = strpos($source, 'public function hasOpenPreparationIssue(', $start ?: 0);
        $method = substr($source, $start, $end - $start);

        $this->assertStringContainsString('$locked->pickup_expires_at && now()->gt($locked->pickup_expires_at)', $method);
        $this->assertStringContainsString('Use Unable to Fulfill Approved Request instead.', $method);
    }

    #[Test]
    public function unable_to_fulfill_is_an_spmu_side_terminal_cancellation_before_release(): void
    {
        $source = (string) file_get_contents(app_path('Services/RequestWorkflowService.php'));
        $start = strpos($source, 'public function cancelApprovedForPreparationDiscrepancy(');
        $end = strpos($source, 'private function finalizeCancellation(', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $method = substr($source, $start, $end - $start);

        $this->assertStringContainsString("'PREPARATION_ISSUE_CLOSED_UNFULFILLED'", $method);
        $this->assertStringContainsString("'UNABLE_TO_FULFILL_APPROVED_REQUEST'", $method);
        $this->assertStringContainsString("'borrower_missed_pickup' => false", $method);
        $this->assertStringContainsString("'partial_release_allowed' => false", $method);
        $this->assertStringContainsString("'PREPARATION_UNABLE_TO_FULFILL'", $method);
        $this->assertStringContainsString('$this->finalizeCancellation(', $method);

        $finalizeStart = strpos($source, 'private function finalizeCancellation(');
        $finalizeEnd = strpos($source, '/**', $finalizeStart + 10);
        $finalize = substr($source, $finalizeStart, ($finalizeEnd ?: strlen($source)) - $finalizeStart);

        $this->assertStringContainsString('$this->inventory->restore(', $finalize);
        $this->assertStringContainsString("'status' => 'INVALIDATED'", $finalize);
        $this->assertStringContainsString("'status' => 'CANCELLED'", $finalize);
        $this->assertStringContainsString("'status' => 'VOID'", $finalize);
    }


    #[Test]
    public function head_review_notes_are_optional_for_recheck_but_required_for_unable_to_fulfill(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/CustodyController.php'));

        $this->assertStringContainsString(
            "Rule::requiredIf(fn () => \$request->input('resolution_type') === 'UNABLE_TO_FULFILL_APPROVED_REQUEST')",
            $source
        );
        $this->assertStringContainsString("'nullable',", $source);

        $service = (string) file_get_contents(app_path('Services/CustodyService.php'));
        $this->assertStringContainsString(
            "Inventory review completed; Action Officer recheck required.",
            $service
        );
    }

    #[Test]
    public function service_still_uses_approved_quantities_when_preparation_is_confirmed(): void
    {
        $source = (string) file_get_contents(app_path('Services/CustodyService.php'));
        $start = strpos($source, 'public function prepare(');
        $end = strpos($source, 'public function release(', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $method = substr($source, $start, $end - $start);

        $this->assertStringContainsString('$approved = (float) $line->approved_quantity;', $method);
        $this->assertStringContainsString("'quantity_to_receive' => \$approved", $method);
        $this->assertStringContainsString("'item_status' => 'PREPARED'", $method);
        $this->assertStringContainsString("'prepared_by_user_id' => \$spmu->id", $method);
        $this->assertStringContainsString("'prepared_at' => now()", $method);
        $this->assertStringContainsString("'RELEASE_PREPARED'", $method);
        $this->assertStringContainsString('$preparedQuantities = [];', $method);
        $this->assertStringContainsString("'prepared_quantities' => \$preparedQuantities", $method);
        $transactionEnd = strrpos($method, '}, 3);');
        $auditPosition = strpos($method, "\$this->audit->record(");
        $this->assertNotFalse($transactionEnd);
        $this->assertNotFalse($auditPosition);
        $this->assertLessThan($transactionEnd, $auditPosition, 'RELEASE_PREPARED audit must be written inside the preparation transaction.');
        $this->assertStringNotContainsString('Actual Prepared', $method);
    }
}
