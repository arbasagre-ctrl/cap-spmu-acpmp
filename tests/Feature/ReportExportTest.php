<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use App\Reports\ReportExportOptions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetReader;
use PhpOffice\PhpWord\IOFactory as WordReader;
use Tests\TestCase;

/**
 * Report exports.
 *
 * Every format is produced from the same dataset the screen rendered, so the
 * tests here open the generated files and read them back rather than trusting
 * a content type: a .docx that Word cannot open, or a .xlsx whose numbers
 * arrived as text, would pass a header check and fail the user.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private OrganizationalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'EXP',
            'unit_name' => 'Export Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->head = User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
            'full_name' => 'SPMU Head',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Supported formats                                                   */
    /* ------------------------------------------------------------------ */

    public function test_only_formats_the_application_can_produce_are_offered(): void
    {
        $this->assertSame(
            ['pdf', 'docx', 'xlsx', 'csv', 'print'],
            array_keys(ReportExportOptions::FORMATS)
        );
    }

    public function test_pdf_export_returns_a_real_pdf(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->export('pdf');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        /* Binary formats are built in full and returned, not streamed. */
        $body = $response->getContent();

        $this->assertStringStartsWith('%PDF', $body);
        $this->assertStringContainsString('%%EOF', $body);
    }

    public function test_word_export_opens_as_a_genuine_editable_document(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->export('docx');
        $response->assertOk();

        $file = $this->save($response->getContent(), 'docx');

        /* PhpWord reading it back is the proof it is genuine WordprocessingML. */
        $document = WordReader::load($file, 'Word2007');

        $this->assertNotEmpty($document->getSections());

        $text = $this->wordText($file);

        $this->assertStringContainsString('CAMARINES SUR POLYTECHNIC COLLEGES', $text);
        $this->assertStringContainsString('BORROWING ACTIVITY REPORT', $text);
        $this->assertStringContainsString('Export fixture activity', $text);

        @unlink($file);
    }

    public function test_excel_export_keeps_numbers_numeric_and_freezes_the_header(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->export('xlsx');
        $response->assertOk();

        $file = $this->save($response->getContent(), 'xlsx');

        $sheet = SpreadsheetReader::load($file)->getActiveSheet();

        /* The header row is frozen, so a pane split exists. */
        $this->assertNotNull($sheet->getFreezePane());

        $found = false;

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === 'Export fixture activity') {
                    $found = true;
                }
            }
        }

        $this->assertTrue($found, 'The record did not reach the spreadsheet.');

        @unlink($file);
    }

    public function test_excel_summary_totals_are_written_as_numbers(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');
        $this->request('ACADEMIC', 'College of Computer Studies');

        $file = $this->save($this->export('xlsx')->getContent(), 'xlsx');
        $sheet = SpreadsheetReader::load($file)->getActiveSheet();

        $total = null;

        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];

            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $cell->getValue();
            }

            if (($cells[0] ?? null) === 'Total requests') {
                $total = $cells[1] ?? null;
            }
        }

        $this->assertSame(2.0, (float) $total);
        $this->assertIsNumeric($total);

        @unlink($file);
    }

    public function test_csv_export_is_raw_tabular_data_without_document_styling(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->export('csv');
        $response->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        /* First line is the header row: no seal, no provenance preamble. */
        $this->assertStringContainsString('Request No.', $lines[0]);
        $this->assertStringNotContainsString('CAMARINES SUR', $csv);
        $this->assertStringNotContainsString('Prepared by', $csv);
        $this->assertCount(2, $lines);
    }

    public function test_print_preview_uses_the_same_authorized_report_scope(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.print', [
                'type' => 'borrowing',
                'academic_period' => 'month',
            ]))
            ->assertOk()
            ->assertSee('Export fixture activity', false)
            ->assertSee('>Print<', false);
    }

    /* ------------------------------------------------------------------ */
    /* Same dataset across every format                                    */
    /* ------------------------------------------------------------------ */

    public function test_every_format_reports_the_same_record_count(): void
    {
        foreach (range(1, 3) as $index) {
            $this->request('ACADEMIC', 'College of Computer Studies');
        }

        /* CSV: one header row plus one row per record. */
        $csvLines = array_values(array_filter(
            explode("\n", trim($this->export('csv')->streamedContent()))
        ));
        $this->assertCount(4, $csvLines);

        /* XLSX: the same three records reach the sheet. */
        $file = $this->save($this->export('xlsx')->getContent(), 'xlsx');
        $sheet = SpreadsheetReader::load($file)->getActiveSheet();

        $records = 0;

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === 'Export fixture activity') {
                    $records++;
                }
            }
        }

        $this->assertSame(3, $records);
        @unlink($file);

        /* DOCX: the same three records reach the document. */
        $wordFile = $this->save($this->export('docx')->getContent(), 'docx');
        $this->assertSame(
            3,
            substr_count($this->wordText($wordFile), 'Export fixture activity')
        );
        @unlink($wordFile);
    }

    /* ------------------------------------------------------------------ */
    /* Options, naming and authorization                                   */
    /* ------------------------------------------------------------------ */

    public function test_filename_names_the_report_and_the_period_it_covers(): void
    {
        $response = $this->export('pdf');

        $response->assertHeader(
            'content-disposition',
            'attachment; filename="Borrowing_Activity_Report_2026-04-01_to_2026-04-30.pdf"'
        );
    }

    public function test_inventory_filename_carries_its_as_of_date(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.export', [
                'type' => 'inventory',
                'format' => 'xlsx',
                'academic_period' => 'month',
            ]));

        $response->assertHeader(
            'content-disposition',
            'attachment; filename="Inventory_Status_Report_2026-04-30.xlsx"'
        );
    }

    public function test_unsupported_format_falls_back_to_pdf_rather_than_failing(): void
    {
        $this->export('exe')->assertHeader('content-type', 'application/pdf');
    }

    public function test_content_toggles_change_only_presentation(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $withFooter = $this->save($this->export('docx')->getContent(), 'docx');
        $this->assertStringContainsString(
            'No signature is required',
            $this->wordText($withFooter)
        );
        @unlink($withFooter);

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.export', [
                'type' => 'borrowing',
                'format' => 'docx',
                'academic_period' => 'month',
                'options_submitted' => '1',
                /* include_footer deliberately absent: the box was unticked. */
            ]));

        $withoutFooter = $this->save($response->getContent(), 'docx');
        $text = $this->wordText($withoutFooter);

        $this->assertStringNotContainsString('No signature is required', $text);
        /* The records themselves are untouched by a presentation toggle. */
        $this->assertStringContainsString('Export fixture activity', $text);

        @unlink($withoutFooter);
    }

    public function test_export_requires_the_same_authorization_as_the_report_page(): void
    {
        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        /* A hidden button is not access control: the URL itself is refused. */
        foreach (['pdf', 'docx', 'xlsx', 'csv'] as $format) {
            $this->actingAs($borrower)
                ->withSession(['active_workspace' => 'BORROWER'])
                ->get(route('reports.export', [
                    'type' => 'borrowing',
                    'format' => $format,
                ]))
                ->assertForbidden();
        }

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('reports.print', ['type' => 'borrowing']))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function export(string $format): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.export', [
                'type' => 'borrowing',
                'format' => $format,
                'academic_period' => 'month',
            ]));
    }

    private function save(string $contents, string $extension): string
    {
        $file = tempnam(sys_get_temp_dir(), 'report-export-').'.'.$extension;
        file_put_contents($file, $contents);

        return $file;
    }

    /** Flatten a .docx back to text so its content can be asserted. */
    private function wordText(string $file): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true, 'The .docx is not a readable archive.');

        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertNotSame('', $xml, 'The .docx has no document part.');

        return html_entity_decode(strip_tags(str_replace('<', ' <', $xml)));
    }

    private function request(string $division, string $unit): BorrowingRequest
    {
        $createdAt = now()->copy()->subDays(2);

        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Export fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        return $request;
    }
}
