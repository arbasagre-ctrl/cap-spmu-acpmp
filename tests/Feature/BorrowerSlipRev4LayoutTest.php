<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BorrowerSlipRev4LayoutTest extends TestCase
{
    private function documentServiceSource(): string
    {
        return (string) file_get_contents(app_path('Services/DocumentService.php'));
    }

    #[Test]
    public function borrower_slip_uses_landscape_rev_4_with_fixed_repeating_header_and_footer(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        foreach ([
            'size: A4 landscape',
            'borrower-page-header',
            'borrower-page-footer',
            'borrower-blue-rule',
            "BORROWER'S SLIP",
            'For borrower:',
            'For Supply Staff:',
            'Date Released',
            'Release Time',
            'Date Returned',
            'Remarks',
            'Borrowed by:',
            'Approved by:',
            'Issued by:',
            'Returned by:',
            'Verified by:',
            'Effectivity Date',
            'September 2026',
            'Rev. 4',
        ] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }

        $this->assertStringContainsString('.borrower-page-header {', $source);
        $this->assertStringContainsString('.borrower-page-footer {', $source);
        $this->assertMatchesRegularExpression('/\.borrower-page-header\s*\{.*?position:\s*fixed;/s', $source);
        $this->assertMatchesRegularExpression('/\.borrower-page-footer\s*\{.*?position:\s*fixed;/s', $source);
        $this->assertStringNotContainsString('.borrower-slip-rev4.compact .borrower-control-footer { position: absolute;', $source);
    }

    #[Test]
    public function borrower_and_supply_sections_are_visually_separated_without_nested_table_shells(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        $this->assertStringContainsString('class="split-gap"', $source);
        $this->assertStringContainsString('.borrower-items .col-gap, .borrower-items .split-gap', $source);
        $this->assertStringContainsString('border: 0 !important;', $source);
        $this->assertStringNotContainsString('borrower-items-shell', $source);
        $this->assertStringNotContainsString('borrower-items-left', $source);
        $this->assertStringNotContainsString('borrower-items-right', $source);
    }

    #[Test]
    public function signature_sections_are_visually_separated_without_a_nested_outer_table_row(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        $this->assertStringContainsString('signature-gap', $source);
        $this->assertStringContainsString('sig-gap-col', $source);
        $this->assertStringNotContainsString('borrower-signature-shell', $source);
        $this->assertStringNotContainsString('borrower-signatures-left', $source);
        $this->assertStringNotContainsString('borrower-signatures-right', $source);
    }

    #[Test]
    public function blank_rows_decrease_as_item_count_grows_and_wrapping_consumes_space(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        $this->assertStringContainsString('$baseFillerRows = match (true)', $source);
        $this->assertStringContainsString('$itemCount === 1 => 5', $source);
        $this->assertStringContainsString('$itemCount <= 6 => 3', $source);
        $this->assertStringContainsString('$itemCount <= 12 => 1', $source);
        $this->assertStringContainsString('$wrapPenalty', $source);
        $this->assertStringContainsString('$fillerRowCount', $source);
        $this->assertStringNotContainsString('$targetVisibleRows', $source);
        $this->assertStringNotContainsString('$minimumVisibleRows = 9;', $source);
    }

    #[Test]
    public function nothing_follows_is_a_formal_merged_terminal_row_on_the_borrower_side(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        $this->assertMatchesRegularExpression(
            '/for \(\$i = 0; \$i < \$fillerRowCount; \$i\+\+\).*?nothing-follows.*?colspan="5" class="nothing-follows-cell".*?NOTHING_FOLLOWS_MARKER/s',
            $source
        );
        $this->assertStringContainsString('letter-spacing: .45pt;', $source);
        $this->assertStringContainsString('white-space: nowrap !important;', $source);
    }

    #[Test]
    public function continuation_pages_repeat_table_headings_and_keep_the_closing_block_together(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        $this->assertStringContainsString('.borrower-items thead { display: table-header-group; }', $source);
        $this->assertStringContainsString('.borrower-closing-block { page-break-inside: avoid; }', $source);
    }

    #[Test]
    public function only_borrowed_by_and_approved_by_are_prefilled_with_esignatures(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        $this->assertStringContainsString('<td><div class="esign">{$borrowerSignature}</div></td>', $source);
        $this->assertStringContainsString('<td><div class="esign">{$approverSignature}</div></td>', $source);
        $this->assertSame(2, substr_count($source, 'class="esign"'));
    }

    #[Test]
    public function release_and_return_values_are_not_auto_filled_into_the_single_print_slip(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        $this->assertStringNotContainsString('$custody->released_at?->format', $source);
        $this->assertStringNotContainsString('returnInspectionData($custody)', $source);
        $this->assertStringNotContainsString('$remarksByCustodyLineId', $source);
    }

    #[Test]
    public function rev_4_does_not_load_generic_borrower_form_css_that_can_change_its_spacing(): void
    {
        $source = $this->borrowerSlipMethod($this->documentServiceSource());

        $this->assertStringNotContainsString('$this->officialCss().$rev4Css', $source);
        $this->assertStringContainsString('$rev4Css.</head><body>', str_replace(["'", '"'], '', $source));
    }

    private function borrowerSlipMethod(string $source): string
    {
        $start = strpos($source, 'private function borrowerSlipHtml(');
        $end = strpos($source, 'public function conditionalForm(', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }
}
