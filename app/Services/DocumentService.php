<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\OverdueCase;
use App\Models\RequestVersion;
use App\Models\Sanction;
use App\Models\SignatureSnapshot;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentService
{
    private const NOTHING_FOLLOWS_MARKER = '— NOTHING FOLLOWS —';

    public function __construct(
        private SimplePdfService $pdf,
        private ProtectedFileService $files,
        private DocumentTemplateDefinitionService $templateDefinitions,
        private DocumentTemplateRenderer $templateRenderer,
        private OfficeDraftTemplateRenderer $officeDrafts,
    ) {}

    /**
     * Every production document that has an uploaded custom template renders
     * through this one seam. A DOCX/XLSX Active layout renders through the
     * exact same Office Draft compiler its own preview used before
     * activation - the same compile() -> compileWorkingSource() path,
     * against the same immutable uploaded source - so activation can never
     * change what the document looks like. Anything else (PDF, or the
     * built-in system layout) keeps using the existing production renderer
     * completely unchanged.
     *
     * @param array<string,mixed> $data
     */
    private function renderCustomTemplate(DocumentTemplate $customTemplate, array $data): string
    {
        if ($this->officeDrafts->isOfficeFormat($customTemplate)) {
            $context = OfficialFormRuntimeContextService::forRuntimeData((string) $customTemplate->document_type, $data);

            return $this->officeDrafts->render($customTemplate, $context);
        }

        return $this->templateRenderer->render($customTemplate, $data);
    }

    public function requestLetter(BorrowingRequest $request, bool $final = false): GeneratedDocument
    {
        $request->loadMissing('currentVersion');
        $version = $request->currentVersion;
        $type = $final ? 'APPROVED_REQUEST_LETTER' : 'REQUEST_LETTER';
        $status = $final ? 'FINAL' : 'DRAFT';

        return $this->saveHtml(
            $type,
            $this->requestLetterHtml($request, $final),
            $version,
            $request::class,
            $request->id,
            $status,
            $request->request_no.'-'.$type.'.pdf',
            true,
        );
    }

    public function requestLetterHtml(BorrowingRequest $request, bool $final = false, ?CarbonInterface $generatedAt = null): string
    {
        $request->loadMissing([
            'borrower.organizationalUnit',
            'accountableUnit',
            'currentVersion.items.inventoryItem.unit',
            'currentVersion.approvalSteps.approver',
        ]);
        $version = $request->currentVersion;
        if (! $version) {
            throw ValidationException::withMessages(['document' => 'A current request version is required to render the Borrowing Request Letter.']);
        }

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        if (! is_file($logoPath)) {
            throw ValidationException::withMessages(['document' => 'The institutional logo asset is unavailable.']);
        }

        $approvals = $final
            ? $version->approvalSteps
                ->filter(fn ($step) => $step->stage_code->value === 'SPMU')
                ->sortBy('sequence_no')
                ->map(fn ($step): array => [
                    'stage' => 'SPMU',
                    'name' => $step->approver?->full_name,
                    'decided_at_formal' => $this->formalDateTime($step->decided_at),
                    'decision' => $step->decision,
                ])->values()
            : collect();

        $generatedAt = ($generatedAt ?? now())->setTimezone('Asia/Manila');
        $borrowerDesignation = trim((string) $request->borrower->designation);
        if ($borrowerDesignation === ''
            || strcasecmp($borrowerDesignation, AccessClassification::BorrowerOnly->label()) === 0
            || $borrowerDesignation === $request->borrower->access_classification?->label()) {
            $borrowerDesignation = '';
        }

        return view('documents.borrowing-request-letter', [
            'borrowingRequest' => $request,
            'version' => $version,
            'isFinal' => $final,
            'documentStatus' => $final ? 'Fully Approved' : 'Draft',
            'visibleGeneratedAt' => $this->formalDateTime($generatedAt),
            'visibleNeededFrom' => $this->formalDateTime($version->needed_from),
            'visibleReturnDueAt' => $this->formalDateTime($version->return_due_at),
            'visibleSignedAt' => $this->formalDateTime($version->signed_at),
            'visibleDownloadDeadline' => $this->formalDateTime($request->download_deadline_at),
            'logoDataUri' => 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)),
            'borrowerDesignation' => $borrowerDesignation,
            'approvals' => $approvals,
        ])->render();
    }

    /** @return array{document: GeneratedDocument, generated: bool} */
    public function recoverMissingDraftRequestLetter(BorrowingRequest $request): array
    {
        return DB::transaction(function () use ($request): array {
            $lockedRequest = BorrowingRequest::query()->lockForUpdate()->findOrFail($request->id);
            $lockedRequest->loadMissing('currentVersion');

            if ($lockedRequest->status !== RequestStatus::Draft || ! $lockedRequest->currentVersion) {
                throw ValidationException::withMessages([
                    'document' => 'Only a draft request with a current version can recover a missing preview.',
                ]);
            }

            $existing = GeneratedDocument::query()
                ->where('request_version_id', $lockedRequest->currentVersion->id)
                ->where('document_type', 'REQUEST_LETTER')
                ->where('status', 'DRAFT')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing) {
                return ['document' => $existing, 'generated' => false];
            }

            return [
                'document' => $this->requestLetter($lockedRequest, false),
                'generated' => true,
            ];
        }, 3);
    }

    public function borrowerSlip(CustodyTransaction $custody): GeneratedDocument
    {
        $this->assertFinalApprovalForOperationalDocument($custody);

        $custody->loadMissing([
            'request.borrower',
            'request.currentVersion.borrowerSignature.file',
            'lines.requestItem.inventoryItem',
            'returns.receivedBy',
            'returns.inspectionSignature.file',
            'returns.lines.custodyLine.requestItem',
            'releasedBy',
        ]);

        /*
         * Borrower Slip is generated immediately after SPMU approval so the
         * borrower can download, print, and bring the physical form to SPMU.
         * The approved custody quantities are already fixed at this point.
         * Item preparation later validates this approved document and the
         * same approved quantities. Controlled copies may still be replaced
         * after release or return so their operational sections stay current.
         */

        $customTemplate = $this->activeUploadedTemplate('BORROWER_SLIP');
        if ($customTemplate) {
            $bytes = $this->renderCustomTemplate($customTemplate, $this->borrowerSlipRenderData($custody));
            $this->supersede($custody, 'BORROWER_SLIP', 'Replaced by the latest controlled operational copy.');

            return $this->saveRenderedTemplate(
                $customTemplate,
                'BORROWER_SLIP',
                $bytes,
                $custody->request->currentVersion,
                $custody::class,
                $custody->id,
                'FINAL',
                $custody->custody_no.'-BORROWER-SLIP.pdf',
            );
        }

        $this->supersede($custody, 'BORROWER_SLIP', 'Replaced by the latest controlled operational copy.');

        return $this->saveHtml(
            'BORROWER_SLIP',
            $this->borrowerSlipHtml($custody),
            $custody->request->currentVersion,
            $custody::class,
            $custody->id,
            'FINAL',
            $custody->custody_no.'-BORROWER-SLIP.pdf',
        );
    }

    private function borrowerSlipHtml(CustodyTransaction $custody, bool $documentShell = true, int $pageNumber = 1, int $pageCount = 1): string
    {
        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $approval = $this->approvalSignatory($version);

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        $logo = is_file($logoPath)
            ? '<img src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo">'
            : '<div class="borrower-logo-fallback">CSPC</div>';

        $borrowerName = e((string) $borrower->full_name);
        $borrowerDesignationValue = trim((string) $borrower->designation);
        if ($borrowerDesignationValue === ''
            || strcasecmp($borrowerDesignationValue, AccessClassification::BorrowerOnly->label()) === 0
            || $borrowerDesignationValue === $borrower->access_classification?->label()) {
            $borrowerDesignationValue = '';
        }
        $borrowerDesignation = e($borrowerDesignationValue);

        $borrowerSignature = $this->signatureImage($version?->borrowerSignature, 72, 22);
        $approverName = e((string) $approval['name']);
        $approverDesignation = e((string) $approval['designation']);
        $approverSignature = $this->signatureImage($approval['snapshot'], 72, 22);

        $formDate = $approval['signed_at']
            ? $approval['signed_at']->format('m/d/Y')
            : now()->format('m/d/Y');

        /*
         * Rev. 4 is a single travelling physical form. It is generated once,
         * immediately after final approval. Release/return values and the
         * operational signature blocks stay blank so the same printed sheet
         * can be physically completed at pickup and return. The system keeps
         * its own release/return timestamps in the custody audit trail and
         * must not regenerate a second official Borrower Slip later.
         */

        $itemCount = max(1, $custody->lines->count());
        $purposeRaw = trim((string) ($version?->purpose_event ?: ''));
        $purpose = e($purposeRaw);
        $expectedReturn = $custody->due_at?->format('m/d/Y') ?? '';

        $contentWeight = 0;
        foreach ($custody->lines as $line) {
            $descriptionRaw = trim((string) $line->requestItem?->description_snapshot);
            $contentWeight += max(
                1,
                (int) ceil(strlen($descriptionRaw) / 34),
                (int) ceil(strlen($purposeRaw) / 26),
            );
        }

        // Keep a modest handwritten writing area when there are only a few
        // approved items. Blank rows intentionally decrease as the item list
        // grows so short requests stay formal and compact, while long requests
        // are allowed to continue naturally onto another page.
        $baseFillerRows = match (true) {
            $itemCount === 1 => 5,
            $itemCount <= 3 => 4,
            $itemCount <= 6 => 3,
            $itemCount <= 9 => 2,
            $itemCount <= 12 => 1,
            default => 0,
        };

        $wrapPenalty = max(0, $contentWeight - $itemCount);
        $fillerRowCount = max(0, $baseFillerRows - min(2, $wrapPenalty));
        $compactLayout = $itemCount <= 6 && $contentWeight <= ($itemCount + 4);
        $layoutClass = $compactLayout ? ' compact' : ' extended';

        $tableFont = match (true) {
            $itemCount <= 4 => '6.5pt',
            $itemCount <= 7 => '6.1pt',
            $itemCount <= 10 => '5.8pt',
            default => '5.5pt',
        };
        $rowHeight = match (true) {
            $itemCount <= 4 => '14.5pt',
            $itemCount <= 7 => '13.5pt',
            $itemCount <= 10 => '12.5pt',
            default => '11.5pt',
        };
        $cellPadding = $itemCount <= 7 ? '2pt 3pt' : '1.5pt 2pt';
        $signatureFont = $itemCount <= 7 ? '5.8pt' : '5.4pt';

        $itemRows = '';
        foreach ($custody->lines as $line) {
            $quantityValue = (float) $line->quantity_to_receive;
            $quantity = floor($quantityValue) === $quantityValue
                ? (string) (int) $quantityValue
                : rtrim(rtrim(number_format($quantityValue, 3, '.', ''), '0'), '.');
            $unit = e((string) $line->requestItem?->unit_snapshot);
            $description = e((string) $line->requestItem?->description_snapshot);

            $itemRows .=
                '<tr class="actual-item">'
                .'<td class="c-qty">'.$quantity.'</td>'
                .'<td class="c-unit">'.$unit.'</td>'
                .'<td class="c-desc">'.$description.'</td>'
                .'<td class="c-purpose">'.$purpose.'</td>'
                .'<td class="c-expected">'.$expectedReturn.'</td>'
                .'<td class="split-gap"></td>'
                .'<td class="c-release-date"></td>'
                .'<td class="c-release-time"></td>'
                .'<td class="c-return-date"></td>'
                .'<td class="c-remarks"></td>'
                .'</tr>';
        }

        for ($i = 0; $i < $fillerRowCount; $i++) {
            $itemRows .=
                '<tr class="filler-row">'
                .'<td class="c-qty"></td>'
                .'<td class="c-unit"></td>'
                .'<td class="c-desc"></td>'
                .'<td class="c-purpose"></td>'
                .'<td class="c-expected"></td>'
                .'<td class="split-gap"></td>'
                .'<td class="c-release-date"></td>'
                .'<td class="c-release-time"></td>'
                .'<td class="c-return-date"></td>'
                .'<td class="c-remarks"></td>'
                .'</tr>';
        }

        // Close the borrower item list with one formal merged terminal row.
        // This reads as a controlled-document marker instead of looking like a
        // value that belongs only to the Article/Description column.
        $itemRows .=
            '<tr class="nothing-follows">'
            .'<td colspan="5" class="nothing-follows-cell">'.self::NOTHING_FOLLOWS_MARKER.'</td>'
            .'<td class="split-gap"></td>'
            .'<td class="c-release-date"></td>'
            .'<td class="c-release-time"></td>'
            .'<td class="c-return-date"></td>'
            .'<td class="c-remarks"></td>'
            .'</tr>';

        $pageHeader = <<<HTML
<div class="borrower-page-header-inner">
    <table class="borrower-header">
        <tr>
            <td class="borrower-logo-cell">{$logo}</td>
            <td class="borrower-school">
                <div>Republic of the Philippines</div>
                <strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>
                <div>Nabua, Camarines Sur</div>
            </td>
        </tr>
    </table>

    <div class="borrower-blue-rule"></div>
    <div class="borrower-title">BORROWER'S SLIP</div>
</div>
HTML;

        $pageFooter = <<<HTML
<div class="borrower-control-footer">
    <div class="footer-left">Effectivity Date&nbsp;&nbsp;&nbsp;<strong>September 2026</strong></div>
    <div class="footer-revision">Rev. 4</div>
    <div class="footer-right">Page {$pageNumber} of {$pageCount}</div>
</div>
HTML;

        $inlineHeader = $documentShell ? '' : '<div class="borrower-page-header-inline">'.$pageHeader.'</div>';
        $inlineFooter = $documentShell ? '' : '<div class="borrower-page-footer-inline">'.$pageFooter.'</div>';

        $body = <<<HTML
<section class="borrower-slip-rev4{$layoutClass}">
    {$inlineHeader}

    <div class="borrower-date">Date: <span>{$formDate}</span></div>

    <div class="borrower-classification">
        <span class="borrower-box checked">✓</span> Employee
        <span class="borrower-others"><span class="borrower-box"></span> Others <span class="borrower-others-line"></span></span>
    </div>

    <table class="borrower-section-headings">
        <tr>
            <td class="borrower-heading-left"><strong>For borrower:</strong></td>
            <td class="heading-gap"></td>
            <td class="borrower-heading-right"><strong>For Supply Staff:</strong></td>
        </tr>
        <tr>
            <td class="borrower-intro">I acknowledge to have received from the Supply and Property Management Unit the following:</td>
            <td class="heading-gap"></td>
            <td></td>
        </tr>
    </table>

    <table class="borrower-items">
        <colgroup>
            <col class="col-qty">
            <col class="col-unit">
            <col class="col-desc">
            <col class="col-purpose">
            <col class="col-expected">
            <col class="col-gap">
            <col class="col-release-date">
            <col class="col-release-time">
            <col class="col-return-date">
            <col class="col-remarks">
        </colgroup>
        <thead>
            <tr>
                <th>Qty.</th>
                <th>Unit</th>
                <th>Article/Description</th>
                <th>Purpose</th>
                <th>Expected Date of<br>Return</th>
                <th class="split-gap"></th>
                <th>Date Released</th>
                <th>Release Time</th>
                <th>Date Returned</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>{$itemRows}</tbody>
    </table>

    <div class="borrower-closing-block">
    <div class="borrower-terms">
        <strong class="section-label">TERMS AND CONDITIONS:</strong>
        <div>That I (the borrower) shall:</div>
        <ol>
            <li>personally return <strong>IMMEDIATELY</strong> after use the borrowed items listed above to make it/them available for other users;</li>
            <li>willing to accept any <strong>ACCOUNTABILITY</strong> for the borrowed property; and</li>
            <li>be held responsible for <strong>LOSS</strong> and <strong>DAMAGES</strong> while the items are in my custody.</li>
        </ol>
    </div>

    <div class="borrower-note">
        <strong class="section-label">NOTE:</strong>
        <ol>
            <li>Students are not allowed to borrow equipment/materials. The instructor shall receive the item/s and sign as the borrower.</li>
            <li>Releasing and returning of items are within school days ONLY from 1:00 to 4:00 in the afternoon.</li>
        </ol>
    </div>

    <table class="borrower-signatures">
        <colgroup>
            <col class="sig-label-col">
            <col class="sig-left-col"><col class="sig-left-col"><col class="sig-left-col"><col class="sig-left-col">
            <col class="sig-gap-col">
            <col class="sig-right-col"><col class="sig-right-col"><col class="sig-right-col">
        </colgroup>
        <thead>
            <tr>
                <th class="sig-row-label"></th>
                <th>Borrowed by:</th>
                <th>Approved by:</th>
                <th>Issued by:</th>
                <th>Received by:</th>
                <th class="signature-gap"></th>
                <th>Returned by:</th>
                <th>Received by:</th>
                <th>Verified by:</th>
            </tr>
        </thead>
        <tbody>
            <tr class="signature-row">
                <td class="sig-row-label">Signature</td>
                <td><div class="esign">{$borrowerSignature}</div></td>
                <td><div class="esign">{$approverSignature}</div></td>
                <td></td>
                <td></td>
                <td class="signature-gap"></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td class="sig-row-label">Printed Name</td>
                <td><strong>{$borrowerName}</strong></td>
                <td><strong>{$approverName}</strong></td>
                <td></td><td></td>
                <td class="signature-gap"></td>
                <td></td><td></td><td></td>
            </tr>
            <tr>
                <td class="sig-row-label">Designation</td>
                <td>{$borrowerDesignation}</td>
                <td>{$approverDesignation}</td>
                <td></td><td></td>
                <td class="signature-gap"></td>
                <td></td><td></td><td></td>
            </tr>
            <tr>
                <td class="sig-row-label">Date</td>
                <td>{$formDate}</td>
                <td>{$formDate}</td>
                <td></td><td></td>
                <td class="signature-gap"></td>
                <td></td><td></td><td></td>
            </tr>
        </tbody>
    </table>

    </div>

    {$inlineFooter}
</section>
HTML;

        $rev4Css = <<<CSS
<style>
    @page { size: A4 landscape; margin: 70pt 16pt 30pt; }
    * { box-sizing: border-box; }
    body { margin: 0; color: #111; font-family: DejaVu Sans, Arial, sans-serif; }

    .borrower-slip-rev4 {
        width: 100%;
        color: #111;
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 6.6pt;
        line-height: 1.12;
    }
    .borrower-slip-rev4.compact,
    .borrower-slip-rev4.extended {
        position: relative;
        padding: 0;
    }

    .borrower-page-header {
        position: fixed;
        top: -58pt;
        left: 0;
        right: 0;
        height: 54pt;
    }
    .borrower-page-header-inline { margin-bottom: 8pt; }
    .borrower-page-header-inner { width: 100%; }

    .borrower-header { width: 100%; border-collapse: collapse; margin: 0; }
    .borrower-header td { border: 0; padding: 0; vertical-align: top; }
    .borrower-logo-cell { width: 42pt; padding-right: 6pt !important; }
    .borrower-logo-cell img { display: block; width: 29pt; height: 29pt; object-fit: contain; }
    .borrower-logo-fallback { width: 29pt; height: 29pt; font-weight: bold; font-size: 6pt; }
    .borrower-school { padding-top: 1pt !important; font-size: 6.3pt; line-height: 1.12; text-align: left; }
    .borrower-school strong { display: block; font-size: 7.8pt; line-height: 1.08; }
    .borrower-blue-rule { border-top: .9pt solid #78a6c8; margin: 7pt 0 5pt; }
    .borrower-title { text-align: center; font-weight: bold; font-size: 8.7pt; margin: 0; letter-spacing: .08pt; }

    .borrower-date { margin: 0 0 4pt; font-size: 6.5pt; }
    .borrower-date span { display: inline-block; width: 60pt; padding-bottom: 1pt; border-bottom: .55pt solid #111; text-align: center; }
    .borrower-classification { margin: 0 0 8pt 45pt; font-size: 6.6pt; }
    .borrower-box { display: inline-block; width: 7pt; height: 7pt; margin-right: 3pt; border: .55pt solid #111; line-height: 6pt; text-align: center; vertical-align: middle; font-size: 5.8pt; }
    .borrower-others { margin-left: 30pt; }
    .borrower-others-line { display: inline-block; width: 50pt; height: 7pt; vertical-align: bottom; border-bottom: .55pt solid #111; }

    .borrower-section-headings { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 0 0 3pt; font-size: 6.5pt; }
    .borrower-section-headings td { border: 0; padding: 0; vertical-align: bottom; }
    .borrower-heading-left { width: 56%; }
    .borrower-section-headings .heading-gap { width: 4%; }
    .borrower-heading-right { width: 40%; padding-left: 2pt !important; }
    .borrower-intro { padding-top: 7pt !important; font-size: 6.2pt; font-weight: normal; }

    .borrower-items { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 0; font-size: {$tableFont}; line-height: 1.08; }
    .borrower-items th, .borrower-items td { border: .55pt solid #777; padding: {$cellPadding}; vertical-align: middle; white-space: normal; overflow-wrap: anywhere; word-break: normal; }
    .borrower-items th { height: 20pt; text-align: center; font-weight: bold; font-size: 6.0pt; }
    .borrower-items tbody td { height: {$rowHeight}; }
    /* Widths live on the colgroup only.  Dompdf can re-balance a table when
       the same percentage width is repeated on both <col> and <td>; that was
       why the Supply Staff grid and the Returned-by signature grid could look
       horizontally offset even though their percentages added to 100. */
    .borrower-items col.col-qty { width: 5.3%; }
    .borrower-items col.col-unit { width: 6.7%; }
    .borrower-items col.col-desc { width: 18.0%; }
    .borrower-items col.col-purpose { width: 15.5%; }
    .borrower-items col.col-expected { width: 10.5%; }
    .borrower-items col.col-gap { width: 4%; }
    .borrower-items col.col-release-date { width: 9.5%; }
    .borrower-items col.col-release-time { width: 9.5%; }
    .borrower-items col.col-return-date { width: 9.5%; }
    .borrower-items col.col-remarks { width: 11.5%; }
    .borrower-items .c-qty, .borrower-items .c-unit,
    .borrower-items .c-expected, .borrower-items .c-release-date,
    .borrower-items .c-release-time, .borrower-items .c-return-date { text-align: center; }
    .borrower-items .c-desc, .borrower-items .c-purpose, .borrower-items .c-remarks { text-align: left; }
    .borrower-items .col-gap, .borrower-items .split-gap { border: 0 !important; background: #fff; padding: 0 !important; }
    .borrower-items thead { display: table-header-group; }
    .borrower-items tr { page-break-inside: avoid; }
    .nothing-follows td { background: #fff; }
    .nothing-follows-cell {
        text-align: center !important;
        font-weight: bold;
        font-size: 5.7pt;
        letter-spacing: .45pt;
        white-space: nowrap !important;
    }

    .borrower-closing-block { page-break-inside: avoid; }

    .borrower-terms { margin-top: 6pt; font-size: 5.5pt; line-height: 1.1; }
    .borrower-note { margin-top: 4pt; font-size: 5.4pt; line-height: 1.08; }
    .section-label { display: block; margin-bottom: 1pt; font-size: 6.1pt; }
    .borrower-terms ol, .borrower-note ol { margin: 0 0 0 14pt; padding: 0; }
    .borrower-terms li, .borrower-note li { margin: 0; padding: 0; }

    /* One table with a borderless separator creates the exact visual split of
       the official left and right signature sections without a nested table
       row that Dompdf can push wholesale onto page 2. */
    .borrower-signatures { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 8pt; font-size: {$signatureFont}; line-height: 1.12; }
    .borrower-signatures th, .borrower-signatures td { height: 11pt; border: .55pt solid #777; padding: 1.8pt 2.8pt; text-align: center; vertical-align: middle; white-space: normal; overflow-wrap: anywhere; }
    .borrower-signatures th { height: 9pt; font-weight: bold; }
    /* Match the exact 56% / 4% / 40% geometry used by the item grid above.
       Keeping the widths on <col> makes the right signature block start on
       exactly the same vertical line as Date Released / Release Time / Date
       Returned / Remarks, regardless of how many request rows are generated. */
    .borrower-signatures col.sig-label-col { width: 11%; }
    .borrower-signatures col.sig-left-col { width: 11.25%; }
    .borrower-signatures col.sig-gap-col { width: 4%; }
    .borrower-signatures col.sig-right-col { width: 13.333333%; }
    .borrower-signatures .sig-row-label { text-align: left; }
    .borrower-signatures .signature-gap { border: 0 !important; background: #fff; padding: 0 !important; }
    .borrower-signatures .signature-row td { height: 22pt; }
    .borrower-signatures .esign { height: 18pt; text-align: center; }
    .borrower-signatures .esign img { display: block; max-width: 72pt; max-height: 17pt; margin: 0 auto; object-fit: contain; }

    .borrower-page-footer {
        position: fixed;
        left: 0;
        right: 0;
        bottom: -21pt;
        height: 18pt;
    }
    .borrower-page-footer-inline { margin-top: 18pt; }
    .borrower-control-footer {
        width: 100%;
        border-top: .9pt solid #78a6c8;
        padding-top: 5pt;
        font-size: 5.1pt;
        line-height: 1;
        position: relative;
        min-height: 12pt;
    }
    .borrower-control-footer .footer-left { position: absolute; left: 0; top: 5pt; width: 45%; text-align: left; }
    .borrower-control-footer .footer-revision { position: absolute; left: 45%; top: 5pt; width: 10%; text-align: center; }
    .borrower-control-footer .footer-right { position: absolute; right: 0; top: 5pt; width: 45%; }
</style>
CSS;

        if (! $documentShell) {
            $packetCss = str_replace('@page { size: A4 landscape; margin: 70pt 16pt 30pt; }', '', $rev4Css);

            return $packetCss.$body;
        }

        // Do not load the generic official-form stylesheet here. Rev. 4 has a
        // controlled landscape layout whose exact spacing would otherwise be
        // altered by shared .borrower-* rules intended for older templates.
        return '<!doctype html><html><head><meta charset="utf-8">'.$rev4Css.'</head><body>'
            .'<div class="borrower-page-header">'.$pageHeader.'</div>'
            .'<div class="borrower-page-footer">'.$pageFooter.'</div>'
            .$body
            .'</body></html>';
    }



    public function conditionalForm(CustodyTransaction $custody, string $type): GeneratedDocument
    {
        $type = strtoupper(trim($type));

        $this->assertFinalApprovalForOperationalDocument($custody);

        $custody->loadMissing([
            'request.borrower',
            'request.borrower.organizationalUnit',
            'request.currentVersion',
            'request.currentVersion.borrowerSignature.file',
            'request.currentVersion.approvalSteps.approver',
            'request.currentVersion.approvalSteps.signatureSnapshot.file',
            'lines.requestItem.inventoryItem',
            'lines.laundryJobLine',
            'gatePass.preparedVerifier',
            'gatePass.preparedVerifierSignature.file',
            'gatePass.approver',
            'gatePass.approverSignature.file',
            'gatePass.delegation',
            'releaseSignature.file',
            'borrower',
            'laundryJob.formVerifier',
        ]);

        $hasOffCampusProperty = $custody->lines->contains(
            fn ($line) =>
                $line->requestItem?->use_location === 'OFF_CAMPUS'
                && (float) $line->quantity_to_receive > 0
        );

        $hasLaundryProperty = $custody->lines->contains(
            fn ($line) =>
                (bool) $line->requestItem?->inventoryItem?->laundry_required
                && (float) $line->quantity_to_receive > 0
        );

        if ($type === 'GATE_PASS' && ! $hasOffCampusProperty) {
            throw ValidationException::withMessages([
                'document' => 'A Gate Pass is generated only when the custody includes off-campus property.',
            ]);
        }

        if ($type === 'LAUNDRY_FORM' && ! $hasLaundryProperty) {
            throw ValidationException::withMessages([
                'document' => 'A Laundry Form is generated only when the custody includes laundry-required property.',
            ]);
        }

        if (! in_array($type, ['GATE_PASS', 'LAUNDRY_FORM'], true)) {
            throw ValidationException::withMessages([
                'document' => 'Unsupported physical custody form type.',
            ]);
        }

        $customTemplate = $this->activeUploadedTemplate($type);
        if ($customTemplate) {
            $data = $type === 'LAUNDRY_FORM'
                ? $this->laundryFormRenderData($custody)
                : $this->gatePassRenderData($custody);
            $bytes = $this->renderCustomTemplate($customTemplate, $data);
            $this->supersede($custody, $type, 'Replaced by the latest generated physical form.');

            return $this->saveRenderedTemplate(
                $customTemplate,
                $type,
                $bytes,
                $custody->request->currentVersion,
                $custody::class,
                $custody->id,
                'FINAL',
                $custody->custody_no.'-'.$type.'.pdf',
            );
        }

        $this->supersede($custody, $type, 'Replaced by the latest generated physical form.');

        if ($type === 'LAUNDRY_FORM') {
            return $this->saveHtml(
                'LAUNDRY_FORM',
                $this->laundryFormHtml($custody),
                $custody->request->currentVersion,
                $custody::class,
                $custody->id,
                'FINAL',
                $custody->custody_no.'-LAUNDRY_FORM.pdf',
            );
        }

        return $this->saveHtml(
            'GATE_PASS',
            $this->gatePassHtml($custody),
            $custody->request->currentVersion,
            $custody::class,
            $custody->id,
            'FINAL',
            $custody->custody_no.'-GATE_PASS.pdf',
        );
    }

    private function gatePassHtml(CustodyTransaction $custody, bool $documentShell = true, int $pageNumber = 1, int $pageCount = 1): string
    {
        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $gatePass = $custody->gatePass;
        $templateConfig = $this->templateDefinitions->resolve('GATE_PASS');

        $formCode = e($templateConfig['form_code']);
        $gpNoLabel = e($templateConfig['gp_no_label']);
        $dateLabel = e($templateConfig['date_label']);
        $documentTitle = e($templateConfig['title']);
        $toLabel = e($templateConfig['to_label']);
        $toValue = e($templateConfig['to_value']);
        $introPrefix = e($templateConfig['intro_prefix']);
        $introSuffix = e($templateConfig['intro_suffix']);
        $quantityLabel = e($templateConfig['quantity_label']);
        $unitLabel = e($templateConfig['unit_label']);
        $descriptionLabel = e($templateConfig['description_label']);
        $purposeLabel = e($templateConfig['purpose_label']);
        $remarksLabel = e($templateConfig['remarks_label']);
        $bearerLabel = e($templateConfig['bearer_label']);
        $verifiedByLabel = e($templateConfig['verified_by_label']);
        $verifiedRole = e($templateConfig['verified_role']);
        $approvedByLabel = e($templateConfig['approved_by_label']);
        $approvedRole = e($templateConfig['approved_role']);
        $releasedByLabel = e($templateConfig['released_by_label']);
        $guardRole = e($templateConfig['guard_role']);
        $releasedDateLabel = e($templateConfig['released_date_label']);
        $releasedTimeLabel = e($templateConfig['released_time_label']);
        $footerEffectivity = e($templateConfig['footer_effectivity']);
        $footerRevision = e($templateConfig['footer_revision']);

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        $logo = is_file($logoPath)
            ? '<img src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo">'
            : '<div class="gp-logo-fallback">CSPC</div>';

        $borrowerName = e((string) $borrower->full_name);
        $purpose = e((string) ($version?->purpose_event ?: ''));
        $custodyNumber = e((string) $custody->custody_no);
        $formDate = $custody->scheduled_release_at
            ? $custody->scheduled_release_at->format('m-d-Y')
            : ($gatePass?->approved_at?->format('m-d-Y') ?: now()->format('m-d-Y'));

        $verifiedName = $gatePass?->preparedVerifier?->full_name
            ? e((string) $gatePass->preparedVerifier->full_name)
            : 'SPMU ACTION OFFICER';
        $approvedName = $gatePass?->approver?->full_name
            ? e((string) $gatePass->approver->full_name)
            : 'SPMU HEAD';

        $verifiedSignature = $this->centeredSignatureImage($gatePass?->preparedVerifierSignature, 130, 24);
        $approvedSignature = $this->centeredSignatureImage($gatePass?->approverSignature, 130, 24);
        $borrowerSignature = $this->centeredSignatureImage($version?->borrowerSignature, 130, 24);

        $offCampusLines = $custody->lines->filter(
            fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
                && (float) $line->quantity_to_receive > 0
        );

        $itemRows = '';
        foreach ($offCampusLines as $line) {
            $quantity = (float) $line->quantity_to_receive;
            $quantityText = floor($quantity) === $quantity
                ? (string) (int) $quantity
                : rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
            $unit = e((string) $line->requestItem?->unit_snapshot);
            $description = e((string) $line->requestItem?->description_snapshot);

            $itemRows .= '<tr class="gp-item-row">'
                .'<td>'.$quantityText.'</td>'
                .'<td>'.$unit.'</td>'
                .'<td class="gp-description">'.$description.'</td>'
                .'</tr>';
        }

        // Keep short Gate Passes balanced without filling the page with empty
        // lines. The controlled closure marker is always the final table row.
        $blankRows = max(0, 5 - $offCampusLines->count());
        for ($i = 0; $i < $blankRows; $i++) {
            $itemRows .= '<tr class="gp-item-row gp-blank-row"><td></td><td></td><td></td></tr>';
        }
        if ($offCampusLines->isNotEmpty()) {
            $itemRows .= '<tr class="gp-nothing-follows"><td colspan="3">'.self::NOTHING_FOLLOWS_MARKER.'</td></tr>';
        }

        $pageHeader = <<<HTML
<div class="gp-header-inner">
    <table class="gp-header-table">
        <tr>
            <td class="gp-logo">{$logo}</td>
            <td class="gp-school">
                <div>Republic of the Philippines</div>
                <strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>
                <div>Nabua, Camarines Sur</div>
            </td>
            <td class="gp-form-code">{$formCode}</td>
        </tr>
    </table>
    <div class="gp-blue-rule"></div>
    <div class="gp-title">{$documentTitle}</div>
</div>
HTML;

        $pageFooter = <<<HTML
<div class="gp-footer-inner">
    <div class="gp-footer-left">Effective Date&nbsp;&nbsp;<strong>{$footerEffectivity}</strong></div>
    <div class="gp-footer-center">{$footerRevision}</div>
    <div class="gp-footer-right">Page {$pageNumber} of {$pageCount}</div>
</div>
HTML;

        $inlineHeader = $documentShell ? '' : '<div class="gp-header-inline">'.$pageHeader.'</div>';
        $inlineFooter = $documentShell ? '' : '<div class="gp-footer-inline">'.$pageFooter.'</div>';

        $body = <<<HTML
<section class="gate-pass-form">
    {$inlineHeader}

    <table class="gp-meta">
        <tr>
            <td class="gp-meta-spacer"></td>
            <td class="gp-meta-label">{$gpNoLabel}</td>
            <td class="gp-meta-value">{$custodyNumber}</td>
        </tr>
        <tr>
            <td class="gp-meta-spacer"></td>
            <td class="gp-meta-label">{$dateLabel}</td>
            <td class="gp-meta-value">{$formDate}</td>
        </tr>
    </table>

    <table class="gp-to-row"><tr><td class="gp-to-label">{$toLabel}</td><td><strong>{$toValue}</strong></td></tr></table>

    <div class="gp-intro">
        {$introPrefix}
        <span class="gp-bearer-inline">{$borrowerName}</span>
        {$introSuffix}
    </div>

    <table class="gp-items">
        <colgroup><col class="gp-col-qty"><col class="gp-col-unit"><col class="gp-col-desc"></colgroup>
        <thead><tr><th>{$quantityLabel}</th><th>{$unitLabel}</th><th>{$descriptionLabel}</th></tr></thead>
        <tbody>
            {$itemRows}
            <tr><td colspan="3" class="gp-purpose"><strong>{$purposeLabel}</strong><span>{$purpose}</span></td></tr>
            <tr><td colspan="3" class="gp-remarks"><strong>{$remarksLabel}</strong><span></span></td></tr>
        </tbody>
    </table>

    <div class="gp-signature-section">
        <table class="gp-bearer-block"><tr>
            <td>
                <div class="gp-signature-label">{$bearerLabel}</div>
                <div class="gp-signature-line">{$borrowerSignature}</div>
                <div class="gp-signature-name">{$borrowerName}</div>
            </td>
            <td></td>
        </tr></table>

        <table class="gp-approval-block"><tr>
            <td>
                <div class="gp-signature-label">{$verifiedByLabel}</div>
                <div class="gp-signature-line">{$verifiedSignature}</div>
                <div class="gp-signature-name">{$verifiedName}</div>
                <div class="gp-signature-role">{$verifiedRole}</div>
            </td>
            <td>
                <div class="gp-signature-label">{$approvedByLabel}</div>
                <div class="gp-signature-line">{$approvedSignature}</div>
                <div class="gp-signature-name">{$approvedName}</div>
                <div class="gp-signature-role">{$approvedRole}</div>
            </td>
        </tr></table>

        <table class="gp-release-block"><tr>
            <td>
                <div class="gp-signature-label">{$releasedByLabel}</div>
                <div class="gp-signature-line gp-handwritten-line"></div>
                <div class="gp-signature-name">{$guardRole}</div>
                <div class="gp-release-meta"><strong>{$releasedDateLabel}</strong><span></span></div>
                <div class="gp-release-meta"><strong>{$releasedTimeLabel}</strong><span></span></div>
            </td>
            <td></td>
        </tr></table>
    </div>

    {$inlineFooter}
</section>
HTML;

        $css = <<<CSS
<style>
    @page { size: A4 portrait; margin: 72pt 34pt 38pt; }
    * { box-sizing: border-box; }
    body { margin: 0; color: #111; font-family: DejaVu Sans, Arial, sans-serif; }
    .gate-pass-form { width: 100%; font-family: DejaVu Sans, Arial, sans-serif; font-size: 8pt; line-height: 1.18; color: #111; }

    .gp-page-header { position: fixed; top: -60pt; left: 0; right: 0; height: 56pt; }
    .gp-header-inline { margin-bottom: 9pt; }
    .gp-header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .gp-header-table td { border: 0; padding: 0; vertical-align: middle; }
    .gp-logo { width: 40pt; padding-right: 7pt !important; }
    .gp-logo img { width: 30pt; height: 30pt; object-fit: contain; display: block; }
    .gp-logo-fallback { font-weight: bold; font-size: 7pt; }
    .gp-school { font-size: 6.5pt; line-height: 1.15; }
    .gp-school strong { display: block; font-size: 8.2pt; line-height: 1.08; }
    .gp-form-code { width: 110pt; text-align: right; vertical-align: bottom !important; font-size: 6.4pt; font-weight: bold; }
    .gp-blue-rule { border-top: .9pt solid #78a6c8; margin: 6pt 0 5pt; }
    .gp-title { text-align: center; font-size: 9.2pt; font-weight: bold; letter-spacing: .08pt; }

    .gp-meta { width: 100%; border-collapse: collapse; margin: 0 0 7pt; font-size: 7.2pt; }
    .gp-meta td { border: 0; padding: 1.2pt 0; }
    .gp-meta-spacer { width: 66%; }
    .gp-meta-label { width: 12%; font-weight: bold; white-space: nowrap; padding-right: 4pt !important; }
    .gp-meta-value { width: 22%; border-bottom: .55pt solid #111 !important; text-align: center; }

    .gp-to-row { width: 100%; border-collapse: collapse; margin: 2pt 0 6pt; font-size: 7.8pt; }
    .gp-to-row td { border: 0; padding: 0; vertical-align: top; }
    .gp-to-label { width: 34pt; font-weight: bold; }
    .gp-intro { margin: 0 0 9pt 34pt; text-align: justify; font-size: 7.6pt; line-height: 1.3; }
    .gp-bearer-inline { display: inline-block; min-width: 145pt; padding: 0 5pt 1pt; border-bottom: .55pt solid #111; text-align: center; font-weight: bold; }

    .gp-items { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 7.5pt; line-height: 1.16; }
    .gp-items th, .gp-items td { border: .55pt solid #555; padding: 3.2pt 4pt; vertical-align: middle; }
    .gp-items th { height: 20pt; text-align: center; font-size: 7.2pt; }
    .gp-col-qty { width: 13%; } .gp-col-unit { width: 15%; } .gp-col-desc { width: 72%; }
    .gp-item-row td { min-height: 20pt; text-align: center; }
    .gp-item-row .gp-description { text-align: left; padding-left: 6pt; }
    .gp-blank-row td { height: 18pt; }
    .gp-nothing-follows td { height: 18pt; text-align: center; font-size: 6.8pt; font-weight: bold; letter-spacing: .35pt; background: #fff; }
    .gp-purpose, .gp-remarks { min-height: 22pt; }
    .gp-purpose strong, .gp-remarks strong { display: inline-block; width: 52pt; }
    .gp-purpose span, .gp-remarks span { display: inline-block; padding-left: 4pt; }

    .gp-signature-section { page-break-inside: avoid; }
    .gp-bearer-block, .gp-approval-block, .gp-release-block { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .gp-bearer-block { margin-top: 12pt; }
    .gp-approval-block { margin-top: 13pt; }
    .gp-release-block { margin-top: 13pt; }
    .gp-bearer-block td, .gp-approval-block td, .gp-release-block td { width: 50%; border: 0; vertical-align: top; padding: 0; }
    .gp-bearer-block td:first-child, .gp-release-block td:first-child { padding-right: 18pt; }
    .gp-approval-block td:first-child { padding-right: 14pt; }
    .gp-approval-block td:last-child { padding-left: 14pt; }
    .gp-signature-label { font-size: 7.2pt; font-weight: bold; margin-bottom: 3pt; }
    .gp-signature-line { height: 25pt; border-bottom: .55pt solid #111; text-align: center; vertical-align: bottom; }
    .gp-signature-line img { max-height: 22pt; max-width: 120pt; object-fit: contain; }
    .gp-handwritten-line { height: 22pt; }
    .gp-signature-name { margin-top: 2.5pt; text-align: center; font-size: 7pt; font-weight: bold; }
    .gp-signature-role { margin-top: 1pt; text-align: center; font-size: 6.4pt; line-height: 1.15; }
    .gp-release-meta { margin-top: 5pt; font-size: 6.7pt; }
    .gp-release-meta strong { display: inline-block; width: 28pt; }
    .gp-release-meta span { display: inline-block; width: 88pt; border-bottom: .55pt solid #111; }

    .gp-page-footer { position: fixed; left: 0; right: 0; bottom: -24pt; height: 20pt; }
    .gp-footer-inline { margin-top: 18pt; }
    .gp-footer-inner { width: 100%; border-top: .9pt solid #78a6c8; padding-top: 5pt; font-size: 5.4pt; line-height: 1; position: relative; min-height: 12pt; }
    .gp-footer-left { position: absolute; left: 0; top: 5pt; width: 40%; text-align: left; }
    .gp-footer-center { position: absolute; left: 40%; top: 5pt; width: 20%; text-align: center; }
    .gp-footer-right { position: absolute; right: 0; top: 5pt; width: 40%; text-align: right; }
</style>
CSS;

        if (! $documentShell) {
            $packetCss = str_replace('@page { size: A4 portrait; margin: 72pt 34pt 38pt; }', '', $css);
            return $packetCss.$body;
        }

        return '<!doctype html><html><head><meta charset="utf-8">'.$css.'</head><body>'
            .'<div class="gp-page-header">'.$pageHeader.'</div>'
            .'<div class="gp-page-footer">'.$pageFooter.'</div>'
            .$body
            .'</body></html>';
    }
    private function laundryFormHtml(
        CustodyTransaction $custody,
        bool $documentShell = true,
        int $pageNumber = 1,
        int $pageCount = 1
    ): string {
        $custody->loadMissing([
            'request.borrower',
            'request.borrower.organizationalUnit',
            'request.currentVersion',
            'request.currentVersion.borrowerSignature.file',
            'request.currentVersion.approvalSteps.approver',
            'request.currentVersion.approvalSteps.signatureSnapshot.file',
            'lines.requestItem.inventoryItem',
        ]);

        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $templateConfig = $this->templateDefinitions->resolve('LAUNDRY_FORM');

        $formCode = e($templateConfig['form_code']);
        $documentTitle = e($templateConfig['title']);
        $requestingOfficeLabel = e($templateConfig['requesting_office_label']);
        $requestNoLabel = e($templateConfig['request_no_label']);
        $qtyLabel = e($templateConfig['qty_label']);
        $unitLabel = e($templateConfig['unit_label']);
        $descriptionLabel = e($templateConfig['description_label']);
        $dateRequestedLabel = e($templateConfig['date_requested_label']);
        $dateCompletedLabel = e($templateConfig['date_completed_label']);
        $requestedByLabel = e($templateConfig['requested_by_label']);
        $approvedByLabel = e($templateConfig['approved_by_label']);
        $issuedByLabel = e($templateConfig['issued_by_label']);
        $receivedByLabel = e($templateConfig['received_by_label']);
        $signatureLabel = e($templateConfig['signature_label']);
        $printedNameLabel = e($templateConfig['printed_name_label']);
        $designationLabel = e($templateConfig['designation_label']);
        $dateRowLabel = e($templateConfig['date_label']);
        $footerEffectivity = e($templateConfig['footer_effectivity']);
        $footerRevision = e($templateConfig['footer_revision']);

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        $logo = is_file($logoPath)
            ? '<img src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo">'
            : '<div class="lf-logo-fallback">CSPC</div>';

        $requestingOffice = e((string) (
            $version?->office_unit
            ?: $version?->represented_program_department
            ?: $borrower?->organizationalUnit?->unit_name
            ?: ''
        ));
        $requestNumber = e((string) $custody->request->request_no);
        $borrowerName = e((string) $borrower->full_name);

        $laundryRequestDateSource = $version?->signed_at ?: $version?->submitted_at ?: $version?->created_at;
        $laundryRequestDate = $laundryRequestDateSource
            ? e($laundryRequestDateSource->format('F j, Y'))
            : '';

        $laundryApproval = $this->approvalSignatory($version);
        $laundryApproverName = e((string) $laundryApproval['name']);
        $laundryApproverDesignation = e((string) ($laundryApproval['designation'] ?: 'SPMU Admin / Head'));
        $laundryApprovalDate = $laundryApproval['signed_at']
            ? e($laundryApproval['signed_at']->format('F j, Y'))
            : '';
        $laundryApproverSignature = $this->signatureImage($laundryApproval['snapshot'], 100, 20);
        $laundryBorrowerSignature = $this->signatureImage($version?->borrowerSignature, 100, 20);

        $laundryBorrowerDesignationValue = trim((string) ($borrower?->designation ?? ''));
        if ($laundryBorrowerDesignationValue === ''
            || strcasecmp($laundryBorrowerDesignationValue, AccessClassification::BorrowerOnly->label()) === 0
            || $laundryBorrowerDesignationValue === $borrower?->access_classification?->label()) {
            $laundryBorrowerDesignationValue = '';
        }
        $laundryBorrowerDesignation = e($laundryBorrowerDesignationValue);

        $laundryLines = $custody->lines->filter(
            fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
                && (float) $line->quantity_to_receive > 0
        );

        $itemRows = '';
        foreach ($laundryLines as $line) {
            $quantity = (float) $line->quantity_to_receive;
            $quantityText = floor($quantity) === $quantity
                ? (string) (int) $quantity
                : rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
            $unit = e((string) $line->requestItem?->unit_snapshot);
            $description = e((string) $line->requestItem?->description_snapshot);

            $itemRows .= '<tr class="lf-item-row">'
                .'<td class="lf-center">'.$quantityText.'</td>'
                .'<td class="lf-center">'.$unit.'</td>'
                .'<td>'.$description.'</td>'
                .'<td class="lf-center">'.$laundryRequestDate.'</td>'
                .'<td></td>'
                .'</tr>';
        }

        // Five visible item lines are enough for short requests. This avoids the
        // oversized writing box while preserving a modest handwritten area.
        $blankRows = max(0, 5 - $laundryLines->count());
        for ($i = 0; $i < $blankRows; $i++) {
            $itemRows .= '<tr class="lf-item-row lf-blank-row"><td></td><td></td><td></td><td></td><td></td></tr>';
        }
        if ($laundryLines->isNotEmpty()) {
            $itemRows .= '<tr class="lf-nothing-follows"><td colspan="5">'.self::NOTHING_FOLLOWS_MARKER.'</td></tr>';
        }

        $pageHeader = <<<HTML
<div class="lf-header-inner">
    <table class="lf-header-table">
        <tr>
            <td class="lf-logo">{$logo}</td>
            <td class="lf-school">
                <div>Republic of the Philippines</div>
                <strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>
                <div>Nabua, Camarines Sur</div>
            </td>
            <td class="lf-form-code">{$formCode}</td>
        </tr>
    </table>
    <div class="lf-blue-rule"></div>
    <div class="lf-title">{$documentTitle}</div>
</div>
HTML;

        $pageFooter = <<<HTML
<div class="lf-footer-inner">
    <div class="lf-footer-left">Effective Date&nbsp;&nbsp;<strong>{$footerEffectivity}</strong></div>
    <div class="lf-footer-center">{$footerRevision}</div>
    <div class="lf-footer-right">Page {$pageNumber} of {$pageCount}</div>
</div>
HTML;

        $inlineHeader = $documentShell ? '' : '<div class="lf-header-inline">'.$pageHeader.'</div>';
        $inlineFooter = $documentShell ? '' : '<div class="lf-footer-inline">'.$pageFooter.'</div>';

        $body = <<<HTML
<section class="laundry-form-clean">
    {$inlineHeader}

    <table class="lf-request-meta">
        <tr>
            <td class="lf-meta-label">{$requestingOfficeLabel}</td>
            <td class="lf-meta-value">{$requestingOffice}</td>
            <td class="lf-meta-gap"></td>
            <td class="lf-meta-label lf-request-no-label">{$requestNoLabel}</td>
            <td class="lf-meta-value lf-request-no-value">{$requestNumber}</td>
        </tr>
    </table>

    <table class="lf-items">
        <colgroup>
            <col class="lf-col-qty"><col class="lf-col-unit"><col class="lf-col-desc"><col class="lf-col-date"><col class="lf-col-date">
        </colgroup>
        <thead><tr>
            <th>{$qtyLabel}</th><th>{$unitLabel}</th><th>{$descriptionLabel}</th><th>{$dateRequestedLabel}</th><th>{$dateCompletedLabel}</th>
        </tr></thead>
        <tbody>{$itemRows}</tbody>
    </table>

    <table class="lf-signatures">
        <colgroup><col class="lf-sig-label"><col class="lf-sig-value"><col class="lf-sig-value"><col class="lf-sig-value"><col class="lf-sig-value"></colgroup>
        <thead><tr>
            <th></th><th>{$requestedByLabel}</th><th>{$approvedByLabel}</th><th>{$issuedByLabel}</th><th>{$receivedByLabel}</th>
        </tr></thead>
        <tbody>
            <tr class="lf-signature-row"><td>{$signatureLabel}</td><td>{$laundryBorrowerSignature}</td><td>{$laundryApproverSignature}</td><td></td><td></td></tr>
            <tr><td>{$printedNameLabel}</td><td>{$borrowerName}</td><td><strong>{$laundryApproverName}</strong></td><td></td><td></td></tr>
            <tr><td>{$designationLabel}</td><td>{$laundryBorrowerDesignation}</td><td>{$laundryApproverDesignation}</td><td></td><td></td></tr>
            <tr><td>{$dateRowLabel}</td><td>{$laundryRequestDate}</td><td>{$laundryApprovalDate}</td><td></td><td></td></tr>
        </tbody>
    </table>

    {$inlineFooter}
</section>
HTML;

        $css = <<<CSS
<style>
    @page { size: A4 portrait; margin: 72pt 34pt 38pt; }
    * { box-sizing: border-box; }
    body { margin: 0; color: #111; font-family: DejaVu Sans, Arial, sans-serif; }
    .laundry-form-clean { width: 100%; font-family: DejaVu Sans, Arial, sans-serif; font-size: 7.5pt; line-height: 1.16; color: #111; }

    .lf-page-header { position: fixed; top: -60pt; left: 0; right: 0; height: 56pt; }
    .lf-header-inline { margin-bottom: 9pt; }
    .lf-header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .lf-header-table td { border: 0; padding: 0; vertical-align: middle; }
    .lf-logo { width: 40pt; padding-right: 7pt !important; }
    .lf-logo img { width: 30pt; height: 30pt; object-fit: contain; display: block; }
    .lf-logo-fallback { font-weight: bold; font-size: 7pt; }
    .lf-school { font-size: 6.5pt; line-height: 1.15; }
    .lf-school strong { display: block; font-size: 8.2pt; line-height: 1.08; }
    .lf-form-code { width: 110pt; text-align: right; vertical-align: bottom !important; font-size: 6.4pt; font-weight: bold; }
    .lf-blue-rule { border-top: .9pt solid #78a6c8; margin: 6pt 0 5pt; }
    .lf-title { text-align: center; font-size: 9.2pt; font-weight: bold; letter-spacing: .08pt; }

    .lf-request-meta { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 1pt 0 9pt; font-size: 7.2pt; }
    .lf-request-meta td { border: 0; padding: 0 3pt 2pt 0; vertical-align: bottom; }
    .lf-meta-label { width: 15%; font-weight: bold; white-space: nowrap; }
    .lf-meta-value { width: 37%; border-bottom: .55pt solid #111 !important; text-align: center; overflow-wrap: anywhere; }
    .lf-meta-gap { width: 5%; }
    .lf-request-no-label { width: 15%; text-align: right; padding-right: 5pt !important; }
    .lf-request-no-value { width: 28%; }

    .lf-items { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 7.3pt; line-height: 1.16; }
    .lf-items th, .lf-items td { border: .55pt solid #555; padding: 3.2pt 4pt; vertical-align: middle; overflow-wrap: anywhere; }
    .lf-items th { height: 22pt; text-align: center; font-size: 7pt; }
    .lf-col-qty { width: 10%; } .lf-col-unit { width: 11%; } .lf-col-desc { width: 39%; } .lf-col-date { width: 20%; }
    .lf-item-row td { min-height: 21pt; }
    .lf-center { text-align: center; }
    .lf-blank-row td { height: 19pt; }
    .lf-nothing-follows td { height: 18pt; text-align: center; font-size: 6.8pt; font-weight: bold; letter-spacing: .35pt; background: #fff; }

    .lf-signatures { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 16pt; font-size: 6.6pt; line-height: 1.14; page-break-inside: avoid; }
    .lf-signatures th, .lf-signatures td { border: .55pt solid #555; padding: 2.2pt 3pt; text-align: center; vertical-align: middle; overflow-wrap: anywhere; }
    .lf-signatures th { height: 18pt; font-size: 6.7pt; }
    .lf-signatures td:first-child { text-align: left; font-weight: normal; }
    .lf-sig-label { width: 17%; }
    .lf-signatures .lf-sig-value { width: 20.75%; }
    .lf-signature-row td { height: 25pt; }
    .lf-signature-row img { max-height: 21pt; max-width: 92pt; margin: 0 auto; object-fit: contain; }

    .lf-page-footer { position: fixed; left: 0; right: 0; bottom: -24pt; height: 20pt; }
    .lf-footer-inline { margin-top: 18pt; }
    .lf-footer-inner { width: 100%; border-top: .9pt solid #78a6c8; padding-top: 5pt; font-size: 5.4pt; line-height: 1; position: relative; min-height: 12pt; }
    .lf-footer-left { position: absolute; left: 0; top: 5pt; width: 40%; text-align: left; }
    .lf-footer-center { position: absolute; left: 40%; top: 5pt; width: 20%; text-align: center; }
    .lf-footer-right { position: absolute; right: 0; top: 5pt; width: 40%; text-align: right; }
</style>
CSS;

        if (! $documentShell) {
            $packetCss = str_replace('@page { size: A4 portrait; margin: 72pt 34pt 38pt; }', '', $css);
            return $packetCss.$body;
        }

        return '<!doctype html><html><head><meta charset="utf-8">'.$css.'</head><body>'
            .'<div class="lf-page-header">'.$pageHeader.'</div>'
            .'<div class="lf-page-footer">'.$pageFooter.'</div>'
            .$body
            .'</body></html>';
    }

    private function officialPacketRequestHtml(CustodyTransaction $custody, int $pageNumber, int $pageCount): string
    {
        $request = $custody->request;
        $version = $request->currentVersion;
        $borrower = $request->borrower;

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        $logo = is_file($logoPath)
            ? '<img class="packet-logo" src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo">'
            : '<div class="seal">CSPC</div>';

        /*
         * Each cell renders the immutable snapshot captured for its own action
         * and role. No snapshot is reused across cells: the borrower's cell is
         * the request certification, and each SPMU cell is that approval step's
         * own signature. Cells with no snapshot keep the previous placeholder.
         */
        $borrowerSnapshot = $version?->borrowerSignature;

        $signatureCells = [[
            'label' => 'Accountable Borrower',
            'name' => $borrower->full_name,
            'role' => 'Borrower — Request Certification',
            'time' => $version?->submitted_at,
            'visual' => $this->signatureImage($borrowerSnapshot, 150, 42)
                ?: '<div class="signature-placeholder">See uploaded wet-signed request letter</div>',
        ]];

        foreach (
            ($version?->approvalSteps ?? collect())
                ->filter(fn ($step) => $step->stage_code->value === 'SPMU')
                ->sortBy('sequence_no')
            as $step
        ) {
            $signatureCells[] = [
                'label' => 'SPMU Verification and Approval',
                'name' => $step->approver?->full_name ?: 'Authorized SPMU reviewer',
                'role' => UserRole::Spmu->label(),
                'time' => $step->decided_at,
                'visual' => $this->signatureImage($step->signatureSnapshot, 150, 42)
                    ?: '<div class="signature-placeholder">System verification record</div>',
            ];
        }

        /*
         * The SPMU Action Officer's issuance signature only exists once the
         * property has been physically released.
         */
        if ($custody->released_at && $custody->releaseSignature) {
            $issuance = $this->issuanceSignatory($custody);

            $signatureCells[] = [
                'label' => 'Issued by (Physical Release)',
                'name' => $issuance['name'] ?: 'SPMU Action Officer',
                'role' => 'SPMU Action Officer — '.$issuance['designation'],
                'time' => $issuance['signed_at'],
                'visual' => $this->signatureImage($issuance['snapshot'], 150, 42)
                    ?: '<div class="signature-placeholder">Physical issuance record</div>',
            ];
        }

        $signatureRows = '';
        foreach (array_chunk($signatureCells, 2) as $row) {
            $signatureRows .= '<tr>';
            foreach ($row as $signature) {
                $signatureRows .= '<td>'
                    .'<div class="packet-signature-label">'.e($signature['label']).'</div>'
                    .'<div class="packet-signature-space">'.$signature['visual'].'</div>'
                    .'<div class="packet-signature-name">'.e(strtoupper((string) $signature['name'])).'</div>'
                    .'<div class="packet-signature-role">'.e((string) $signature['role']).'</div>'
                    .'<div class="packet-signature-date">'.e($this->formalDateTime($signature['time']) ?? 'Date unavailable').'</div>'
                .'</td>';
            }
            if (count($row) === 1) {
                $signatureRows .= '<td></td>';
            }
            $signatureRows .= '</tr>';
        }

        return '<section class="official packet-request">'
            .'<table class="packet-header" role="presentation">'
                .'<colgroup><col style="width:62px"><col></colgroup>'
                .'<tr>'
                    .'<td class="packet-header-logo-cell">'.$logo.'</td>'
                    .'<td class="packet-header-copy">'
                        .'<strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>'
                        .'<span>Supply and Property Management Unit</span>'
                    .'</td>'
                .'</tr>'
            .'</table>'

            .'<div class="packet-title-block">'
                .'<h1>BORROWING REQUEST LETTER</h1>'
                .'<div class="packet-meta">'
                    .'<span><b>Request No.</b> '.e((string) $request->request_no).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Status</b> Fully Approved</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Custody No.</b> '.e($custody->custody_no).'</span>'
                .'</div>'
            .'</div>'

            .'<div class="packet-section-title">Borrower / Event Information</div>'
            .'<table class="packet-info-grid">'
                .'<tr>'
                    .'<td><span class="field-label">Borrower</span><span class="field-value">'.e($borrower->full_name).'</span></td>'
                    .'<td><span class="field-label">Purpose / Event</span><span class="field-value">'.e((string) $version?->purpose_event).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Location</span><span class="field-value">'.e((string) $version?->location).'</span></td>'
                    .'<td><span class="field-label">Custody No.</span><span class="field-value">'.e($custody->custody_no).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Needed From</span><span class="field-value">'.e($this->formalDateTime($version?->needed_from) ?? 'Not recorded').'</span></td>'
                    .'<td><span class="field-label">Return Deadline</span><span class="field-value">'.e($this->formalDateTime($custody->due_at) ?? 'Not recorded').'</span></td>'
                .'</tr>'
            .'</table>'

            .'<p class="packet-note">This page forms part of the official custody packet. The approved request, borrower record, and SPMU verification history are preserved in SPMU-ACPMP and summarized below for custody reference.</p>'

            .'<div class="packet-section-title">Approval Record</div>'
            .'<table class="packet-signature-grid" role="presentation">'.$signatureRows.'</table>'

            .'<footer class="packet-footer"><span>Controlled document | SPMU-ACPMP | Official operational time: Asia/Manila</span><span>Page '.e((string) $pageNumber).' of '.e((string) $pageCount).'</span></footer>'
        .'</section>';
    }

    public function replaceConditionalForm(CustodyTransaction $custody, string $type): GeneratedDocument
    {
        $this->supersede($custody, $type, 'Replaced after a controlled custody or document update.');
        $document = $this->conditionalForm($custody->fresh(), $type);
        if ($type === 'GATE_PASS' && $custody->gatePass) {
            $custody->gatePass->update(['pass_document_id' => $document->id]);
        }
        $this->refreshPacketIfReady($custody->fresh());

        return $document;
    }

    public function refreshPacketIfReady(CustodyTransaction $custody): ?GeneratedDocument
    {
        $custody->loadMissing([
            'request.borrower', 'request.currentVersion.approvalSteps.approver',
            'request.currentVersion.borrowerSignature.file',
            'request.currentVersion.approvalSteps.signatureSnapshot.file',
            'lines.requestItem.inventoryItem', 'gatePass.preparedVerifier', 'gatePass.approver',
            'gatePass.preparedVerifierSignature.file', 'gatePass.approverSignature.file',
            'releaseSignature.file',
        ]);
        if (! $custody->acknowledged_at) {
            return null;
        }
        $hasGatePass = $custody->lines->contains(fn ($line) => $line->requestItem->use_location === 'OFF_CAMPUS');
        $hasLaundry = $custody->lines->contains(fn ($line) => (bool) $line->requestItem->inventoryItem?->laundry_required);

        $this->supersede($custody, 'OFFICIAL_FORM_PACKET', 'Replaced by the latest approved packet.');
        $totalPages = 2 + ($hasGatePass ? 1 : 0) + ($hasLaundry ? 1 : 0);
        $nextPageNumber = 1;
        $pages = [
            ['__html' => $this->officialPacketRequestHtml($custody, $nextPageNumber++, $totalPages)],
            ['__html' => $this->borrowerSlipHtml($custody, false, $nextPageNumber++, $totalPages)],
        ];
        if ($hasGatePass) {
            $pages[] = ['__html' => $this->gatePassHtml($custody, false, $nextPageNumber++, $totalPages)];
        }
        if ($hasLaundry) {
            $pages[] = ['__html' => $this->laundryFormHtml($custody, false, $nextPageNumber++, $totalPages)];
        }

        $htmlPages = [];
        foreach ($pages as $index => $page) {
            if (isset($page['__html'])) {
                $htmlPages[] = $page['__html'];

                continue;
            }

            $htmlPages[] = $this->officialHtml((string) ($page[3] ?? $page[1] ?? 'Official Form'), $page, false);
        }

        return $this->saveHtml('OFFICIAL_FORM_PACKET', '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.implode('<div class="page-break"></div>', $htmlPages).'</body></html>', $custody->request->currentVersion, $custody::class, $custody->id, 'FINAL', $custody->custody_no.'-OFFICIAL-PACKET.pdf');
    }

    public function billingStatement(BillingStatement $billing, ?SignatureSnapshot $authorizationSignature = null): GeneratedDocument
    {
        $billing->loadMissing([
            'borrower.organizationalUnit',
            'responsibleSpmuUser',
            'lines.incident.custody.request.currentVersion',
            'lines.penalty.incident.custody.request.currentVersion',
            'lines.penalty.custody.request.currentVersion',
        ]);

        if ($customTemplate = $this->activeUploadedTemplate('BILLING_STATEMENT')) {
            return $this->saveRenderedTemplate(
                $customTemplate,
                'BILLING_STATEMENT',
                $this->renderCustomTemplate($customTemplate, $this->billingStatementRenderData($billing, $authorizationSignature)),
                null,
                $billing::class,
                $billing->id,
                'FINAL',
                $billing->billing_no.'.pdf',
            );
        }

        return $this->saveHtml(
            'BILLING_STATEMENT',
            $this->billingStatementHtml($billing, $authorizationSignature),
            null,
            $billing::class,
            $billing->id,
            'FINAL',
            $billing->billing_no.'.pdf',
        );
    }

    public function lateReturnNotice(
        OverdueCase $case,
        User $spmuHead,
        string $disposition,
        string $decisionBasis,
        ?SignatureSnapshot $headSignature = null
    ): GeneratedDocument {
        $case->loadMissing([
            'borrower.organizationalUnit',
            'custody.request.currentVersion',
            'custody.lines.requestItem.inventoryItem.unit',
            'confirmedBy',
        ]);

        GeneratedDocument::query()
            ->where('subject_type', $case::class)
            ->where('subject_id', $case->id)
            ->where('document_type', 'LATE_RETURN_NOTICE')
            ->where('status', 'FINAL')
            ->update([
                'status' => 'SUPERSEDED',
                'invalidated_at' => now(),
                'invalidation_reason' => 'Replaced by the latest controlled Late Return Notice.',
            ]);

        $custody = $case->custody;
        $request = $custody?->request;
        $reference = 'LRN-'.str_pad((string) $case->id, 6, '0', STR_PAD_LEFT);
        $items = $this->lateReturnNoticeItems($custody);

        $html = view('documents.accountability.late-return-notice', [
            'case' => $case,
            'reference' => $reference,
            'logoDataUri' => $this->institutionalLogoDataUri(),
            'borrowerName' => (string) ($case->borrower?->full_name ?? ''),
            'officeUnit' => (string) ($case->borrower?->organizationalUnit?->unit_name
                ?? $case->borrower?->organizationalUnit?->name
                ?? ''),
            'requestNo' => (string) ($request?->request_no ?? ''),
            'custodyNo' => (string) ($custody?->custody_no ?? ''),
            'expectedReturnDate' => $case->grace_expires_at?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '—',
            'actualReturnDate' => $case->actual_return_date?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '—',
            'lateDays' => (int) ($case->late_days ?? 0),
            'rate' => $case->rate_snapshot !== null ? (float) $case->rate_snapshot : null,
            'amount' => (float) ($case->accrued_amount ?? 0),
            'disposition' => $disposition,
            'decisionBasis' => trim($decisionBasis),
            'aoConfirmedBy' => $case->ao_confirmed_at ? (string) ($case->confirmedBy?->full_name ?? 'SPMU Action Officer') : null,
            'aoConfirmedAt' => $case->ao_confirmed_at?->copy()->timezone('Asia/Manila')->format('d F Y, g:i A'),
            'headName' => (string) ($spmuHead->full_name ?: 'SPMU Head/Admin'),
            'headDesignation' => $this->templatePrintedDesignation($spmuHead),
            'headDate' => now()->timezone('Asia/Manila')->format('d F Y'),
            'headSignatureHtml' => $this->signatureImage($headSignature, 150, 42),
            'generatedAt' => now()->timezone('Asia/Manila')->format('d F Y, g:i A'),
            'items' => $items,
            'isPreReturn' => false,
        ])->render();

        return $this->saveHtml(
            'LATE_RETURN_NOTICE',
            $html,
            $request?->currentVersion,
            $case::class,
            $case->id,
            'FINAL',
            ($custody?->custody_no ?: $reference).'-LATE-RETURN-NOTICE.pdf',
        );
    }

    /**
     * Issued exactly once, automatically, the moment a custody first becomes
     * OVERDUE - before any physical return, AO confirmation, or Head decision
     * exists. Shows only the Expected Return Date and the official per-day
     * fee rate; it can never show final late days or a final total, because
     * neither is knowable yet. lateReturnNotice() (above) remains the only
     * method that renders those finalized figures, and only ever runs after
     * an actual physical return.
     */
    public function lateReturnNoticePreReturn(OverdueCase $case): GeneratedDocument
    {
        $case->loadMissing([
            'borrower.organizationalUnit',
            'custody.request.currentVersion',
            'custody.lines.requestItem.inventoryItem.unit',
        ]);

        $custody = $case->custody;
        $request = $custody?->request;
        $reference = 'LRN-'.str_pad((string) $case->id, 6, '0', STR_PAD_LEFT);

        $html = view('documents.accountability.late-return-notice', [
            'case' => $case,
            'reference' => $reference,
            'logoDataUri' => $this->institutionalLogoDataUri(),
            'borrowerName' => (string) ($case->borrower?->full_name ?? ''),
            'officeUnit' => (string) ($case->borrower?->organizationalUnit?->unit_name
                ?? $case->borrower?->organizationalUnit?->name
                ?? ''),
            'requestNo' => (string) ($request?->request_no ?? ''),
            'custodyNo' => (string) ($custody?->custody_no ?? ''),
            'expectedReturnDate' => $case->grace_expires_at?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '—',
            'actualReturnDate' => '—',
            'lateDays' => 0,
            'rate' => $case->rate_snapshot !== null ? (float) $case->rate_snapshot : null,
            'amount' => 0.0,
            'disposition' => 'Pending Physical Return',
            'decisionBasis' => 'This preliminary notice was issued automatically because the item was not returned by the Expected Return Date. The final number of late days and the applicable fee, if any, will be determined once the item is physically returned.',
            'aoConfirmedBy' => null,
            'aoConfirmedAt' => null,
            'headName' => '',
            'headDesignation' => '',
            'headDate' => '',
            'headSignatureHtml' => '',
            'generatedAt' => now()->timezone('Asia/Manila')->format('d F Y, g:i A'),
            'items' => $this->lateReturnNoticeItems($custody),
            'isPreReturn' => true,
        ])->render();

        return $this->saveHtml(
            'LATE_RETURN_NOTICE',
            $html,
            $request?->currentVersion,
            $case::class,
            $case->id,
            'FINAL',
            ($custody?->custody_no ?: $reference).'-LATE-RETURN-NOTICE.pdf',
        );
    }

    /** @return \Illuminate\Support\Collection<int, array{description: string, quantity: float, unit: string}> */
    private function lateReturnNoticeItems(?CustodyTransaction $custody): \Illuminate\Support\Collection
    {
        return ($custody?->lines ?? collect())
            ->map(function ($line): array {
                $requestItem = $line->requestItem;
                $inventoryItem = $requestItem?->inventoryItem;

                return [
                    'description' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Inventory item'),
                    'quantity' => (float) ($line->actual_released_quantity ?? 0),
                    'unit' => (string) ($requestItem?->unit_snapshot ?: $inventoryItem?->unit?->unit_name ?: ''),
                ];
            })
            ->values();
    }

    public function accountabilityComplianceNotice(
        Incident $incident,
        User $spmuHead,
        string $decisionRemarks,
        ?SignatureSnapshot $headSignature = null
    ): GeneratedDocument {
        $incident->loadMissing([
            'borrower.organizationalUnit',
            'custody.request.currentVersion',
            'lines.custodyLine.requestItem',
        ]);

        GeneratedDocument::query()
            ->where('subject_type', $incident::class)
            ->where('subject_id', $incident->id)
            ->where('document_type', 'ACCOUNTABILITY_COMPLIANCE_NOTICE')
            ->where('status', 'FINAL')
            ->update([
                'status' => 'SUPERSEDED',
                'invalidated_at' => now(),
                'invalidation_reason' => 'Replaced by the latest SPMU Head compliance decision notice.',
            ]);

        if ($customTemplate = $this->activeUploadedTemplate('ACCOUNTABILITY_COMPLIANCE_NOTICE')) {
            return $this->saveRenderedTemplate(
                $customTemplate,
                'ACCOUNTABILITY_COMPLIANCE_NOTICE',
                $this->renderCustomTemplate(
                    $customTemplate,
                    $this->accountabilityComplianceNoticeRenderData($incident, $spmuHead, $decisionRemarks, $headSignature)
                ),
                $incident->custody?->request?->currentVersion,
                $incident::class,
                $incident->id,
                'FINAL',
                $incident->incident_no.'-COMPLIANCE-NOTICE.pdf',
            );
        }

        return $this->saveHtml(
            'ACCOUNTABILITY_COMPLIANCE_NOTICE',
            $this->accountabilityComplianceNoticeHtml($incident, $spmuHead, $decisionRemarks, $headSignature),
            $incident->custody?->request?->currentVersion,
            $incident::class,
            $incident->id,
            'FINAL',
            $incident->incident_no.'-COMPLIANCE-NOTICE.pdf',
        );
    }

    public function administrativeSanctionNotice(Sanction $sanction, ?SignatureSnapshot $headSignature = null): GeneratedDocument
    {
        $sanction->loadMissing([
            'borrower.organizationalUnit',
            'academicPeriod',
            'confirmedBy',
            'violation.custody.request.currentVersion',
        ]);

        GeneratedDocument::query()
            ->where('subject_type', $sanction::class)
            ->where('subject_id', $sanction->id)
            ->where('document_type', 'ADMINISTRATIVE_SANCTION_NOTICE')
            ->where('status', 'FINAL')
            ->update([
                'status' => 'SUPERSEDED',
                'invalidated_at' => now(),
                'invalidation_reason' => 'Replaced by the latest controlled administrative sanction notice.',
            ]);

        $headSignature ??= $sanction->signatureSnapshot;

        if ($customTemplate = $this->activeUploadedTemplate('ADMINISTRATIVE_SANCTION_NOTICE')) {
            return $this->saveRenderedTemplate(
                $customTemplate,
                'ADMINISTRATIVE_SANCTION_NOTICE',
                $this->renderCustomTemplate(
                    $customTemplate,
                    $this->administrativeSanctionNoticeRenderData($sanction, $headSignature)
                ),
                $sanction->violation?->custody?->request?->currentVersion,
                $sanction::class,
                $sanction->id,
                'FINAL',
                'SANCTION-'.$sanction->id.'-'.$sanction->offense_no.'-OFFENSE.pdf',
            );
        }

        return $this->saveHtml(
            'ADMINISTRATIVE_SANCTION_NOTICE',
            $this->administrativeSanctionNoticeHtml($sanction, $headSignature),
            $sanction->violation?->custody?->request?->currentVersion,
            $sanction::class,
            $sanction->id,
            'FINAL',
            'SANCTION-'.$sanction->id.'-'.$sanction->offense_no.'-OFFENSE.pdf',
        );
    }

    /**
     * A plain factual record of an existing borrowing restriction. It reads
     * from the one authoritative BorrowerRestriction row and never computes
     * or duplicates restriction logic; it is not a decision document, so it
     * carries no signature block. Property-accountability-caused
     * restrictions only - late-return and suspension-caused restrictions
     * are represented by the Late Return Notice and Suspension Notice
     * respectively.
     */
    public function restrictionNotice(BorrowerRestriction $restriction): GeneratedDocument
    {
        $restriction->loadMissing([
            'borrower.organizationalUnit',
            'custody.request.currentVersion',
            'imposedBy',
        ]);

        GeneratedDocument::query()
            ->where('subject_type', $restriction::class)
            ->where('subject_id', $restriction->id)
            ->where('document_type', 'RESTRICTION_NOTICE')
            ->where('status', 'FINAL')
            ->update([
                'status' => 'SUPERSEDED',
                'invalidated_at' => now(),
                'invalidation_reason' => 'Replaced by the latest Restriction Notice for this restriction.',
            ]);

        return $this->saveHtml(
            'RESTRICTION_NOTICE',
            $this->restrictionNoticeHtml($restriction),
            $restriction->custody?->request?->currentVersion,
            $restriction::class,
            $restriction->id,
            'FINAL',
            'RESTRICTION-'.$restriction->id.'-NOTICE.pdf',
        );
    }

    private function billingStatementHtml(BillingStatement $billing, ?SignatureSnapshot $authorizationSignature = null): string
    {
        $sourceLine = $billing->lines->first();
        $incident = $sourceLine?->incident ?: $sourceLine?->penalty?->incident;
        $incident?->loadMissing(['headDecisionSignature.file', 'headDecidedBy']);
        $authorizationSignature ??= $incident?->headDecisionSignature;
        $custody = $incident?->custody ?: $sourceLine?->penalty?->custody;
        $request = $custody?->request;
        $incident?->loadMissing(['headDecisionSignature.file', 'headDecidedBy']);
        $logoDataUri = $this->institutionalLogoDataUri();

        return view('documents.accountability.billing-statement', [
            'billing' => $billing,
            'documentTitle' => $billing->isLateReturnBilling() ? 'Late Return Billing Statement' : 'Billing Statement / Assessment Notice',
            'logoDataUri' => $logoDataUri,
            'issuedDate' => $billing->issued_at?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '—',
            'dueDate' => $billing->due_at?->copy()->timezone('Asia/Manila')->format('d F Y'),
            'officeUnit' => (string) ($billing->borrower?->organizationalUnit?->unit_name
                ?? $billing->borrower?->organizationalUnit?->name
                ?? ''),
            'requestNo' => (string) ($request?->request_no ?? ''),
            'custodyNo' => (string) ($custody?->custody_no ?? ''),
            'incidentNo' => (string) ($incident?->incident_no ?? ''),
            'issuerName' => (string) ($billing->responsibleSpmuUser?->full_name ?: 'Authorized SPMU Signatory'),
            'issuerDesignation' => $this->templatePrintedDesignation($billing->responsibleSpmuUser),
            'headName' => (string) ($billing->responsibleSpmuUser?->full_name
                ?: $incident?->headDecidedBy?->full_name
                ?: 'SPMU Head'),
            'headDesignation' => $this->templatePrintedDesignation(
                $billing->responsibleSpmuUser ?: $incident?->headDecidedBy
            ),
            'headDecisionDate' => ($billing->issued_at ?: $incident?->head_decided_at)
                ?->copy()->timezone('Asia/Manila')->format('d F Y'),
            'headSignatureHtml' => $this->signatureImage($authorizationSignature, 150, 42),
        ])->render();
    }

    private function accountabilityComplianceNoticeHtml(
        Incident $incident,
        User $spmuHead,
        string $decisionRemarks,
        ?SignatureSnapshot $headSignature = null
    ): string {
        $request = $incident->custody?->request;

        return view('documents.accountability.compliance-notice', [
            'incident' => $incident,
            'logoDataUri' => $this->institutionalLogoDataUri(),
            'decisionDate' => now()->timezone('Asia/Manila')->format('d F Y'),
            'decisionRemarks' => trim($decisionRemarks),
            'officeUnit' => (string) ($incident->borrower?->organizationalUnit?->unit_name
                ?? $incident->borrower?->organizationalUnit?->name
                ?? ''),
            'requestNo' => (string) ($request?->request_no ?? ''),
            'custodyNo' => (string) ($incident->custody?->custody_no ?? ''),
            'headName' => (string) ($spmuHead->full_name ?: 'SPMU Head'),
            'headDesignation' => $this->templatePrintedDesignation($spmuHead),
            'headSignatureHtml' => $this->signatureImage($headSignature, 150, 42),
        ])->render();
    }

    private function administrativeSanctionNoticeHtml(Sanction $sanction, ?SignatureSnapshot $headSignature = null): string
    {
        $violation = $sanction->violation;
        $request = $violation?->custody?->request;
        $reasons = collect($violation?->details_json['reasons'] ?? [])
            ->map(fn ($reason) => str((string) $reason)->replace('_', ' ')->title()->toString())
            ->filter()
            ->values();

        $offenseLabel = match ((int) $sanction->offense_no) {
            1 => '1st Offense',
            2 => '2nd Offense',
            3 => '3rd Offense',
            default => $sanction->offense_no.'th Offense',
        };

        $hasBorrowingSuspension = strtoupper((string) $sanction->sanction_code) === 'BORROWING_SUSPENSION'
            || str_contains(strtolower((string) $sanction->sanction_label), 'suspension');

        return view('documents.accountability.sanction-notice', [
            'sanction' => $sanction,
            'logoDataUri' => $this->institutionalLogoDataUri(),
            'offenseLabel' => $offenseLabel,
            'confirmedDate' => $sanction->confirmed_at?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '—',
            'effectiveFrom' => $sanction->effective_from?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '—',
            'effectiveTo' => $sanction->effective_to?->copy()->timezone('Asia/Manila')->format('d F Y'),
            'academicPeriod' => trim((string) (($sanction->academicPeriod?->academic_year ?? '').' '.($sanction->academicPeriod?->term_name ?? ''))) ?: '—',
            'officeUnit' => (string) ($sanction->borrower?->organizationalUnit?->unit_name
                ?? $sanction->borrower?->organizationalUnit?->name
                ?? ''),
            'requestNo' => (string) ($request?->request_no ?? ''),
            'custodyNo' => (string) ($violation?->custody?->custody_no ?? ''),
            'reasonText' => $reasons->isNotEmpty() ? $reasons->implode(', ') : 'Confirmed borrowing accountability offense',
            'hasBorrowingSuspension' => $hasBorrowingSuspension,
            'headName' => (string) ($sanction->confirmedBy?->full_name ?: 'SPMU Head'),
            'headDesignation' => $this->templatePrintedDesignation($sanction->confirmedBy),
            'headSignatureHtml' => $this->signatureImage($headSignature, 150, 42),
        ])->render();
    }

    private function restrictionNoticeHtml(BorrowerRestriction $restriction): string
    {
        $caseReference = $restriction->custody?->custody_no ?: null;

        return view('documents.accountability.restriction-notice', [
            'restriction' => $restriction,
            'logoDataUri' => $this->institutionalLogoDataUri(),
            'issuedDate' => now()->timezone('Asia/Manila')->format('d F Y'),
            'officeUnit' => (string) ($restriction->borrower?->organizationalUnit?->unit_name
                ?? $restriction->borrower?->organizationalUnit?->name
                ?? ''),
            'custodyNo' => (string) ($restriction->custody?->custody_no ?? ''),
            'caseReference' => $caseReference,
            'restrictionTypeLabel' => str((string) $restriction->restriction_type)->replace('_', ' ')->title()->toString(),
            'effectiveFrom' => $restriction->effective_from?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '—',
            'effectiveTo' => $restriction->effective_to?->copy()->timezone('Asia/Manila')->format('d F Y'),
            'imposedByName' => (string) ($restriction->imposedBy?->full_name ?: 'SPMU'),
            'imposedByDesignation' => $this->templatePrintedDesignation($restriction->imposedBy),
        ])->render();
    }

    /** @return array<string,mixed> */
    private function accountabilityComplianceNoticeRenderData(
        Incident $incident,
        User $spmuHead,
        string $decisionRemarks,
        ?SignatureSnapshot $headSignature = null
    ): array {
        $request = $incident->custody?->request;

        return [
            'incident_no' => $incident->incident_no,
            'decision_date' => ($incident->head_decided_at ?: now())->copy()->timezone('Asia/Manila')->format('d F Y'),
            'borrower_name' => (string) ($incident->borrower?->full_name ?? ''),
            'office_unit' => (string) ($incident->borrower?->organizationalUnit?->unit_name
                ?? $incident->borrower?->organizationalUnit?->name ?? ''),
            'request_no' => (string) ($request?->request_no ?? ''),
            'custody_no' => (string) ($incident->custody?->custody_no ?? ''),
            'incident_type' => str((string) $incident->incident_type)->replace('_', ' ')->title()->toString(),
            'decision_remarks' => trim($decisionRemarks),
            'head_signature' => $this->templateSignatureAsset($headSignature),
            'head_printed_name' => (string) ($spmuHead->full_name ?? ''),
            'head_designation' => $this->templatePrintedDesignation($spmuHead),
            'head_date' => ($incident->head_decided_at ?: now())->copy()->timezone('Asia/Manila')->format('d F Y'),
            'items' => $incident->lines->map(fn ($line): array => [
                'description' => (string) ($line->custodyLine?->requestItem?->description_snapshot ?? 'Inventory item'),
                'qty' => (string) ($line->quantity + 0),
                'finding' => str((string) $line->observed_condition)->replace('_', ' ')->title()->toString(),
                'disposition' => str((string) $line->disposition_state)->replace('_', ' ')->title()->toString(),
            ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function administrativeSanctionNoticeRenderData(
        Sanction $sanction,
        ?SignatureSnapshot $headSignature = null
    ): array {
        $violation = $sanction->violation;
        $request = $violation?->custody?->request;
        $reasons = collect($violation?->details_json['reasons'] ?? [])
            ->map(fn ($reason) => str((string) $reason)->replace('_', ' ')->title()->toString())
            ->filter()->values();

        $offenseLabel = match ((int) $sanction->offense_no) {
            1 => '1st Offense',
            2 => '2nd Offense',
            3 => '3rd Offense',
            default => $sanction->offense_no.'th Offense',
        };

        $hasBorrowingSuspension = strtoupper((string) $sanction->sanction_code) === 'BORROWING_SUSPENSION'
            || str_contains(strtolower((string) $sanction->sanction_label), 'suspension');

        return [
            'document_title' => $hasBorrowingSuspension ? 'Suspension Notice' : 'Administrative Sanction Notice',
            'borrower_name' => (string) ($sanction->borrower?->full_name ?? ''),
            'office_unit' => (string) ($sanction->borrower?->organizationalUnit?->unit_name
                ?? $sanction->borrower?->organizationalUnit?->name ?? ''),
            'request_no' => (string) ($request?->request_no ?? ''),
            'custody_no' => (string) ($violation?->custody?->custody_no ?? ''),
            'academic_period' => trim((string) (($sanction->academicPeriod?->academic_year ?? '').' · '.($sanction->academicPeriod?->term_name ?? ''))),
            'offense_level' => $offenseLabel,
            'confirmed_date' => $sanction->confirmed_at?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '',
            'reason_text' => $reasons->isNotEmpty() ? $reasons->implode(', ') : 'Confirmed borrowing accountability offense',
            'sanction_label' => (string) $sanction->sanction_label,
            'effective_from' => $sanction->effective_from?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '',
            'effective_to' => $sanction->effective_to?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '',
            'head_remarks' => (string) ($sanction->remarks ?? ''),
            'head_signature' => $this->templateSignatureAsset($headSignature),
            'head_printed_name' => (string) ($sanction->confirmedBy?->full_name ?? ''),
            'head_designation' => $this->templatePrintedDesignation($sanction->confirmedBy),
            'head_date' => $sanction->confirmed_at?->copy()->timezone('Asia/Manila')->format('d F Y') ?: '',
        ];
    }

    private function institutionalLogoDataUri(): string
    {
        $logoPath = resource_path('images/cspc-logo-print.jpg');

        if (! is_file($logoPath)) {
            throw ValidationException::withMessages([
                'document' => 'The institutional logo asset is unavailable.',
            ]);
        }

        return 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath));
    }

    /**
     * The RSLDDP's official content, appraisal fields, signatories, and
     * layout remain client-confirmation-required
     * (docs/CONFIGURATION-REGISTER.md). This renders the two relationships
     * the schema can actually prove - Incident::reportedBy (the SPMU Action
     * Officer who inspected) and Incident::headDecidedBy (the SPMU
     * Head/Admin who confirmed) - under literal labels, never a claimed
     * "custodian" role the data model does not have. The generated document
     * carries a visible provisional marker (RsldppLayoutVersion) until both
     * the layout is code-approved and rslddp_template_status is APPROVED.
     */
    public function rslddp(Incident $incident): GeneratedDocument
    {
        $incident->loadMissing([
            'borrower.organizationalUnit',
            'custody.request.currentVersion',
            'lines.custodyLine.requestItem.inventoryItem',
            'reportedBy',
            'headDecidedBy',
            'headDecisionSignature.file',
        ]);

        $layoutVersion = app(\App\Support\RsldppLayoutVersion::class);
        $isProvisional = SystemSetting::value('rslddp_template_status') !== 'APPROVED'
            || ! $layoutVersion->isApprovedLayout();

        $items = $incident->lines->map(function (IncidentLine $line): array {
            $requestItem = $line->custodyLine?->requestItem;
            $inventoryItem = $requestItem?->inventoryItem;

            return [
                'description' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Custody line '.$line->custody_line_id),
                'quantity' => (float) $line->quantity,
                'condition' => (string) (str($line->observed_condition ?: '')->replace('_', ' ')->title() ?: '—'),
                'assessed_value' => $line->assessed_value !== null ? (float) $line->assessed_value : null,
            ];
        })->values()->all();

        $html = view('documents.accountability.rslddp', [
            'incident' => $incident,
            'logoDataUri' => $this->institutionalLogoDataUri(),
            'isProvisional' => $isProvisional,
            'officeUnit' => (string) ($incident->borrower?->organizationalUnit?->unit_name
                ?? $incident->borrower?->organizationalUnit?->name
                ?? ''),
            'requestNo' => (string) ($incident->custody?->request?->request_no ?? ''),
            'items' => $items,
            'reportedByName' => (string) ($incident->reportedBy?->full_name ?: 'SPMU Action Officer'),
            'reportedByDesignation' => $this->templatePrintedDesignation($incident->reportedBy),
            'reportedByDate' => $incident->reported_at?->timezone('Asia/Manila')->format('d F Y'),
            'headName' => (string) ($incident->headDecidedBy?->full_name ?: 'SPMU Head/Admin'),
            'headDesignation' => $this->templatePrintedDesignation($incident->headDecidedBy),
            'headSignatureHtml' => $this->signatureImage($incident->headDecisionSignature, 150, 42),
            'headDate' => $incident->head_decided_at?->timezone('Asia/Manila')->format('d F Y'),
            'generatedAt' => now()->timezone('Asia/Manila')->format('d F Y, g:i A'),
        ])->render();

        $document = $this->saveHtml(
            'RSLDDP',
            $html,
            $incident->custody?->request?->currentVersion,
            $incident::class,
            $incident->id,
            'FINAL',
            $incident->incident_no.'-RSLDDP.pdf',
        );
        $incident->update(['rslddp_reference' => $document->document_no]);

        return $document;
    }

    /** @param list<string> $lines */
    private function save(string $type, array $lines, ?RequestVersion $version, string $subjectType, int $subjectId, string $status, string $filename): GeneratedDocument
    {
        $template = $this->activeTemplate($type);
        $bytes = $this->pdf->make($lines);
        $file = $this->files->storeBytes($bytes, 'generated-documents', $filename, 'application/pdf', 'pdf', 'CONTROLLED_DOCUMENT');

        return GeneratedDocument::query()->create([
            'template_id' => $template?->id,
            'stored_file_id' => $file->id,
            'request_version_id' => $version?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'document_no' => strtoupper($type).'-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_type' => $type,
            'version_no' => $version?->version_no ?? 1,
            'sha256' => $file->sha256,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }

    /** @param list<list<string>> $pages */
    private function savePages(string $type, array $pages, ?RequestVersion $version, string $subjectType, int $subjectId, string $status, string $filename): GeneratedDocument
    {
        $template = $this->activeTemplate($type);
        $bytes = $this->pdf->makePages($pages);
        $file = $this->files->storeBytes($bytes, 'generated-documents', $filename, 'application/pdf', 'pdf', 'CONTROLLED_DOCUMENT');

        return GeneratedDocument::query()->create([
            'template_id' => $template?->id,
            'stored_file_id' => $file->id,
            'request_version_id' => $version?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'document_no' => strtoupper($type).'-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_type' => $type,
            'version_no' => $version?->version_no ?? 1,
            'sha256' => $file->sha256,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }

    private function saveHtml(string $type, string $html, ?RequestVersion $version, string $subjectType, int $subjectId, string $status, string $filename, bool $pageNumbers = false): GeneratedDocument
    {
        $template = $this->activeTemplate($type);
        $bytes = $this->pdf->html($html, $pageNumbers);
        $file = $this->files->storeBytes($bytes, 'generated-documents', $filename, 'application/pdf', 'pdf', 'CONTROLLED_DOCUMENT');

        return GeneratedDocument::query()->create([
            'template_id' => $template?->id,
            'stored_file_id' => $file->id,
            'request_version_id' => $version?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'document_no' => strtoupper($type).'-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_type' => $type,
            'version_no' => $version?->version_no ?? 1,
            'sha256' => $file->sha256,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function saveRenderedTemplate(DocumentTemplate $template, string $type, string $bytes, ?RequestVersion $version, string $subjectType, int $subjectId, string $status, string $filename): GeneratedDocument
    {
        $file = $this->files->storeBytes($bytes, 'generated-documents', $filename, 'application/pdf', 'pdf', 'CONTROLLED_DOCUMENT');

        return GeneratedDocument::query()->create([
            'template_id' => $template->id,
            'stored_file_id' => $file->id,
            'request_version_id' => $version?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'document_no' => strtoupper($type).'-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_type' => $type,
            'version_no' => $version?->version_no ?? 1,
            'sha256' => $file->sha256,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }

    private function activeUploadedTemplate(string $type): ?DocumentTemplate
    {
        /*
         * Editable/uploaded Office templates are intentionally outside the
         * final SPMU-ACPMP scope. Historical DocumentTemplate rows remain for
         * audit/backward compatibility, but production generation always uses
         * the built-in controlled layouts.
         */
        return null;
    }

    /**
     * Read one existing, form-eligible workflow record into the same explicit
     * semantic payload used by the current uploaded-template renderer.
     *
     * This exists solely for an unactivated Office Draft preview. It never
     * creates a document, updates a model, selects an active template, or
     * returns an Eloquent model. Signature image data is intentionally omitted
     * because Office Draft previews retain the renderer's existing synthetic
     * signature treatment until a later, separately approved phase.
     *
     * @return array<string,mixed>|null
     */
    public function runtimePayloadForDraftPreview(string $type): ?array
    {
        $type = strtoupper(trim($type));

        return match ($type) {
            'BORROWER_SLIP' => ($custody = $this->latestRuntimePreviewCustody())
                ? $this->withSafeCustodyRuntimeDetails($this->borrowerSlipRenderData($custody, false), $custody)
                : null,
            'LAUNDRY_FORM' => ($custody = $this->latestRuntimePreviewCustody('laundryJob'))
                ? $this->withSafeCustodyRuntimeDetails($this->laundryFormRenderData($custody, false), $custody)
                : null,
            'GATE_PASS' => ($custody = $this->latestRuntimePreviewCustody('gatePass'))
                ? $this->withSafeCustodyRuntimeDetails($this->gatePassRenderData($custody, false), $custody)
                : null,
            'BILLING_STATEMENT' => ($billing = BillingStatement::query()
                ->with([
                    'borrower.organizationalUnit',
                    'responsibleSpmuUser',
                    'lines.incident.custody.request.currentVersion',
                    'lines.penalty.incident.custody.request.currentVersion',
                    'lines.penalty.custody.request.currentVersion',
                ])
                ->latest('id')
                ->first())
                    ? $this->billingStatementRenderData($billing, null, false)
                    : null,
            'RSLDDP' => ($incident = Incident::query()
                ->with([
                    'borrower',
                    'custody.request.currentVersion',
                    'lines.custodyLine.requestItem',
                    'reportedBy',
                ])
                ->latest('id')
                ->first())
                    ? $this->rslddpRenderData($incident)
                    : null,
            default => null,
        };
    }

    private function latestRuntimePreviewCustody(?string $requiredRelation = null): ?CustodyTransaction
    {
        $query = CustodyTransaction::query()
            ->with([
                'request.borrower.organizationalUnit',
                'request.currentVersion.approvalSteps.approver',
                'lines.requestItem.inventoryItem',
                'lines.laundryJobLine',
                'returns.receivedBy',
                'returns.lines.custodyLine.requestItem',
                'releasedBy',
                'gatePass.preparedVerifier',
                'gatePass.approver',
                'laundryJob.formVerifier',
            ])
            ->whereHas('request.currentVersion');

        if ($requiredRelation !== null) {
            $query->whereHas($requiredRelation);
        }

        return $query->latest('id')->first();
    }

    /**
     * This is a deliberately small, read-only extension of the existing
     * renderer payload for Office Draft discovery. These are request/custody
     * facts, not a catalog tailored to a particular uploaded layout, so a
     * future official revision can use an already-recorded venue, schedule,
     * office, or classification without changing a template implementation.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withSafeCustodyRuntimeDetails(array $payload, CustodyTransaction $custody): array
    {
        $request = $custody->request;
        $version = $request?->currentVersion;
        $borrower = $request?->borrower;

        return [
            ...$payload,
            'request_location' => (string) ($version?->location ?? ''),
            'request_event_details' => (string) ($version?->event_details ?? ''),
            'request_division_code' => (string) ($version?->division_code ?? ''),
            'request_represented_program_department' => (string) ($version?->represented_program_department ?? ''),
            'request_represented_year_level' => (string) ($version?->represented_year_level ?? ''),
            'request_schedule_date' => $version?->schedule_date?->format('F j, Y') ?: '',
            'request_return_date' => $version?->return_date?->format('F j, Y') ?: '',
            'request_is_off_campus' => (bool) ($version?->off_campus ?? false),
            'borrower_office_unit' => (string) ($borrower?->organizationalUnit?->unit_name ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function borrowerSlipRenderData(CustodyTransaction $custody, bool $includeSignatures = true): array
    {
        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $return = $this->returnInspectionData($custody, $includeSignatures);
        $approval = $this->approvalSignatory($version, $includeSignatures);
        $issuance = $this->issuanceSignatory($custody, $includeSignatures);
        $employmentType = strtoupper((string) ($borrower?->employment_type?->value ?? ''));
        $borrowerDesignation = trim((string) ($borrower?->designation ?? ''));
        if ($borrowerDesignation === ''
            || strcasecmp($borrowerDesignation, AccessClassification::BorrowerOnly->label()) === 0
            || $borrowerDesignation === $borrower?->access_classification?->label()) {
            $borrowerDesignation = '';
        }
        $otherClassification = $employmentType !== '' && $employmentType !== 'EMPLOYEE'
            ? str($employmentType)->replace('_', ' ')->title()->toString()
            : '';

        return [
            'document_date' => $approval['signed_at']?->format('F j, Y') ?: now()->format('F j, Y'),
            'employee_checkbox' => $employmentType === 'EMPLOYEE' ? 'X' : '',
            'others_checkbox' => $employmentType !== '' && $employmentType !== 'EMPLOYEE' ? 'X' : '',
            'other_classification' => $otherClassification,
            'purpose' => (string) ($version?->purpose_event ?? ''),
            'expected_return_date' => $custody->due_at?->format('F j, Y') ?: '',
            'date_released' => $custody->released_at?->copy()->timezone('Asia/Manila')->format('F j, Y') ?: '',
            'release_time' => $custody->released_at?->copy()->timezone('Asia/Manila')->format('g:i A') ?: '',
            'date_returned' => $return['signed_at']?->format('F j, Y') ?: '',
            'remarks' => implode('; ', $return['findings'] ?? []),
            'borrowed_by_signature' => $includeSignatures ? $this->templateSignatureAsset($version?->borrowerSignature) : null,
            'borrowed_by_printed_name' => (string) ($borrower?->full_name ?? ''),
            'borrowed_by_designation' => $borrowerDesignation,
            'borrowed_by_date' => $version?->signed_at?->format('F j, Y') ?: '',
            'approved_by_signature' => $includeSignatures ? $this->templateSignatureAsset($approval['snapshot'] ?? null) : null,
            'approved_by_printed_name' => (string) ($approval['name'] ?? ''),
            'approved_by_designation' => (string) ($approval['designation'] ?? ''),
            'approved_by_date' => $approval['signed_at']?->format('F j, Y') ?: '',
            'issued_by_signature' => $includeSignatures ? $this->templateSignatureAsset($issuance['snapshot'] ?? null) : null,
            'issued_by_printed_name' => (string) ($issuance['name'] ?? ''),
            'issued_by_designation' => (string) ($issuance['designation'] ?? ''),
            'issued_by_date' => $issuance['signed_at']?->format('F j, Y') ?: '',
            'return_received_by_signature' => $includeSignatures ? $this->templateSignatureAsset($return['signature'] ?? null) : null,
            'return_received_by_printed_name' => (string) ($return['received_by_name'] ?? ''),
            'return_received_by_designation' => (string) ($return['received_by_designation'] ?? ''),
            'return_received_by_date' => $return['signed_at']?->format('F j, Y') ?: '',
            'approved_by' => (string) ($approval['name'] ?? ''),
            'items' => $this->appendNothingFollowsItem($custody->lines
                ->filter(fn ($line) => (float) $line->quantity_to_receive > 0)
                ->map(fn ($line): array => [
                    'qty' => (string) (int) round((float) $line->quantity_to_receive),
                    'unit' => (string) ($line->requestItem?->unit_snapshot ?? ''),
                    'description' => (string) ($line->requestItem?->description_snapshot ?? ''),
                ])->values()->all()),
        ];
    }

    /** @return array<string,mixed> */
    private function laundryFormRenderData(CustodyTransaction $custody, bool $includeSignatures = true): array
    {
        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $job = $custody->laundryJob;
        $approval = $this->approvalSignatory($version, $includeSignatures);
        $requestDateSource = $version?->signed_at ?: $version?->submitted_at ?: $version?->created_at;
        $dateRequested = $requestDateSource?->format('F j, Y') ?: '';
        $requestingOffice = (string) (
            $version?->office_unit
            ?: $version?->represented_program_department
            ?: $borrower?->organizationalUnit?->unit_name
            ?: ''
        );
        $physicalReceivedDate = $job?->worker_received_at?->format('F j, Y') ?: '';
        $physicalCompletedDate = $job?->worker_completed_at?->format('F j, Y') ?: '';

        return [
            'request_no' => (string) ($custody->request->request_no ?? ''),
            'custody_no' => (string) ($custody->custody_no ?? ''),
            'borrower_name' => (string) ($borrower?->full_name ?? ''),
            'requesting_office' => $requestingOffice,
            'date_requested' => $dateRequested,
            'date_released' => $custody->released_at?->format('F j, Y') ?: '',
            'requested_by_signature' => $includeSignatures ? $this->templateSignatureAsset($version?->borrowerSignature) : null,
            'requested_by_printed_name' => (string) ($borrower?->full_name ?? ''),
            'requested_by_designation' => $this->templatePrintedDesignation($borrower),
            'requested_by_date' => $dateRequested,
            'approved_by_signature' => $includeSignatures ? $this->templateSignatureAsset($approval['snapshot'] ?? null) : null,
            'approved_by_printed_name' => (string) ($approval['name'] ?? ''),
            'approved_by_designation' => (string) ($approval['designation'] ?? ''),
            'approved_by_date' => $approval['signed_at']?->format('F j, Y') ?: '',
            // Laundry workers are offline physical actors. Name and their
            // actual receipt date are saved on LaundryJob; a signature image
            // and designation are intentionally not manufactured.
            'received_by_signature' => null,
            'received_by_printed_name' => (string) ($job?->worker_name ?? ''),
            'received_by_designation' => '',
            'received_by_date' => $physicalReceivedDate,
            'verified_by_signature' => null,
            'verified_by_printed_name' => (string) ($job?->formVerifier?->full_name ?? ''),
            'verified_by_designation' => $this->templatePrintedDesignation($job?->formVerifier),
            'verified_by_date' => $job?->form_verified_at?->format('F j, Y') ?: '',
            'items' => $this->appendNothingFollowsItem($custody->lines
                ->filter(fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required && (float) $line->quantity_to_receive > 0)
                ->map(function ($line) use ($job, $dateRequested, $physicalReceivedDate, $physicalCompletedDate): array {
                    $laundryLine = $line->laundryJobLine;

                    return [
                        'qty' => (string) (int) round((float) $line->quantity_to_receive),
                        'unit' => (string) ($line->requestItem?->unit_snapshot ?? ''),
                        'description' => (string) ($line->requestItem?->description_snapshot ?? ''),
                        // These are deliberately attached to every item row:
                        // table redraw removes the original row artwork, and
                        // the official form may put physical dates in columns.
                        'date_requested' => $dateRequested,
                        'date_received' => $physicalReceivedDate,
                        'date_completed' => $physicalCompletedDate,
                        'received_quantity' => $laundryLine?->received_quantity === null ? '' : (string) $laundryLine->received_quantity,
                        'completed_quantity' => $laundryLine?->completed_quantity === null ? '' : (string) $laundryLine->completed_quantity,
                        'affected_quantity' => $laundryLine?->affected_quantity === null ? '' : (string) $laundryLine->affected_quantity,
                        'issue_type' => (string) ($laundryLine?->issue_type ?? ''),
                        'remarks' => (string) ($laundryLine?->remarks ?: $job?->worker_remarks ?: ''),
                    ];
                })->values()->all()),
        ];
    }

    /** @return array<string,mixed> */
    private function gatePassRenderData(CustodyTransaction $custody, bool $includeSignatures = true): array
    {
        $version = $custody->request->currentVersion;
        $gatePass = $custody->gatePass;
        $borrower = $custody->request->borrower;
        $movementScope = $custody->lines
            ->first(fn ($line) => $line->requestItem?->use_location !== null)?->requestItem?->use_location;

        return [
            'gate_pass_no' => (string) $custody->custody_no,
            'request_no' => (string) ($custody->request->request_no ?? ''),
            'custody_no' => (string) ($custody->custody_no ?? ''),
            'document_date' => $custody->scheduled_release_at?->format('F j, Y')
                ?: $gatePass?->approved_at?->format('F j, Y')
                ?: now()->format('F j, Y'),
            'borrower_name' => (string) ($gatePass?->bearer_name ?: ($borrower?->full_name ?? '')),
            'requesting_office' => (string) ($version?->office_unit ?: $version?->represented_program_department ?: $borrower?->organizationalUnit?->unit_name ?: ''),
            'purpose' => (string) ($gatePass?->purpose ?: $version?->purpose_event ?: ''),
            'destination' => (string) ($gatePass?->destination ?? ''),
            'movement_scope' => $movementScope ? str((string) $movementScope)->replace('_', ' ')->title()->toString() : '',
            'exit_date' => $custody->released_at?->format('F j, Y') ?: '',
            'verification_remarks' => (string) ($gatePass?->verification_remarks ?? ''),
            'requested_by_signature' => $includeSignatures ? $this->templateSignatureAsset($version?->borrowerSignature) : null,
            'requested_by_printed_name' => (string) ($borrower?->full_name ?? ''),
            'requested_by_designation' => $this->templatePrintedDesignation($borrower),
            'requested_by_date' => $version?->signed_at?->format('F j, Y') ?: '',
            'verified_by_signature' => $includeSignatures ? $this->templateSignatureAsset($gatePass?->preparedVerifierSignature) : null,
            'verified_by_printed_name' => (string) ($gatePass?->preparedVerifier?->full_name ?? ''),
            'verified_by_designation' => $this->templatePrintedDesignation($gatePass?->preparedVerifier),
            'verified_by_date' => $gatePass?->prepared_verified_at?->format('F j, Y') ?: '',
            'approved_by_signature' => $includeSignatures ? $this->templateSignatureAsset($gatePass?->approverSignature) : null,
            'approved_by_printed_name' => (string) ($gatePass?->approver?->full_name ?? ''),
            'approved_by_designation' => $this->templatePrintedDesignation($gatePass?->approver),
            'approved_by_date' => $gatePass?->approved_at?->format('F j, Y') ?: '',
            // A guard's handwritten approval is recorded as a name/date,
            // not as an application signature snapshot.
            'guard_signature' => null,
            'guard_printed_name' => (string) ($gatePass?->guard_name ?? ''),
            'guard_designation' => '',
            'guard_date' => $gatePass?->guard_signed_at?->format('F j, Y') ?: '',
            'items' => $this->appendNothingFollowsItem($custody->lines
                ->filter(fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS' && (float) $line->quantity_to_receive > 0)
                ->map(fn ($line): array => [
                    'qty' => (string) (int) round((float) $line->quantity_to_receive),
                    'unit' => (string) ($line->requestItem?->unit_snapshot ?? ''),
                    'description' => (string) ($line->requestItem?->description_snapshot ?? ''),
                    'movement_scope' => str((string) ($line->requestItem?->use_location ?? ''))->replace('_', ' ')->title()->toString(),
                ])->values()->all()),
        ];
    }

    /**
     * Append the formal terminal row used by controlled, approved operational
     * forms. Blank non-description cells make the marker read as a closure of
     * the item list rather than another property line.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function appendNothingFollowsItem(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        $items[] = [
            'qty' => '',
            'unit' => '',
            'description' => self::NOTHING_FOLLOWS_MARKER,
        ];

        return $items;
    }

    /** @return array<string,mixed> */
    private function billingStatementRenderData(BillingStatement $billing, ?SignatureSnapshot $authorizationSignature = null, bool $includeSignatures = true): array
    {
        $incidents = $billing->lines
            ->map(fn ($line) => $line->incident ?: $line->penalty?->incident)
            ->filter();

        $incident = $incidents->first();
        if ($includeSignatures) {
            $incident?->loadMissing(['headDecisionSignature.file', 'headDecidedBy']);
            $authorizationSignature ??= $incident?->headDecisionSignature;
        }
        $custodies = $billing->lines
            ->map(fn ($line) => $line->incident?->custody ?: $line->penalty?->custody ?: $line->penalty?->incident?->custody)
            ->filter()
            ->unique('id')
            ->values();

        return [
            'billing_no' => (string) $billing->billing_no,
            'borrower_name' => (string) ($billing->borrower?->full_name ?? ''),
            'request_no' => $custodies->pluck('request.request_no')->filter()->unique()->implode(', '),
            'custody_no' => $custodies->pluck('custody_no')->filter()->unique()->implode(', '),
            'incident_no' => $incidents->pluck('incident_no')->filter()->unique()->implode(', '),
            'issued_date' => $billing->issued_at?->format('F j, Y') ?: '',
            'due_date' => $billing->due_at?->format('F j, Y') ?: '',
            'statement_remarks' => (string) ($billing->remarks ?? ''),
            'total_amount' => 'PHP '.number_format((float) $billing->total_amount, 2),
            'issuer_signature' => $includeSignatures ? $this->templateSignatureAsset($authorizationSignature) : null,
            'issuer_printed_name' => (string) ($billing->responsibleSpmuUser?->full_name
                ?: $incident?->headDecidedBy?->full_name
                ?: ''),
            'issuer_designation' => $this->templatePrintedDesignation($billing->responsibleSpmuUser),
            'issuer_date' => $billing->issued_at?->format('F j, Y') ?: '',
            'items' => $billing->lines->map(fn ($line): array => [
                'description' => (string) $line->description,
                'line_type' => (string) ($line->line_type ?? ''),
                'basis' => (string) ($line->basis ?? ''),
                'penalty_type' => (string) ($line->penalty?->penalty_type ?? ''),
                'incident_no' => (string) ($line->incident?->incident_no ?: $line->penalty?->incident?->incident_no ?: ''),
                'amount' => 'PHP '.number_format((float) $line->amount, 2),
            ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function rslddpRenderData(Incident $incident): array
    {
        return [
            'rslddp_reference' => (string) ($incident->rslddp_reference ?? ''),
            'incident_no' => (string) $incident->incident_no,
            'custody_no' => (string) ($incident->custody?->custody_no ?? ''),
            'request_no' => (string) ($incident->custody?->request?->request_no ?? ''),
            'police_blotter_reference' => (string) ($incident->police_blotter_reference ?? ''),
            'borrower_name' => (string) ($incident->borrower?->full_name ?? ''),
            'incident_type' => (string) $incident->incident_type,
            'reported_date' => $incident->reported_at?->format('F j, Y g:i A') ?: '',
            'incident_remarks' => (string) ($incident->remarks ?? ''),
            'appraisal_amount' => $incident->appraisal_amount === null ? '' : 'PHP '.number_format((float) $incident->appraisal_amount, 2),
            'reported_by_signature' => null,
            'reported_by_printed_name' => (string) ($incident->reportedBy?->full_name ?? ''),
            'reported_by_designation' => $this->templatePrintedDesignation($incident->reportedBy),
            'reported_by_date' => $incident->reported_at?->format('F j, Y') ?: '',
            'items' => $incident->lines->map(fn ($line): array => [
                'qty' => (string) ($line->quantity + 0),
                'description' => (string) ($line->custodyLine?->requestItem?->description_snapshot ?? 'Custody line '.$line->custody_line_id),
                'condition' => (string) $line->observed_condition,
                'assessed_value' => $line->assessed_value === null ? '' : 'PHP '.number_format((float) $line->assessed_value, 2),
                'disposition' => (string) ($line->disposition_state ?? ''),
            ])->values()->all(),
        ];
    }


    /** @return array<string,mixed> */
    private function borrowerSlipExcelData(CustodyTransaction $custody, int $pageNumber, int $pageCount): array
    {
        $custody->loadMissing([
            'request.borrower',
            'request.currentVersion.borrowerSignature.file',
            'request.currentVersion.approvalSteps.approver',
            'request.currentVersion.approvalSteps.signatureSnapshot.file',
            'releasedBy',
            'lines.requestItem.inventoryItem',
            'returns.lines.custodyLine.requestItem',
        ]);

        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $approval = $this->approvalSignatory($version);
        $returnInspection = $this->returnInspectionData($custody);
        $defaults = $this->templateDefinitions->defaults('BORROWER_SLIP');
        $employmentType = strtoupper((string) ($borrower?->employment_type?->value ?? ''));
        $otherType = $employmentType !== '' && $employmentType !== 'EMPLOYEE'
            ? str($employmentType)->replace('_', ' ')->title()->toString()
            : '';

        $borrowerDesignation = trim((string) ($borrower?->designation ?? ''));
        if ($borrowerDesignation === ''
            || strcasecmp($borrowerDesignation, AccessClassification::BorrowerOnly->label()) === 0
            || $borrowerDesignation === $borrower?->access_classification?->label()) {
            $borrowerDesignation = '';
        }

        $returnDate = $returnInspection['signed_at']?->format('F j, Y') ?: '';
        $returnRemarks = implode('; ', $returnInspection['findings'] ?? []);

        return [
            'document_date' => $approval['signed_at']?->format('F j, Y') ?: now()->format('F j, Y'),
            'employee_checkbox' => $employmentType === 'EMPLOYEE' ? '☒' : '☐',
            'others_checkbox' => $employmentType === 'EMPLOYEE' ? '☐' : '☒',
            'other_type' => $otherType,
            'reference_no' => (string) $custody->custody_no,
            'request_no' => (string) ($custody->request->request_no ?? ''),
            'purpose' => (string) ($version?->purpose_event ?? ''),
            'expected_return_date' => $custody->due_at?->format('F j, Y') ?: '',
            'borrower_signature' => ['html' => $this->signatureImage($version?->borrowerSignature, 120, 24)],
            'borrower_name' => (string) ($borrower?->full_name ?? ''),
            'borrower_position' => $borrowerDesignation,
            'borrower_sign_date' => $version?->signed_at?->format('F j, Y') ?: '',
            'head_signature' => ['html' => $this->signatureImage($approval['snapshot'], 120, 24)],
            'head_name' => (string) ($approval['name'] ?? ''),
            'head_role' => (string) ($approval['designation'] ?? 'SPMU Admin / Head'),
            'approval_date' => $approval['signed_at']?->format('F j, Y') ?: '',
            'issued_by_name' => (string) ($custody->releasedBy?->full_name ?? ''),
            'issued_by_role' => $custody->releasedBy ? 'SPMU Action Officer' : '',
            'date_released' => $custody->released_at?->format('F j, Y')
                ?: $custody->scheduled_release_at?->format('F j, Y')
                ?: '',
            /* The official-layout field uses the actual physical release
             * event, never approval, generation, or pickup-schedule time. */
            'release_time' => $custody->released_at
                ? $custody->released_at->copy()->timezone('Asia/Manila')->format('g:i A')
                : '',
            'date_returned' => $returnDate,
            'return_remarks' => $returnRemarks,
            'effectivity_date' => (string) ($defaults['footer_effectivity'] ?? ''),
            'revision' => preg_replace('/^Rev\.\s*/i', '', (string) ($defaults['footer_revision'] ?? '')),
            'page_no' => (string) $pageNumber,
            'page_total' => (string) $pageCount,
            'items' => $custody->lines
                ->filter(fn ($line) => (float) $line->quantity_to_receive > 0)
                ->map(fn ($line): array => [
                    'qty' => (string) (int) round((float) $line->quantity_to_receive),
                    'unit' => (string) ($line->requestItem?->unit_snapshot ?? ''),
                    'description' => (string) ($line->requestItem?->description_snapshot ?? ''),
                ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function laundryFormExcelData(CustodyTransaction $custody, int $pageNumber, int $pageCount): array
    {
        $custody->loadMissing([
            'request.borrower',
            'request.currentVersion.borrowerSignature.file',
            'request.currentVersion.approvalSteps.approver',
            'request.currentVersion.approvalSteps.signatureSnapshot.file',
            'lines.requestItem.inventoryItem',
            'laundryJob',
        ]);

        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $approval = $this->approvalSignatory($version);
        $defaults = $this->templateDefinitions->defaults('LAUNDRY_FORM');

        $designation = trim((string) ($borrower?->designation ?? ''));
        if ($designation === ''
            || strcasecmp($designation, AccessClassification::BorrowerOnly->label()) === 0
            || $designation === $borrower?->access_classification?->label()) {
            $designation = '';
        }

        return [
            'office_unit' => (string) ($version?->office_unit ?: $version?->represented_program_department ?: $borrower?->organizationalUnit?->unit_name ?: ''),
            'request_no' => (string) ($custody->request->request_no ?? ''),
            'date_requested' => ($version?->signed_at ?: $version?->submitted_at ?: $version?->created_at)?->format('F j, Y') ?: '',
            'date_completed' => $custody->laundryJob?->worker_completed_at?->format('F j, Y') ?: '',
            'borrower_signature' => ['html' => $this->signatureImage($version?->borrowerSignature, 105, 20)],
            'borrower_name' => (string) ($borrower?->full_name ?? ''),
            'borrower_position' => $designation,
            'request_date' => ($version?->signed_at ?: $version?->submitted_at ?: $version?->created_at)?->format('F j, Y') ?: '',
            'head_signature' => ['html' => $this->signatureImage($approval['snapshot'], 105, 20)],
            'head_name' => (string) ($approval['name'] ?? ''),
            'head_role' => (string) ($approval['designation'] ?? 'SPMU Admin / Head'),
            'approval_date' => $approval['signed_at']?->format('F j, Y') ?: '',
            /* Laundry Personnel are offline/wet-signature actors. */
            'issued_by_name' => '',
            'issued_by_role' => '',
            'date_released' => '',
            'effectivity_date' => (string) ($defaults['footer_effectivity'] ?? ''),
            'revision' => preg_replace('/^Rev\.\s*/i', '', (string) ($defaults['footer_revision'] ?? '')),
            'page_no' => (string) $pageNumber,
            'page_total' => (string) $pageCount,
            'items' => $custody->lines
                ->filter(fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
                    && (float) $line->quantity_to_receive > 0)
                ->map(fn ($line): array => [
                    'qty' => (string) (int) round((float) $line->quantity_to_receive),
                    'unit' => (string) ($line->requestItem?->unit_snapshot ?? ''),
                    'description' => (string) ($line->requestItem?->description_snapshot ?? ''),
                ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function gatePassExcelData(CustodyTransaction $custody, int $pageNumber, int $pageCount): array
    {
        $custody->loadMissing([
            'request.borrower',
            'request.currentVersion.borrowerSignature.file',
            'lines.requestItem.inventoryItem',
            'gatePass.preparedVerifier',
            'gatePass.preparedVerifierSignature.file',
            'gatePass.approver',
            'gatePass.approverSignature.file',
        ]);

        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $gatePass = $custody->gatePass;
        $defaults = $this->templateDefinitions->defaults('GATE_PASS');

        return [
            'gate_pass_no' => (string) $custody->custody_no,
            'document_date' => $custody->scheduled_release_at?->format('m-d-Y') ?: now()->format('m-d-Y'),
            'borrower_name' => (string) ($borrower?->full_name ?? ''),
            'borrower_signature' => ['html' => $this->centeredSignatureImage($version?->borrowerSignature, 120, 24)],
            'purpose' => (string) ($gatePass?->purpose ?: $version?->purpose_event ?: ''),
            'destination' => (string) ($gatePass?->destination ?? ''),
            'ao_signature' => ['html' => $this->centeredSignatureImage($gatePass?->preparedVerifierSignature, 120, 24)],
            'ao_name' => (string) ($gatePass?->preparedVerifier?->full_name ?: 'SPMU ACTION OFFICER'),
            'ao_role' => 'SPMU Action Officer',
            'head_signature' => ['html' => $this->centeredSignatureImage($gatePass?->approverSignature, 120, 24)],
            'head_name' => (string) ($gatePass?->approver?->full_name ?: 'SPMU HEAD'),
            'head_role' => 'Head, Supply and Property Management Unit',
            'effectivity_date' => (string) ($defaults['footer_effectivity'] ?? ''),
            'revision' => preg_replace('/^Rev\.\s*/i', '', (string) ($defaults['footer_revision'] ?? '')),
            'page_no' => (string) $pageNumber,
            'page_total' => (string) $pageCount,
            'items' => $custody->lines
                ->filter(fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
                    && (float) $line->quantity_to_receive > 0)
                ->map(fn ($line): array => [
                    'qty' => (string) (int) round((float) $line->quantity_to_receive),
                    'unit' => (string) ($line->requestItem?->unit_snapshot ?? ''),
                    'description' => (string) ($line->requestItem?->description_snapshot ?? ''),
                ])->values()->all(),
        ];
    }

    private function activeTemplate(string $type): ?DocumentTemplate
    {
        if (! in_array($type, ['BORROWER_SLIP', 'LAUNDRY_FORM', 'GATE_PASS', 'BILLING_STATEMENT', 'ACCOUNTABILITY_COMPLIANCE_NOTICE', 'ADMINISTRATIVE_SANCTION_NOTICE', 'RSLDDP'], true)) {
            return null;
        }

        return DocumentTemplate::query()
            ->with(['file', 'renderFile'])
            ->where('document_type', $type)
            ->where('status', 'ACTIVE')
            ->orderByDesc('template_version')
            ->first();
    }

    private function officialHtml(
        string $title,
        array $lines,
        bool $documentShell = true
    ): string {
        $body = '<section class="official"><header><div class="seal">CSPC</div><div><strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong><span>Supply and Property Management Unit</span></div></header><h1>'.e($title).'</h1><div class="lines">';

        foreach ($lines as $line) {
            $body .= $line === ''
                ? '<div class="spacer"></div>'
                : '<p>'.e($line).'</p>';
        }

        $body .= '</div><footer>Controlled document · Asia/Manila · Operational records are maintained in SPMU-ACPMP</footer></section>';

        return $documentShell
            ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>'
            : $body;
    }

    /**
     * Render one immutable signature snapshot as an embedded image.
     *
     * The snapshot file is a byte-for-byte copy captured at signing time, so a
     * later replacement of the signer's registered E-signature can never change
     * a document that was already generated.
     *
     * Returns an empty string when there is no snapshot, so every caller keeps
     * its existing blank handwritten-signature space. Historical records that
     * predate role-based E-signature capture therefore render unchanged.
     */
    /**
     * A signature image that centres reliably in Dompdf.
     *
     * Dompdf supports neither flexbox nor `margin:0 auto` on a replaced
     * element whose width stays auto, so the image is rendered inline-level
     * and centred by the container's text-align. Aspect ratio is preserved by
     * constraining both max dimensions and leaving width/height auto.
     */
    private function centeredSignatureImage(
        ?SignatureSnapshot $snapshot,
        int $maxWidthPt = 150,
        int $maxHeightPt = 40
    ): string {
        $image = $this->signatureImage($snapshot, $maxWidthPt, $maxHeightPt);

        if ($image === '') {
            return '';
        }

        return str_replace(
            'style="display:block;margin:0 auto;',
            'style="display:inline-block;vertical-align:bottom;',
            $image
        );
    }

    private function signatureImage(
        ?SignatureSnapshot $snapshot,
        int $maxWidthPt = 150,
        int $maxHeightPt = 40
    ): string {
        if (! $snapshot) {
            return '';
        }

        $snapshot->loadMissing('file');
        $file = $snapshot->file;

        if (! $file) {
            return '';
        }

        try {
            $bytes = $this->files->bytes($file);
        } catch (Throwable $exception) {
            /*
             * A controlled document must never fail to generate because one
             * signature image is unreadable. Fall back to the blank signature
             * space and leave the printed name/date intact.
             */
            Log::warning('Signature snapshot file unavailable during document rendering.', [
                'signature_snapshot_id' => $snapshot->id,
                'stored_file_id' => $file->id,
                'exception' => $exception->getMessage(),
            ]);

            return '';
        }

        return '<img src="data:'.e($file->mime_type ?: 'image/png').';base64,'.base64_encode($bytes).'"'
            .' alt="" style="display:block;margin:0 auto;'
            .'max-width:'.$maxWidthPt.'pt;max-height:'.$maxHeightPt.'pt;object-fit:contain;">';
    }

    private function templatePrintedDesignation(?User $user): string
    {
        $designation = trim((string) ($user?->designation ?? ''));
        if ($designation === ''
            || strcasecmp($designation, AccessClassification::BorrowerOnly->label()) === 0
            || $designation === $user?->access_classification?->label()) {
            return '';
        }

        return $designation;
    }

    /**
     * Supply the uploaded-template renderer with one immutable signature
     * snapshot. Missing or unreadable snapshots deliberately remain blank;
     * rendering a controlled document must never manufacture a signature.
     *
     * @return array{kind:'signature_image',bytes:string,mime_type:string}|null
     */
    private function templateSignatureAsset(?SignatureSnapshot $snapshot): ?array
    {
        if (! $snapshot) {
            return null;
        }

        $snapshot->loadMissing('file');
        $file = $snapshot->file;
        if (! $file) {
            return null;
        }

        try {
            $bytes = $this->files->bytes($file);
        } catch (Throwable $exception) {
            Log::warning('Signature snapshot file unavailable for template rendering.', [
                'operation' => 'template_signature_image',
                'signature_snapshot_id' => $snapshot->id,
                'stored_file_id' => $file->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return [
            'kind' => 'signature_image',
            'bytes' => $bytes,
            'mime_type' => (string) ($file->mime_type ?: 'image/png'),
        ];
    }

    /**
     * Resolve the SPMU Head (or formally delegated officer) who approved this
     * request version, together with the immutable approval snapshot.
     *
     * This is the single approval action that authorizes the borrowing, the
     * off-campus movement, and the linen processing, so it is the correct
     * "Approved By" signatory on the Borrower Slip, Gate Pass, and Laundry Form.
     *
     * @return array{name: string, designation: string, snapshot: ?SignatureSnapshot, signed_at: ?CarbonInterface}
     */
    private function approvalSignatory(?RequestVersion $version, bool $includeSignature = true): array
    {
        $step = $version?->approvalSteps
            ?->first(
                fn ($candidate) => $candidate->stage_code?->value === 'SPMU'
                    && $candidate->decision === 'APPROVED'
            );

        $approver = $step?->approver;
        $designation = trim((string) $approver?->designation);

        return [
            'name' => $approver?->full_name ?: '',
            'designation' => $designation,
            'snapshot' => $includeSignature ? $step?->signatureSnapshot : null,
            'signed_at' => $step?->decided_at,
        ];
    }

    /**
     * Resolve the SPMU Action Officer who physically issued the property.
     *
     * This is deliberately separate from the approval signatory above and from
     * the borrower's handwritten receipt signature.
     *
     * @return array{name: string, designation: string, snapshot: ?SignatureSnapshot, signed_at: ?CarbonInterface}
     */
    private function issuanceSignatory(CustodyTransaction $custody, bool $includeSignature = true): array
    {
        $officer = $custody->releasedBy;
        $designation = trim((string) $officer?->designation);

        return [
            'name' => $officer?->full_name ?: '',
            'designation' => $designation !== '' ? $designation : 'SPMU Action Officer',
            'snapshot' => $includeSignature ? $custody->releaseSignature : null,
            'signed_at' => $custody->released_at,
        ];
    }

    /**
     * Resolve the most recent physical-return inspection recorded for this
     * custody transaction, for Borrower's Slip rendering. Return existence is
     * established by the persisted ReturnTransaction record itself — Return
     * Inspection does not capture any Action Officer E-signature, so this
     * never depends on a signature snapshot. Findings are filtered to actual
     * adverse conditions only (Fine/Good quantities are omitted); EARLY/
     * NORMAL/OVERDUE remains a system/audit classification and is not part of
     * this rendering data.
     *
     * ReturnTransaction.remarks (the Action Officer's generic free-text note)
     * is deliberately excluded from this return value — it stays persisted
     * for internal/audit use but is never printed on the Borrower's Slip.
     *
     * @return array{
     *     exists: bool,
     *     signed_at: ?CarbonInterface,
     *     findings: list<string>,
     *     received_by_name: string,
     *     received_by_designation: string,
     *     signature: ?SignatureSnapshot
     * }
     */
    private function returnInspectionData(CustodyTransaction $custody, bool $includeSignature = true): array
    {
        /*
         * A mixed custody may be returned in more than one real inspection
         * (for example, non-linen items received immediately and a
         * laundry-required line accounted for separately once its
         * accomplished Laundry Form arrives - see LinenReturnInspectionForm
         * "Mixed custody records non linen and linen as separate complete
         * return branches"). Every earlier adverse finding must keep
         * printing after a later, unrelated, clean return -- so findings
         * are aggregated across every recorded ReturnTransaction, not just
         * the most recent one. "Received by" / signature still reflect the
         * latest inspection, since only one signer can be shown there.
         */
        $return = $custody->returns
            ->sortByDesc(fn ($candidate) => $candidate->received_at?->getTimestamp() ?? 0)
            ->first();

        $conditionLabels = [
            'DAMAGED' => 'damaged',
            'DESTROYED' => 'destroyed',
            'MISSING' => 'missing',
            'LOST' => 'lost',
            'STOLEN' => 'stolen',
        ];

        $findings = $custody->returns
            ->flatMap(fn ($candidate) => $candidate->lines ?? collect())
            ->groupBy('custody_line_id')
            ->map(function ($lines) use ($conditionLabels): ?string {
                $firstLine = $lines->first();
                $description = trim((string) $firstLine?->custodyLine?->requestItem?->description_snapshot);
                $description = $description !== '' ? $description : 'Returned item';

                $breakdown = $lines
                    ->groupBy(fn ($line) => strtoupper((string) $line->condition_code))
                    ->map(fn ($conditionLines) => $conditionLines->sum(fn ($line) => (float) $line->quantity_received));

                $parts = $breakdown
                    ->except('FINE')
                    ->filter(fn ($quantity) => (float) $quantity > 0)
                    ->map(function ($quantity, $condition) use ($conditionLabels): string {
                        $displayQuantity = (float) $quantity;
                        $displayQuantity = floor($displayQuantity) === $displayQuantity
                            ? (string) (int) $displayQuantity
                            : rtrim(rtrim(number_format($displayQuantity, 3, '.', ''), '0'), '.');
                        $label = $conditionLabels[$condition] ?? str($condition)->replace('_', ' ')->lower();

                        return $displayQuantity.' '.$label;
                    })
                    ->values()
                    ->implode('; ');

                return $parts !== '' ? $description.' — '.$parts : null;
            })
            ->filter()
            ->values()
            ->all();

        return [
            'exists' => $return !== null,
            'signed_at' => $return?->received_at,
            'findings' => $findings,
            'received_by_name' => (string) ($return?->receivedBy?->full_name ?? ''),
            'received_by_designation' => (string) ($return?->receivedBy?->designation ?? ''),
            'signature' => $includeSignature ? $return?->inspectionSignature : null,
        ];
    }

    private function formalDateTime(?CarbonInterface $date): ?string
    {
        if (! $date) {
            return null;
        }

        $localized = CarbonImmutable::instance($date)->setTimezone('Asia/Manila');

        return str_replace([' am', ' pm'], [' a.m.', ' p.m.'], $localized->format('j F Y, g:i a'));
    }

    private function officialCss(): string
    {
        return '<style>
            @page{margin:34px 42px}
            *{box-sizing:border-box}
            body{margin:0;color:#16314c;font-family:DejaVu Sans,Arial,sans-serif;font-size:10px}
            .official{min-height:720px;position:relative;padding-bottom:36px}
            .official header{display:flex;align-items:center;gap:12px;padding-bottom:12px;border-bottom:2px solid #0b3156}
            .seal{display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:10px;background:#0b3156;color:#fff;font-weight:bold}
            .official header strong,.official header span{display:block}
            .official header strong{font-size:13px}
            .official header span{color:#60758a}
            .official h1{text-align:center;margin:22px 0 18px;color:#0b3156;font-size:17px;text-transform:uppercase}
            .lines p{margin:0 0 6px;padding:0 0 4px;border-bottom:1px solid #e1e8ef}
            .spacer{height:8px}
            .signature-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:18px}
            .signature-block{min-height:125px;padding:10px;border:1px solid #cdd9e5;border-radius:8px;text-align:center}
            .signature-block small,.signature-block strong,.signature-block span,.signature-block code{display:block}
            .signature-block small{color:#60758a;text-transform:uppercase}
            .signature-block span,.signature-block code{font-size:8px;color:#60758a}
            .signature-image{display:block;max-width:170px;max-height:60px;margin:5px auto}
            .signature-missing{height:55px;padding-top:20px;color:#8b97a4}
            .official footer{position:absolute;bottom:0;left:0;right:0;padding-top:8px;border-top:1px solid #dbe3eb;color:#6c7d8d;text-align:center;font-size:8px}
            .page-break{page-break-after:always}

            .packet-request{color:#24364a;font-size:9.7px;line-height:1.42;padding-bottom:40px}
            .packet-header{width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 10px;border-bottom:1.5px solid #0b3156}
            .packet-header td{border:0;padding:0 0 10px;vertical-align:middle}
            .packet-header-logo-cell{width:62px;padding-right:10px}
            .packet-logo{display:block;width:52px;height:52px;object-fit:contain;margin:0}
            .packet-header-copy{text-align:left}
            .packet-header-copy strong{display:block;font-family:DejaVu Serif,serif;font-size:14.2px;line-height:1.12;letter-spacing:.18px;color:#0b3156}
            .packet-header-copy span{display:block;margin-top:3px;font-size:8.8px;font-weight:bold;color:#33495e}
            .packet-title-block{text-align:center;padding:14px 0 9px;border-bottom:1px solid #c7cfd6}
            .packet-title-block h1{margin:0 0 8px;color:#0b3156;font-family:DejaVu Serif,serif;font-size:18px;line-height:1.1;letter-spacing:.75px;font-weight:bold;text-transform:uppercase}
            .packet-meta{font-size:7.8px;color:#5d6975;white-space:nowrap}
            .packet-meta b{color:#34485c;text-transform:uppercase;font-size:7.1px;letter-spacing:.12px}
            .packet-section-title{margin:12px 0 7px;padding:0 0 4px;border-bottom:1.2px solid #73879a;background:transparent;color:#0b3156;font-size:9.4px;line-height:1.2;font-weight:bold;text-transform:uppercase;letter-spacing:.35px}
            .packet-info-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
            .packet-info-grid td{width:50%;padding:0 20px 8px 0;border:0;vertical-align:top}
            .packet-info-grid td:nth-child(2){padding-right:0;padding-left:8px}
            .packet-note{margin:2px 0 9px;font-size:8.7px;line-height:1.42;color:#526272;text-align:justify}
            .packet-signature-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:2px;page-break-inside:avoid}
            .packet-signature-grid td{width:50%;padding:7px 18px 8px;border:0;vertical-align:top;text-align:center}
            .packet-signature-grid tr+tr td{padding-top:10px}
            .packet-signature-label{font-size:8px;font-weight:bold;text-transform:uppercase;color:#0b3156;letter-spacing:.18px}
            .packet-signature-space{height:48px;padding-top:2px}
            .packet-signature-name{padding-top:3px;border-top:1px solid #7e8d9a;font-size:9.6px;font-weight:bold;color:#24384b}
            .packet-signature-role{margin-top:2px;font-size:7.9px;color:#45586b}
            .packet-signature-date{margin-top:3px;font-size:7.4px;color:#5f6f7e}
            .packet-signature-integrity{margin-top:2px;font-size:6.7px;color:#7a8793}
            .packet-footer{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding-top:7px;border-top:1px solid #d3d9df;color:#6b7783;text-align:left;font-size:7.2px}

            .gate-pass{color:#24364a;font-size:9.7px;line-height:1.42;padding-bottom:40px}
            .gate-pass .gate-header{width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 10px;border-bottom:1.5px solid #0b3156}
            .gate-pass .gate-header td{border:0;padding:0 0 10px;vertical-align:middle}
            .gate-pass .gate-header-logo-cell{width:62px;padding-right:10px}
            .gate-logo{display:block;width:52px;height:52px;object-fit:contain;margin:0}
            .gate-pass .gate-header-copy{width:auto;text-align:left}
            .gate-header-copy strong{display:block;font-family:DejaVu Serif,serif;font-size:14.2px;line-height:1.12;letter-spacing:.18px;color:#0b3156}
            .gate-header-copy span{display:block;margin-top:3px;font-size:8.8px;font-weight:bold;color:#33495e}
            .gate-title-block{text-align:center;padding:14px 0 9px;border-bottom:1px solid #c7cfd6}
            .gate-pass .gate-title-block h1{margin:0 0 8px;color:#0b3156;font-family:DejaVu Serif,serif;font-size:18px;line-height:1.1;letter-spacing:.75px;font-weight:bold;text-transform:uppercase}
            .gate-meta{font-size:7.8px;color:#5d6975;white-space:nowrap}
            .gate-meta b{color:#34485c;text-transform:uppercase;font-size:7.1px;letter-spacing:.12px}
            .gate-section-title{margin:12px 0 7px;padding:0 0 4px;border-bottom:1.2px solid #73879a;background:transparent;color:#0b3156;font-size:9.4px;line-height:1.2;font-weight:bold;text-transform:uppercase;letter-spacing:.35px}
            .gate-info-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
            .gate-info-grid td{width:50%;padding:0 20px 8px 0;border:0;vertical-align:top}
            .gate-info-grid td:nth-child(2){padding-right:0;padding-left:8px}
            .gate-intro{margin:1px 0 10px;color:#2e3d4c;font-size:9.5px;line-height:1.48;text-align:justify}
            .gate-items-table,.gate-guard-table{width:100%;border-collapse:collapse;table-layout:fixed}
            .gate-items-table th,.gate-items-table td,.gate-guard-table th,.gate-guard-table td{border:1px solid #8d9aa6;padding:5px 7px;vertical-align:middle}
            .gate-items-table thead th{background:#e9edf1;color:#273b4f;font-size:8px;font-weight:bold;text-transform:uppercase;letter-spacing:.2px;text-align:center}
            .gate-items-table thead th:nth-child(2){text-align:left}
            .gate-items-table td{font-size:9.5px;color:#26394d}
            .gate-items-table .item-number{width:7%;text-align:center}
            .gate-items-table th:nth-child(2){width:49%}
            .gate-items-table .numeric{width:13%;text-align:center}
            .gate-items-table .unit-cell{width:13%;text-align:center}
            .gate-items-table .use-cell{width:18%;text-align:center}
            .gate-certification{margin:0 0 7px;font-size:9.5px;line-height:1.48;color:#2e3d4c;text-align:justify}
            .gate-signatures{width:100%;border-collapse:collapse;table-layout:fixed;margin:5px 0 2px;page-break-inside:avoid}
            .gate-signatures td{width:50%;padding:5px 15px 0;border:0;text-align:center;vertical-align:top}
            .gate-signatures td:first-child{padding-left:18px;padding-right:20px}
            .gate-signatures td:last-child{padding-left:20px;padding-right:18px}
            .gate-guard-note{margin:0 0 5px;font-size:8.5px;line-height:1.4;color:#596979}
            .gate-guard-table{margin-top:1px;page-break-inside:avoid}
            .gate-guard-table th{width:15%;background:#f3f5f7;color:#44576a;text-align:left;font-size:7.8px;font-weight:bold;text-transform:uppercase;letter-spacing:.15px}
            .gate-guard-table td{width:35%;height:31px;font-size:9px;color:#26394d}
            .gate-note{margin:9px 0 0;padding:0;border:0;background:transparent;font-size:7.8px;line-height:1.4;color:#667481}
            .gate-pass .gate-footer{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding-top:7px;border-top:1px solid #d3d9df;color:#6b7783;text-align:left;font-size:7.2px}

            .borrower-slip{color:#24364a;font-size:9.7px;line-height:1.42;padding-bottom:40px}
            .borrower-slip .borrower-header{width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 10px;border-bottom:1.5px solid #0b3156}
            .borrower-slip .borrower-header td{border:0;padding:0 0 10px;vertical-align:middle}
            .borrower-slip .borrower-header-logo-cell{width:62px;padding-right:10px}
            .borrower-logo{display:block;width:52px;height:52px;object-fit:contain;margin:0}
            .borrower-slip .borrower-header-copy{width:auto;text-align:left}
            .borrower-header-copy strong{display:block;font-family:DejaVu Serif,serif;font-size:14.2px;line-height:1.12;letter-spacing:.18px;color:#0b3156}
            .borrower-header-copy span{display:block;margin-top:3px;font-size:8.8px;font-weight:bold;color:#33495e}
            .borrower-title-block{text-align:center;padding:14px 0 9px;border-bottom:1px solid #c7cfd6}
            .borrower-slip .borrower-title-block h1{margin:0 0 8px;color:#0b3156;font-family:DejaVu Serif,serif;font-size:18px;line-height:1.1;letter-spacing:.75px;font-weight:bold;text-transform:uppercase}
            .borrower-meta{font-size:7.8px;color:#5d6975;white-space:nowrap}
            .borrower-meta b{color:#34485c;text-transform:uppercase;font-size:7.1px;letter-spacing:.12px}
            .borrower-section-title{margin:12px 0 7px;padding:0 0 4px;border-bottom:1.2px solid #73879a;background:transparent;color:#0b3156;font-size:9.4px;line-height:1.2;font-weight:bold;text-transform:uppercase;letter-spacing:.35px}
            .borrower-info-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
            .borrower-info-grid td{width:50%;padding:0 20px 8px 0;border:0;vertical-align:top}
            .borrower-info-grid td:nth-child(2){padding-right:0;padding-left:8px}
            .borrower-intro{margin:1px 0 10px;color:#2e3d4c;font-size:9.5px;line-height:1.48;text-align:justify}
            .borrower-items-table{width:100%;border-collapse:collapse;table-layout:fixed}
            .borrower-items-table th,.borrower-items-table td{border:1px solid #8d9aa6;padding:5px 6px;vertical-align:middle}
            .borrower-items-table thead th{background:#e9edf1;color:#273b4f;font-size:7.6px;font-weight:bold;text-transform:uppercase;letter-spacing:.15px;text-align:center}
            .borrower-items-table thead th:nth-child(2){text-align:left}
            .borrower-items-table td{font-size:8.9px;color:#26394d}
            .borrower-items-table .item-number{width:5.5%;text-align:center}
            .borrower-items-table th:nth-child(2){width:35%}
            .borrower-items-table .numeric{width:10%;text-align:center}
            .borrower-items-table .final-issued{width:11.5%}
            .borrower-items-table .unit-cell{width:10%;text-align:center}
            .borrower-items-table .use-cell{width:14%;text-align:center}
            .borrower-items-table .status-cell{width:14%;text-align:center;text-transform:uppercase}
            .borrower-certification{margin:0 0 5px;font-size:9.5px;line-height:1.48;color:#2e3d4c;text-align:justify}
            .borrower-ack-table{width:100%;border-collapse:collapse;table-layout:fixed;margin:3px 0 2px;page-break-inside:avoid}
            .borrower-ack-table td{border:0;vertical-align:top}
            .borrower-ack-table .ack-spacer{width:42%}
            .borrower-ack-table .ack-block{width:58%;padding:2px 18px 0;text-align:center}
            .ack-caption{margin-bottom:2px;font-size:8.5px;color:#566779}
            .borrower-release-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:2px;page-break-inside:avoid}
            .borrower-release-table th,.borrower-release-table td{border:1px solid #8d9aa6;padding:6px 7px;vertical-align:middle}
            .borrower-release-table th{width:18%;background:#f3f5f7;color:#44576a;text-align:left;font-size:7.8px;font-weight:bold;text-transform:uppercase;letter-spacing:.15px}
            .borrower-release-table td{width:32%;font-size:9px;color:#26394d}
            .borrower-slip .borrower-footer{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding-top:7px;border-top:1px solid #d3d9df;color:#6b7783;text-align:left;font-size:7.2px}

            .laundry-form{color:#24364a;font-size:9.7px;line-height:1.42;padding-bottom:40px}
            .laundry-form .laundry-header{width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 10px;border-bottom:1.5px solid #0b3156}
            .laundry-form .laundry-header td{border:0;padding:0 0 10px;vertical-align:middle}
            .laundry-form .laundry-header-logo-cell{width:62px;padding-right:10px}
            .laundry-logo{display:block;width:52px;height:52px;object-fit:contain;margin:0}
            .laundry-form .laundry-header-copy{width:auto;text-align:left}
            .laundry-header-copy strong{display:block;font-family:DejaVu Serif,serif;font-size:14.2px;line-height:1.12;letter-spacing:.18px;color:#0b3156}
            .laundry-header-copy span{display:block;margin-top:3px;font-size:8.8px;font-weight:bold;color:#33495e}
            .laundry-title-block{text-align:center;padding:14px 0 9px;border-bottom:1px solid #c7cfd6}
            .laundry-form .laundry-title-block h1{margin:0 0 8px;color:#0b3156;font-family:DejaVu Serif,serif;font-size:18px;line-height:1.1;letter-spacing:.75px;font-weight:bold;text-transform:uppercase}
            .laundry-meta{font-size:7.8px;color:#5d6975;white-space:nowrap}
            .laundry-meta b{color:#34485c;text-transform:uppercase;font-size:7.1px;letter-spacing:.12px}
            .meta-separator{display:inline-block;margin:0 7px;color:#8a98a5}
            .laundry-section-title{margin:12px 0 7px;padding:0 0 4px;border-bottom:1.2px solid #73879a;background:transparent;color:#0b3156;font-size:9.4px;line-height:1.2;font-weight:bold;text-transform:uppercase;letter-spacing:.35px}
            .laundry-info-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
            .laundry-info-grid td{width:50%;padding:0 20px 8px 0;border:0;vertical-align:top}
            .laundry-info-grid td:nth-child(2){padding-right:0;padding-left:8px}
            .field-label,.field-value{display:block}
            .field-label{margin-bottom:2px;color:#4a5967;font-size:7.8px;font-weight:bold;text-transform:uppercase;letter-spacing:.18px}
            .field-value{color:#26394d;font-size:10px;line-height:1.3}
            .laundry-intro{margin:1px 0 10px;color:#2e3d4c;font-size:9.5px;line-height:1.48;text-align:justify}
            .laundry-items-table{width:100%;border-collapse:collapse;table-layout:fixed}
            .laundry-items-table th,.laundry-items-table td{border:1px solid #8d9aa6;padding:5px 7px;vertical-align:middle}
            .laundry-items-table thead th{background:#e9edf1;color:#273b4f;font-size:8px;font-weight:bold;text-transform:uppercase;letter-spacing:.2px;text-align:center}
            .laundry-items-table thead th:nth-child(2){text-align:left}
            .laundry-items-table td{font-size:9.5px;color:#26394d}
            .laundry-items-table .item-number{width:7%;text-align:center}
            .laundry-items-table .numeric{width:13%;text-align:center}
            .laundry-items-table .unit-cell{width:16%;text-align:center}
            .empty-cell{text-align:center;color:#7c8b98;font-style:italic}
            .laundry-certification{margin:0 0 7px;font-size:9.5px;line-height:1.48;color:#2e3d4c;text-align:justify}
            .laundry-signatures{width:100%;border-collapse:collapse;table-layout:fixed;margin:5px 0 2px;page-break-inside:avoid}
            .laundry-signatures td{width:50%;padding:5px 15px 0;border:0;text-align:center;vertical-align:top}
            .laundry-signatures td:first-child{padding-left:18px;padding-right:20px}
            .laundry-signatures td:last-child{padding-left:20px;padding-right:18px}
            .signature-label{font-size:8px;font-weight:bold;text-transform:uppercase;color:#0b3156;letter-spacing:.18px}
            .signature-space{height:58px;padding-top:5px}
            .formal-signature-image{display:block;max-width:175px;max-height:52px;margin:0 auto}
            .signature-placeholder{padding-top:22px;font-size:8.5px;color:#7f8e9b}
            .signature-name{padding-top:3px;border-top:1px solid #7e8d9a;font-size:10px;font-weight:bold;text-transform:uppercase;color:#24384b}
            .signature-role{margin-top:2px;font-size:8.4px;color:#45586b}
            .signature-subrole{margin-top:1px;font-size:8px;color:#45586b}
            .signature-date{margin-top:3px;font-size:7.8px;color:#5f6f7e}
            .signature-integrity,.signature-note{margin-top:2px;font-size:6.9px;color:#7a8793}
            .signature-note{font-style:italic}
            .write-line{background:#fff}
            .condition-cell{height:34px}
            .check-box{font-family:DejaVu Sans,sans-serif;font-size:12px;vertical-align:-1px}
            .condition-gap{display:inline-block;width:20px}
            .laundry-note{margin:9px 0 0;padding:0;border:0;background:transparent;font-size:7.8px;line-height:1.4;color:#667481}
            .laundry-form .laundry-footer{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding-top:7px;border-top:1px solid #d3d9df;color:#6b7783;text-align:left;font-size:7.2px}
        </style>';
    }

    private function supersede(CustodyTransaction $custody, string $type, string $reason): void
    {
        GeneratedDocument::query()
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('document_type', $type)
            ->where('status', 'FINAL')
            ->update(['status' => 'SUPERSEDED', 'invalidated_at' => now(), 'invalidation_reason' => $reason]);
    }

    private function assertFinalApprovalForOperationalDocument(CustodyTransaction $custody): void
    {
        $custody->loadMissing('request');

        $request = $custody->request;
        $approved = $request
            && (
                $request->final_approved_at !== null
                || in_array(
                    $request->status,
                    [
                        RequestStatus::FinalApprovedAwaitingDownload,
                        RequestStatus::ApprovedReadyForRelease,
                    ],
                    true
                )
            );

        if (! $approved) {
            throw ValidationException::withMessages([
                'document' => 'Borrower Slip, Gate Pass, and other operational forms may be generated only after final SPMU Head approval.',
            ]);
        }
    }
}
