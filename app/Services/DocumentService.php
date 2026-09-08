<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\RequestVersion;
use App\Models\SignatureSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentService
{
    private const NOTHING_FOLLOWS_MARKER = '*** NOTHING FOLLOWS ***';

    public function __construct(
        private SimplePdfService $pdf,
        private ProtectedFileService $files,
        private DocumentTemplateDefinitionService $templateDefinitions,
        private DocumentTemplateRenderer $templateRenderer,
    ) {}

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
            $bytes = $this->templateRenderer->render($customTemplate, $this->borrowerSlipRenderData($custody));
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
        $activeTemplate = $this->activeTemplate('BORROWER_SLIP');

        $templateConfig = $this->templateDefinitions->resolve('BORROWER_SLIP');

        $formCode = e($templateConfig['form_code']);
        $documentTitle = e($templateConfig['title']);
        $referenceLabel = e($templateConfig['reference_label']);
        $requestLabel = e($templateConfig['request_label']);
        $recipientName = e($templateConfig['recipient_name']);
        $recipientPosition = e($templateConfig['recipient_position']);
        $recipientInstitution = e($templateConfig['recipient_institution']);
        $dateLabel = e($templateConfig['date_label']);
        $salutation = e($templateConfig['salutation']);
        $introPrefix = e($templateConfig['intro_prefix']);
        $introSuffix = e($templateConfig['intro_suffix']);
        $qtyLabel = e($templateConfig['qty_label']);
        $unitLabel = e($templateConfig['unit_label']);
        $descriptionLabel = e($templateConfig['description_label']);
        $receiptSignatureLabel = nl2br(e($templateConfig['receipt_signature_label']));
        $remarksHeading = e($templateConfig['remarks_heading']);
        $closing = e($templateConfig['closing']);
        $signatureCaption = e($templateConfig['borrower_signature_caption']);
        $designationLabel = e($templateConfig['designation_label']);
        $approvedLabel = e($templateConfig['approved_label']);
        $footerEffectivity = e($templateConfig['footer_effectivity']);
        $footerRevision = e($templateConfig['footer_revision']);

        $logoPath = resource_path('images/cspc-logo-print.jpg');

        $logo = is_file($logoPath)
            ? '<img src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo" style="width:54px;height:54px;object-fit:contain;">'
            : '<div style="font-weight:bold;font-size:8pt;">CSPC</div>';

        $borrowerName = e((string) $borrower->full_name);
        $borrowerDesignationValue = trim((string) $borrower->designation);
        if ($borrowerDesignationValue === ''
            || strcasecmp($borrowerDesignationValue, AccessClassification::BorrowerOnly->label()) === 0
            || $borrowerDesignationValue === $borrower->access_classification?->label()) {
            $borrowerDesignationValue = '';
        }
        $borrowerDesignation = e($borrowerDesignationValue);
        $purpose = e((string) ($version?->purpose_event ?: ''));

        $requestNo = e((string) ($custody->request->request_no ?? ''));
        $custodyNo = e((string) $custody->custody_no);

        /*
         * Borrower's/accountable person's E-signature for the "Very truly
         * yours / Signature over Printed Name" block. This is the borrower's
         * request certification E-signature captured at submission — it is
         * NOT "BORROWER'S SIGNATURE UPON RECEIPT OF ITEMS" in the item table
         * below, which stays blank for the actual person who physically
         * receives the items (who may be a different, authorized person).
         */
        $borrowerSlipSignature = $this->signatureImage(
            $version?->borrowerSignature,
            140,
            22
        );

        /*
         * "APPROVED:" is the SPMU Head's approval E-signature for this exact
         * request version. Physical Release issuer identity is kept in the
         * system only (released_by_user_id / released_at / audit trail) and is
         * intentionally not printed as an ISSUED BY block on the Borrower's Slip.
         *
         * This does not replace the borrower's handwritten receipt signature in
         * the item table or the handwritten undertaking above; those stay blank.
         */
        $approval = $this->approvalSignatory($version);
        $approverName = e((string) $approval['name']);
        $approverDesignation = e((string) $approval['designation']);
        $approverSignature = $this->signatureImage($approval['snapshot'], 150, 38);

        /*
         * Physical Release issuer information is system-only on the Borrower's
         * Slip. The Action Officer remains fully traceable through the custody
         * release user/timestamp, inventory movement, and audit trail. Gate Pass
         * keeps its separate Action Officer E-signature when applicable.
         */

        /*
         * A physical return is established by the persisted ReturnTransaction
         * record, not by any signature — Return Inspection does not capture
         * an Action Officer E-signature (see receiveReturn()). Before any
         * return is recorded, keep the original blank remark lines for the
         * physical form. Remarks show only adverse findings and stay
         * completely blank when everything returned is Fine/Good; EARLY/
         * NORMAL/OVERDUE is a system/audit classification only and is never
         * printed here.
         */
        $returnInspection = $this->returnInspectionData($custody);
        $returnRemarksBlock =
            '<div style="width:88%;height:16pt;border-bottom:1px solid #111;"></div>'
            .'<div style="width:88%;height:16pt;border-bottom:1px solid #111;"></div>'
            .'<div style="width:88%;height:16pt;border-bottom:1px solid #111;"></div>';

        if ($returnInspection['exists']) {
            /*
             * The printed remarks area shows ONLY the structured adverse
             * findings derived from recorded condition quantities (e.g.
             * "Microphones — 1 damaged"). The generic free-text
             * ReturnTransaction.remarks field is never rendered here, even
             * when an adverse finding exists — it remains persisted for
             * internal Action Officer/audit use only.
             */
            $findingRows = collect($returnInspection['findings'])
                ->map(
                    fn (string $finding): string =>
                        '<div style="margin:0 0 2pt;">'.e($finding).'</div>'
                )
                ->implode('');

            $dateReturned = $returnInspection['signed_at']
                ? e($returnInspection['signed_at']->format('F j, Y'))
                : '';

            $returnRemarksBlock =
                '<div style="width:88%;min-height:14pt;border-bottom:1px solid #111;padding:1pt 0 4pt;font-size:7.3pt;line-height:1.22;">'
                .$findingRows
                .'</div>'
                .'<div style="width:88%;margin-top:6pt;font-size:8pt;"><strong>Date Returned:</strong> '.$dateReturned.'</div>';
        }

        /*
         * The Borrower's Slip is generated immediately after final SPMU Head
         * approval. Its document date therefore uses the immutable approval
         * timestamp instead of the later pickup schedule. This keeps the date
         * populated as soon as the approved slip is generated and prevents a
         * later reschedule/release from changing the original document date.
         */
        $formDate = $approval['signed_at']
            ? $approval['signed_at']->format('m-d-Y')
            : now()->format('m-d-Y');

        $returnDate = $custody->due_at
            ? $custody->due_at->format('F j, Y')
            : '';

        /*
         * Standalone PDF:
         * keep document-control footer anchored to the bottom.
         *
         * Embedded packet version:
         * keep normal flow so it does not interfere with other pages.
         */
        $footerPlacement = $documentShell
            ? 'position:fixed;bottom:0;left:0;right:0;'
            : 'margin-top:27pt;';

        /*
         * Only actual prepared items are printed.
         * Do not generate artificial blank rows.
         */
        $itemRows = '';

        foreach ($custody->lines as $line) {
            $quantity = (int) round((float) $line->quantity_to_receive);
            $unit = e((string) $line->requestItem?->unit_snapshot);
            $description = e((string) $line->requestItem?->description_snapshot);

            $itemRows .=
                '<tr>'
                .'<td style="
                    width:9%;
                    border:1px solid #222;
                    height:15pt;
                    padding:1pt 2pt;
                    text-align:center;
                    vertical-align:middle;
                ">'.$quantity.'</td>'

                .'<td style="
                    width:9%;
                    border:1px solid #222;
                    padding:1pt 2pt;
                    text-align:center;
                    vertical-align:middle;
                ">'.$unit.'</td>'

                .'<td style="
                    width:40%;
                    border:1px solid #222;
                    padding:1pt 4pt;
                    vertical-align:middle;
                ">'.$description.'</td>'

                .'<td style="
                    width:42%;
                    border:1px solid #222;
                    padding:1pt 3pt;
                    vertical-align:middle;
                "></td>'

                .'</tr>';
        }

        // Formal terminal marker: the approved item list is closed and no
        // additional property may be inserted after this controlled copy is generated.
        $itemRows .=
            '<tr>'
            .'<td colspan="4" style="border:1px solid #222;height:15pt;padding:2pt 4pt;text-align:center;vertical-align:middle;font-weight:bold;letter-spacing:.3px;">'
            .self::NOTHING_FOLLOWS_MARKER
            .'</td>'
            .'</tr>';

        $body = <<<HTML
<section style="
    width:100%;
    box-sizing:border-box;
    padding-top:22pt;
    padding-bottom:28pt;
    font-family:Arial, Helvetica, sans-serif;
    font-size:9.5pt;
    line-height:1.18;
    color:#111;
">


    <!-- ======================================================
         CSPC HEADER
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        border-bottom:1.2px solid #222;
        margin:0 0 27pt 0;
    ">
        <tr>

            <td style="
                width:62px;
                vertical-align:middle;
                padding:0 7px 5px 0;
            ">
                {$logo}
            </td>

            <td style="
                vertical-align:middle;
                padding-bottom:5px;
            ">

                <div style="
                    font-size:7.5pt;
                    line-height:1.05;
                ">
                    Republic of the Philippines
                </div>

                <div style="
                    font-size:9pt;
                    font-weight:bold;
                    line-height:1.05;
                ">
                    CAMARINES SUR POLYTECHNIC COLLEGES
                </div>

                <div style="
                    font-size:7.5pt;
                    line-height:1.05;
                ">
                    Nabua, Camarines Sur
                </div>

            </td>

            <td style="
                width:120px;
                text-align:right;
                vertical-align:bottom;
                padding-bottom:5px;
                font-size:6.5pt;
                font-weight:bold;
            ">
                {$formCode}
            </td>

        </tr>
    </table>


    <!-- ======================================================
         TITLE
    ======================================================= -->

    <div style="
        width:93%;
        margin:0 auto 4pt;
        text-align:center;
        font-size:11pt;
        line-height:1;
        font-weight:bold;
    ">
        {$documentTitle}
    </div>

    <!--
        Reference number the SPMU Action Officer uses to verify that this
        presented slip matches the approved request in the system.
    -->
    <div style="
        width:93%;
        margin:0 auto 25pt;
        text-align:center;
        font-size:8pt;
        font-weight:bold;
        letter-spacing:0.3pt;
    ">
        {$referenceLabel} {$custodyNo} &nbsp;|&nbsp; {$requestLabel} {$requestNo}
    </div>


    <!-- ======================================================
         ADDRESSEE + DATE
    ======================================================= -->

    <table style="
        width:93%;
        margin:0 auto 19pt;
        border-collapse:collapse;
        font-size:9.5pt;
        line-height:1.18;
    ">
        <tr>

            <td style="
                width:68%;
                vertical-align:top;
            ">

                <div style="
                    font-size:10pt;
                    font-weight:bold;
                    line-height:1.08;
                ">
                    {$recipientName}
                </div>

                <div style="margin-top:2pt;">
                    {$recipientPosition}
                </div>

                <div style="margin-top:1pt;">
                    {$recipientInstitution}
                </div>

            </td>

            <td style="
                width:32%;
                vertical-align:top;
                text-align:right;
                padding-top:2pt;
            ">

                <strong>{$dateLabel}</strong>

                <span style="
                    display:inline-block;
                    width:82pt;
                    margin-left:4pt;
                    padding-bottom:1pt;
                    border-bottom:1px solid #111;
                    text-align:center;
                ">
                    {$formDate}
                </span>

            </td>

        </tr>
    </table>


    <!-- ======================================================
         MESSAGE
    ======================================================= -->

    <div style="
        width:93%;
        margin:0 auto 8pt;
    ">
        {$salutation}
    </div>

    <p style="
        width:93%;
        margin:0 auto 18pt;
        padding:0;
        font-size:9.5pt;
        line-height:1.28;
        text-indent:31pt;
        text-align:justify;
    ">
        {$introPrefix} <strong>{$purpose}</strong>. {$introSuffix} <strong>{$returnDate}</strong>.
    </p>


    <!-- ======================================================
         ITEMS

         QTY          9%
         UNIT         9%
         DESCRIPTION 40%
         SIGNATURE   42%
    ======================================================= -->

    <table style="
        width:93%;
        margin:0 auto;
        border-collapse:collapse;
        table-layout:fixed;
        font-size:8.5pt;
        line-height:1.05;
    ">

        <thead>

            <tr style="height:25pt;">

                <th style="
                    width:9%;
                    border:1px solid #222;
                    padding:2pt 1pt;
                    text-align:center;
                    vertical-align:middle;
                    font-weight:bold;
                ">
                    {$qtyLabel}
                </th>

                <th style="
                    width:9%;
                    border:1px solid #222;
                    padding:2pt 1pt;
                    text-align:center;
                    vertical-align:middle;
                    font-weight:bold;
                ">
                    {$unitLabel}
                </th>

                <th style="
                    width:40%;
                    border:1px solid #222;
                    padding:2pt 3pt;
                    text-align:center;
                    vertical-align:middle;
                    font-weight:bold;
                ">
                    {$descriptionLabel}
                </th>

                <th style="
                    width:42%;
                    border:1px solid #222;
                    padding:2pt 3pt;
                    text-align:center;
                    vertical-align:middle;
                    font-weight:bold;
                    line-height:1.05;
                ">
                    {$receiptSignatureLabel}
                </th>

            </tr>

        </thead>

        <tbody>
            {$itemRows}
        </tbody>

    </table>


    <!-- ======================================================
         LOWER FORM AREA
    ======================================================= -->

    <table style="
        width:93%;
        margin:5pt auto 0;
        border-collapse:collapse;
    ">

        <tr>


            <!-- ==================================================
                 LEFT SIDE

                 Blank space is intentionally preserved for
                 physical SPMU stamps/annotations.
            =================================================== -->

            <td style="
                width:50%;
                vertical-align:top;
                padding-right:22pt;
            ">

                <div style="height:76pt;"></div>


                <!-- Reference-form equal-sign divider -->

                <div style="
                    width:88%;
                    margin-bottom:17pt;
                    font-family:'Courier New', monospace;
                    font-size:9pt;
                    letter-spacing:0.4pt;
                    white-space:nowrap;
                    overflow:hidden;
                    color:#111;
                ">======================================</div>


                <div style="
                    font-size:9.5pt;
                    font-weight:bold;
                    margin-bottom:8pt;
                ">
                    {$remarksHeading}
                </div>


                {$returnRemarksBlock}

            </td>


            <!-- ==================================================
                 BORROWER SIGNATURE

                 Printed name is system-encoded.
                 Actual signature remains handwritten.
            =================================================== -->

            <td style="
                width:50%;
                vertical-align:top;
                padding-left:18pt;
                text-align:center;
            ">

                <div style="
                    text-align:left;
                    margin:7pt 0 25pt 7pt;
                    font-size:9.5pt;
                ">
                    {$closing}
                </div>


                <!--
                    Borrower's/accountable person's E-signature: the immutable
                    snapshot captured when the borrower request-certified and
                    submitted this exact version. Empty for historical records
                    generated before this rendering existed, which keep this
                    blank handwritten signature space.
                -->

                <div style="height:24pt;">{$borrowerSlipSignature}</div>


                <!-- signature line -->

                <div style="
                    border-bottom:1px solid #111;
                    margin:0 5pt;
                    height:1pt;
                "></div>


                <!-- encoded printed name -->

                <div style="
                    margin-top:2pt;
                    font-size:9.5pt;
                    font-weight:bold;
                    text-transform:uppercase;
                    line-height:1.05;
                ">
                    {$borrowerName}
                </div>


                <!-- close to printed name / line -->

                <div style="
                    margin-top:1pt;
                    font-size:8pt;
                    line-height:1;
                    font-style:italic;
                ">
                    {$signatureCaption}
                </div>


                <!-- actual institutional designation from the borrower profile -->

                <div style="height:24pt;"></div>

                <div style="
                    border-bottom:1px solid #111;
                    margin:0 14pt;
                    min-height:12pt;
                    padding-bottom:1pt;
                    font-size:8.5pt;
                    font-weight:bold;
                    line-height:1.05;
                    text-transform:uppercase;
                ">{$borrowerDesignation}</div>

                <div style="
                    margin-top:1pt;
                    font-size:8pt;
                    line-height:1;
                    font-style:italic;
                ">
                    {$designationLabel}
                </div>

            </td>

        </tr>

    </table>


    <!-- ======================================================
         APPROVAL
    ======================================================= -->

    <div style="
        width:42%;
        margin:29pt auto 0;
        text-align:center;
    ">

        <div style="
            font-size:9.5pt;
            font-weight:bold;
            margin-bottom:6pt;
        ">
            {$approvedLabel}
        </div>


        <!--
            SPMU Head approval E-signature for this exact request version.
            Renders the immutable snapshot captured at approval time, so a later
            replacement of the approver's registered signature cannot alter this
            document. Empty for historical records, which keep the blank
            handwritten signature space.
        -->

        <div style="height:38pt;">{$approverSignature}</div>


        <div style="
            border-bottom:1px solid #111;
            margin:0 8pt;
        "></div>


        <div style="
            margin-top:3pt;
            font-size:9.5pt;
            font-weight:bold;
            text-transform:uppercase;
        ">
            {$approverName}
        </div>


        <div style="
            margin-top:1pt;
            font-size:8pt;
        ">
            {$approverDesignation}
        </div>

    </div>


    <!-- ======================================================
         FIXED DOCUMENT CONTROL FOOTER
    ======================================================= -->

    <table style="
        {$footerPlacement}
        width:100%;
        border-collapse:collapse;
        border-top:1px solid #222;
        font-size:6.5pt;
        line-height:1;
    ">

        <tr>

            <td style="
                width:33%;
                padding-top:4pt;
            ">
                Effective Date: {$footerEffectivity}
            </td>

            <td style="
                width:34%;
                padding-top:4pt;
                text-align:center;
            ">
                {$footerRevision}
            </td>

            <td style="
                width:33%;
                padding-top:4pt;
                text-align:right;
            ">
                Page {$pageNumber} of {$pageCount}
            </td>

        </tr>

    </table>

</section>
HTML;

        return $documentShell
            ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>'
            : $body;
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
            $bytes = $this->templateRenderer->render($customTemplate, $data);
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
        $activeTemplate = $this->activeTemplate('GATE_PASS');

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
            ? '<img src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo" style="width:54px;height:54px;object-fit:contain;">'
            : '<div style="font-size:10px;font-weight:bold;">CSPC</div>';

        $borrowerName = e((string) $borrower->full_name);
        $purpose = e((string) ($version?->purpose_event ?: ''));
        /*
         * The printed Remarks field carries no automatically generated text.
         * Destination, location, designations and approval/signature metadata
         * are never placed here: a designation belongs only under its
         * signatory's printed name, and the destination stays on the Gate Pass
         * record for the workflow without being printed as a remark. The row
         * stays on the form and is filled in by hand when needed.
         */

        $custodyNumber = e((string) $custody->custody_no);

        $formDate = $custody->scheduled_release_at
            ? $custody->scheduled_release_at->format('m-d-Y')
            : ($gatePass?->approved_at?->format('m-d-Y') ?: now()->format('m-d-Y'));

        /*
         * The final Gate Pass carries three immutable system E-signatures:
         *
         * - Bearer / Accountable Person: the borrower signature captured when
         *   the approved request version was E-signed and submitted.
         * - "Verified By": the SPMU Action Officer who verified the submitted
         *   request and required documents before Head review.
         * - "Approved By": the SPMU Head whose approval authorized the request
         *   and therefore the off-campus movement.
         *
         * The guard's "Released by" line remains handwritten at the gate.
         */
        $verifiedName = $gatePass?->preparedVerifier?->full_name
            ? e((string) $gatePass->preparedVerifier->full_name)
            : 'SPMU ACTION OFFICER';

        $approvedName = $gatePass?->approver?->full_name
            ? e((string) $gatePass->approver->full_name)
            : 'SPMU HEAD';

        /*
         * One shared geometry for every Gate Pass signature block so the
         * Bearer, Verified By and Approved By blocks stay identical.
         */
        $verifiedSignature = $this->centeredSignatureImage(
            $gatePass?->preparedVerifierSignature,
            140,
            26
        );

        $approvedSignature = $this->centeredSignatureImage(
            $gatePass?->approverSignature,
            140,
            26
        );

        $borrowerSignature = $this->centeredSignatureImage(
            $version?->borrowerSignature,
            140,
            26
        );

        $offCampusLines = $custody->lines->filter(
            fn ($line) =>
                $line->requestItem?->use_location === 'OFF_CAMPUS'
                && (float) $line->quantity_to_receive > 0
        );

        $itemRows = '';

        foreach ($offCampusLines as $line) {
            $quantity = (int) round((float) $line->quantity_to_receive);
            $unit = e((string) $line->requestItem?->unit_snapshot);
            $description = e((string) $line->requestItem?->description_snapshot);

            $itemRows .=
                '<tr>'
                .'<td style="border:1px solid #222;height:23px;text-align:center;padding:3px 5px;">'.$quantity.'</td>'
                .'<td style="border:1px solid #222;text-align:center;padding:3px 5px;">'.$unit.'</td>'
                .'<td style="border:1px solid #222;padding:3px 7px;">'.$description.'</td>'
                .'</tr>';
        }

        // Close the approved item list formally. The marker occupies the first
        // row after the last approved item so blank rows cannot be mistaken for
        // space where more property may be added later.
        $itemRows .=
            '<tr>'
            .'<td colspan="3" style="border:1px solid #222;height:23px;text-align:center;padding:3px 7px;font-weight:bold;letter-spacing:.3px;">'
            .self::NOTHING_FOLLOWS_MARKER
            .'</td>'
            .'</tr>';

        $minimumRows = 9;
        $existingRows = $offCampusLines->count() + 1; // includes terminal marker

        for ($i = $existingRows; $i < $minimumRows; $i++) {
            $itemRows .=
                '<tr>'
                .'<td style="border:1px solid #222;height:23px;"></td>'
                .'<td style="border:1px solid #222;"></td>'
                .'<td style="border:1px solid #222;"></td>'
                .'</tr>';
        }

        $body = <<<HTML
<section style="
    width:100%;
    box-sizing:border-box;
    font-family:'Times New Roman', Times, serif;
    font-size:12px;
    line-height:1.25;
    color:#111;
">

    <!-- ======================================================
         INSTITUTIONAL HEADER
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        border-bottom:1.5px solid #222;
        margin-bottom:12px;
    ">
        <tr>
            <td style="width:62px;padding:2px 6px 5px 2px;vertical-align:middle;">
                {$logo}
            </td>

            <td style="vertical-align:middle;padding:2px 4px 5px;">
                <div style="font-size:10px;">Republic of the Philippines</div>
                <div style="font-size:12px;font-weight:bold;">
                    CAMARINES SUR POLYTECHNIC COLLEGES
                </div>
                <div style="font-size:10px;">
                    Nabua, Camarines Sur
                </div>
            </td>

            <td style="
                width:120px;
                text-align:right;
                vertical-align:bottom;
                padding-bottom:6px;
                font-size:9px;
                font-weight:bold;
            ">
                {$formCode}
            </td>
        </tr>
    </table>


    <!-- ======================================================
         NUMBER + DATE
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        margin-bottom:2px;
    ">
        <tr>
            <td style="width:67%;"></td>

            <td style="width:33%;font-size:11px;">
                <div>
                    <strong>{$gpNoLabel}</strong>
                    <span style="
                        display:inline-block;
                        width:110px;
                        border-bottom:1px solid #111;
                        text-align:center;
                    ">
                        {$custodyNumber}
                    </span>
                </div>

                <div style="margin-top:3px;">
                    <strong>{$dateLabel}</strong>
                    <span style="
                        display:inline-block;
                        width:110px;
                        border-bottom:1px solid #111;
                        text-align:center;
                    ">
                        {$formDate}
                    </span>
                </div>
            </td>
        </tr>
    </table>


    <!-- ======================================================
         TITLE
    ======================================================= -->

    <div style="
        text-align:center;
        font-weight:bold;
        font-size:15px;
        margin:2px 0 16px;
    ">
        {$documentTitle}
    </div>


    <!-- ======================================================
         INTRO
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        margin-bottom:10px;
    ">
        <tr>
            <td style="
                width:44px;
                vertical-align:top;
                font-weight:bold;
            ">
                {$toLabel}
            </td>

            <td style="vertical-align:top;">
                <strong>{$toValue}</strong>
            </td>
        </tr>
    </table>

    <p style="
        margin:0 0 12px 44px;
        text-align:justify;
        line-height:1.4;
    ">
        {$introPrefix}
        <span style="
            display:inline-block;
            min-width:190px;
            border-bottom:1px solid #111;
            text-align:center;
            font-weight:bold;
        ">
            {$borrowerName}
        </span>
        {$introSuffix}
    </p>


    <!-- ======================================================
         ITEMS
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        table-layout:fixed;
        font-size:11px;
    ">
        <colgroup>
            <col style="width:13%;">
            <col style="width:14%;">
            <col style="width:73%;">
        </colgroup>

        <thead>
            <tr>
                <th style="border:1px solid #222;padding:4px;text-align:center;">
                    {$quantityLabel}
                </th>

                <th style="border:1px solid #222;padding:4px;text-align:center;">
                    {$unitLabel}
                </th>

                <th style="border:1px solid #222;padding:4px;text-align:center;">
                    {$descriptionLabel}
                </th>
            </tr>
        </thead>

        <tbody>
            {$itemRows}

            <tr>
                <td colspan="3" style="
                    border:1px solid #222;
                    padding:6px;
                    min-height:24px;
                ">
                    <strong>{$purposeLabel}</strong>
                    &nbsp; {$purpose}
                </td>
            </tr>

            <tr>
                <td colspan="3" style="
                    border:1px solid #222;
                    padding:6px;
                    min-height:24px;
                ">
                    <strong>{$remarksLabel}</strong>
                    &nbsp;
                </td>
            </tr>
        </tbody>
    </table>


    <!-- ======================================================
         BEARER
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        table-layout:fixed;
        margin-top:16px;
    ">
        <tr>
            <td style="
                width:50%;
                border:0;
                padding:0 24px 0 0;
                vertical-align:top;
            ">
                <div style="font-weight:bold;font-size:10px;">
                    {$bearerLabel}
                </div>

                <div style="
                    height:30px;
                    line-height:30px;
                    text-align:center;
                    border-bottom:1px solid #111;
                ">{$borrowerSignature}</div>

                <div style="
                    text-align:center;
                    font-weight:bold;
                    text-transform:uppercase;
                    margin-top:3px;
                ">
                    {$borrowerName}
                </div>
            </td>

            <td style="width:50%;border:0;padding:0;"></td>
        </tr>
    </table>


    <!-- ======================================================
         VERIFIED + APPROVED
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        table-layout:fixed;
        margin-top:26px;
    ">
        <tr>
            <td style="
                width:50%;
                border:0;
                vertical-align:top;
                padding:0 24px 0 0;
            ">

                <div style="font-weight:bold;margin-bottom:4px;">
                    {$verifiedByLabel}
                </div>

                <div style="
                    height:30px;
                    line-height:30px;
                    text-align:center;
                    border-bottom:1px solid #111;
                ">{$verifiedSignature}</div>

                <div style="
                    text-align:center;
                    font-weight:bold;
                    margin-top:3px;
                ">
                    {$verifiedName}
                </div>

                <div style="
                    text-align:center;
                    font-size:10px;
                ">
                    {$verifiedRole}
                </div>

            </td>

            <td style="
                width:50%;
                border:0;
                vertical-align:top;
                padding:0 0 0 24px;
            ">

                <div style="font-weight:bold;margin-bottom:4px;">
                    {$approvedByLabel}
                </div>

                <div style="
                    height:30px;
                    line-height:30px;
                    text-align:center;
                    border-bottom:1px solid #111;
                ">{$approvedSignature}</div>

                <div style="
                    text-align:center;
                    font-weight:bold;
                    margin-top:3px;
                ">
                    {$approvedName}
                </div>

                <div style="
                    text-align:center;
                    font-size:10px;
                ">
                    {$approvedRole}
                </div>

            </td>
        </tr>
    </table>


    <!-- ======================================================
         GUARD / RELEASE CONTROL
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        table-layout:fixed;
        margin-top:28px;
    ">
        <tr>
            <td style="
                width:50%;
                border:0;
                padding:0 24px 0 0;
                vertical-align:top;
            ">
        <div style="font-weight:bold;margin-bottom:8px;">
            {$releasedByLabel}
        </div>

        <div style="
            height:30px;
            border-bottom:1px solid #111;
        "></div>

        <div style="
            text-align:center;
            font-weight:bold;
            font-size:10px;
            margin-top:3px;
        ">
            {$guardRole}
        </div>

        <div style="margin-top:8px;font-size:10px;">
            {$releasedDateLabel}
            <span style="
                display:inline-block;
                width:105px;
                border-bottom:1px solid #111;
            "></span>
        </div>

        <div style="margin-top:6px;font-size:10px;">
            {$releasedTimeLabel}
            <span style="
                display:inline-block;
                width:105px;
                border-bottom:1px solid #111;
            "></span>
        </div>
            </td>

            <td style="width:50%;border:0;padding:0;"></td>
        </tr>
    </table>


    <!-- ======================================================
         DOCUMENT CONTROL FOOTER
    ======================================================= -->

    <table style="
        width:100%;
        border-collapse:collapse;
        border-top:1px solid #222;
        margin-top:36px;
        font-size:8px;
    ">
        <tr>
            <td style="width:33%;padding-top:4px;">
                Effective Date: {$footerEffectivity}
            </td>

            <td style="width:34%;padding-top:4px;text-align:center;">
                {$footerRevision}
            </td>

            <td style="width:33%;padding-top:4px;text-align:right;">
                Page {$pageNumber} of {$pageCount}
            </td>
        </tr>
    </table>

</section>
HTML;

        return $documentShell
            ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>'
            : $body;
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
            'lines.requestItem.inventoryItem',
        ]);

        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $activeTemplate = $this->activeTemplate('LAUNDRY_FORM');

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

        /*
         * =========================================================
         * CSPC HEADER
         * =========================================================
         */

        $logoPath = resource_path('images/cspc-logo-print.jpg');

        $logo = is_file($logoPath)
            ? '<img src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo" style="width:50px;height:50px;object-fit:contain;">'
            : '<div style="font-weight:bold;font-size:7pt;">CSPC</div>';


        /*
         * =========================================================
         * REQUEST INFORMATION
         * =========================================================
         */

        /*
         * Use the immutable Office / Unit snapshot selected on the borrowing
         * request. The borrower profile organizational unit is only a legacy
         * fallback for older records that predate request-level snapshots.
         */
        $requestingOffice = e((string) (
            $version?->office_unit
            ?: $version?->represented_program_department
            ?: $borrower?->organizationalUnit?->unit_name
            ?: ''
        ));

        $requestNumber = e((string) $custody->request->request_no);
        $borrowerName = e((string) $borrower->full_name);

        /*
         * These dates already exist once this controlled Laundry Form can be
         * generated: the borrower's request/submission date and the SPMU Head
         * approval date. Physical Laundry fields (Date Completed, Issued by,
         * Received by) remain blank for the offline Laundry Personnel.
         */
        $laundryRequestDateSource = $version?->signed_at
            ?: $version?->submitted_at
            ?: $version?->created_at;
        $laundryRequestDate = $laundryRequestDateSource
            ? e($laundryRequestDateSource->format('F j, Y'))
            : '';

        /*
         * SIGNATURE / CONTROL MATRIX SIGNATORIES
         * --------------------------------------
         * Requested by : Borrower — request certification E-signature
         * Approved By  : SPMU Head — approval E-signature for this version
         * Issued by    : Laundry Personnel — HANDWRITTEN/WET signature at pickup
         * Received by  : Laundry Personnel — HANDWRITTEN/WET signature when the
         *                same linen and the same printed Laundry Form are returned.
         *
         * Laundry Personnel are not system users, so the application must never
         * place the SPMU Action Officer's E-signature in either physical Laundry
         * signature cell.
         */
        $laundryApproval = $this->approvalSignatory($version);
        $laundryApproverName = e((string) $laundryApproval['name']);
        $laundryApproverDesignation = e((string) (
            $laundryApproval['designation'] ?: 'ADMIN. OFFICER V, SPMU'
        ));
        $laundryApprovalDate = $laundryApproval['signed_at']
            ? e($laundryApproval['signed_at']->format('F j, Y'))
            : '';
        $laundryApproverSignature = $this->signatureImage($laundryApproval['snapshot'], 110, 20);

        $laundryBorrowerSignature = $this->signatureImage(
            $version?->borrowerSignature,
            110,
            20
        );

        $laundryBorrowerDesignationValue = trim((string) ($borrower?->designation ?? ''));
        if ($laundryBorrowerDesignationValue === ''
            || strcasecmp($laundryBorrowerDesignationValue, AccessClassification::BorrowerOnly->label()) === 0
            || $laundryBorrowerDesignationValue === $borrower?->access_classification?->label()) {
            $laundryBorrowerDesignationValue = '';
        }
        $laundryBorrowerDesignation = e($laundryBorrowerDesignationValue);


        /*
         * =========================================================
         * LAUNDRY ITEMS ONLY
         * =========================================================
         */

        $laundryLines = $custody->lines->filter(
            fn ($line) =>
                (bool) $line->requestItem?->inventoryItem?->laundry_required
                && (float) $line->quantity_to_receive > 0
        );


        /*
         * The official physical form uses one large uninterrupted
         * writing area instead of one bordered row per item.
         */

        $quantityContent = '';
        $unitContent = '';
        $descriptionContent = '';

        foreach ($laundryLines as $line) {
            $quantity = (int) round(
                (float) $line->quantity_to_receive
            );

            $unit = e(
                (string) $line->requestItem?->unit_snapshot
            );

            $description = e(
                (string) $line->requestItem?->description_snapshot
            );

            $quantityContent .=
                '<div style="
                    height:17pt;
                    line-height:17pt;
                    white-space:nowrap;
                ">'
                .e((string) $quantity)
                .'</div>';

            $unitContent .=
                '<div style="
                    height:17pt;
                    line-height:17pt;
                    white-space:nowrap;
                ">'
                .$unit
                .'</div>';

            $descriptionContent .=
                '<div style="
                    min-height:17pt;
                    line-height:17pt;
                ">'
                .$description
                .'</div>';
        }

        if ($laundryLines->isEmpty()) {
            $quantityContent = '&nbsp;';
            $unitContent = '&nbsp;';
            $descriptionContent = '&nbsp;';
        } else {
            // Keep the official uninterrupted writing area while formally closing
            // the approved linen list immediately beneath the final description.
            $quantityContent .= '<div style="height:17pt;line-height:17pt;">&nbsp;</div>';
            $unitContent .= '<div style="height:17pt;line-height:17pt;">&nbsp;</div>';
            $descriptionContent .=
                '<div style="min-height:17pt;line-height:17pt;text-align:center;font-weight:bold;letter-spacing:.3px;">'
                .self::NOTHING_FOLLOWS_MARKER
                .'</div>';
        }


        $body = <<<HTML
<section style="
    width:100%;
    box-sizing:border-box;

    font-family:Arial, Helvetica, sans-serif;
    font-size:8pt;
    line-height:1.08;

    color:#111;
">


    <!-- ======================================================
         INSTITUTIONAL HEADER
         ====================================================== -->

    <table style="
        width:94%;
        margin:0 auto;

        border-collapse:collapse;
        border-bottom:1px solid #222;
    ">

        <tr>

            <td style="
                width:57px;

                padding:0 6px 4px 0;

                vertical-align:middle;
            ">
                {$logo}
            </td>


            <td style="
                vertical-align:middle;

                padding-bottom:4px;
            ">

                <div style="
                    font-size:6.8pt;
                    line-height:1.02;
                ">
                    Republic of the Philippines
                </div>

                <div style="
                    margin-top:1pt;

                    font-size:8.2pt;
                    font-weight:bold;
                    line-height:1.02;
                ">
                    CAMARINES SUR POLYTECHNIC COLLEGES
                </div>

                <div style="
                    margin-top:1pt;

                    font-size:6.8pt;
                    line-height:1.02;
                ">
                    Nabua, Camarines Sur
                </div>

            </td>


            <td style="
                width:112px;

                padding-bottom:4px;

                vertical-align:bottom;

                text-align:right;

                font-size:6.5pt;
                font-weight:bold;
            ">
                {$formCode}
            </td>

        </tr>

    </table>



    <!-- ======================================================
         TITLE
         ====================================================== -->

    <div style="
        width:94%;

        margin:8pt auto 11pt;

        text-align:center;

        font-size:9.3pt;
        font-weight:bold;
        line-height:1;
    ">
        {$documentTitle}
    </div>



    <!-- ======================================================
         REQUESTING OFFICE / REQUEST NUMBER

         Separate cells are used so labels and values do not
         visually collide.
         ====================================================== -->

    <table style="
        width:94%;

        margin:0 auto 9pt;

        border-collapse:collapse;

        font-size:7.8pt;
        line-height:1;
    ">

        <colgroup>
            <col style="width:12.5%;">
            <col style="width:49.5%;">
            <col style="width:14%;">
            <col style="width:24%;">
        </colgroup>


        <tr>

            <td colspan="2" style="
                padding:0;
                vertical-align:bottom;
            ">
                <table style="
                    width:100%;
                    border:0;
                    border-collapse:collapse;
                    margin:0;
                    padding:0;
                    table-layout:auto;
                ">
                    <tr>
                        <td style="
                            width:1%;
                            border:0;
                            padding:0 2pt 2pt 0;
                            vertical-align:bottom;
                            font-weight:bold;
                            white-space:nowrap;
                        ">{$requestingOfficeLabel}</td>

                        <td style="
                            border:0;
                            border-bottom:1px solid #111;
                            padding:0 3pt 2pt;
                            vertical-align:bottom;
                            text-align:center;
                            white-space:nowrap;
                        ">{$requestingOffice}</td>
                    </tr>
                </table>
            </td>


            <td style="
                padding-left:15pt;
                padding-right:6pt;

                vertical-align:bottom;

                text-align:right;

                font-weight:bold;
                white-space:nowrap;
            ">
                {$requestNoLabel}
            </td>


            <td style="
                padding:0 4pt 2pt;

                vertical-align:bottom;

                border-bottom:1px solid #111;

                text-align:center;

                white-space:nowrap;
            ">
                {$requestNumber}
            </td>

        </tr>

    </table>



    <!-- ======================================================
         MAIN LAUNDRY TABLE

         Proportions patterned after the scanned CSPC form.

           QTY              11%
           UNIT              9%
           DESCRIPTION      41%
           DATE REQUESTED   19%
           DATE COMPLETED   20%

         ====================================================== -->

    <table style="
        width:94%;

        margin:0 auto;

        border-collapse:collapse;
        table-layout:fixed;

        font-size:8pt;
        line-height:1.05;
    ">

        <colgroup>
            <col style="width:11%;">
            <col style="width:9%;">
            <col style="width:41%;">
            <col style="width:19%;">
            <col style="width:20%;">
        </colgroup>


        <thead>

            <tr style="height:28pt;">


                <th style="
                    width:11%;

                    border:1px solid #222;

                    padding:2pt 1pt;

                    text-align:center;
                    vertical-align:middle;

                    font-weight:bold;
                ">
                    {$qtyLabel}
                </th>


                <th style="
                    width:9%;

                    border:1px solid #222;

                    padding:2pt 1pt;

                    text-align:center;
                    vertical-align:middle;

                    font-weight:bold;
                ">
                    {$unitLabel}
                </th>


                <th style="
                    width:41%;

                    border:1px solid #222;

                    padding:2pt;

                    text-align:center;
                    vertical-align:middle;

                    font-weight:bold;
                ">
                    {$descriptionLabel}
                </th>


                <th style="
                    width:19%;

                    border:1px solid #222;

                    padding:2pt 1pt;

                    text-align:center;
                    vertical-align:middle;

                    font-size:7.6pt;
                    font-weight:bold;

                    white-space:nowrap;
                ">
                    {$dateRequestedLabel}
                </th>


                <th style="
                    width:20%;

                    border:1px solid #222;

                    padding:2pt 1pt;

                    text-align:center;
                    vertical-align:middle;

                    font-size:7.6pt;
                    font-weight:bold;

                    white-space:nowrap;
                ">
                    {$dateCompletedLabel}
                </th>


            </tr>

        </thead>



        <tbody>

            <tr>


                <td style="
                    width:11%;

                    height:160pt;

                    border:1px solid #222;

                    padding:7pt 2pt;

                    text-align:center;
                    vertical-align:top;
                ">
                    {$quantityContent}
                </td>


                <td style="
                    width:9%;

                    height:160pt;

                    border:1px solid #222;

                    padding:7pt 2pt;

                    text-align:center;
                    vertical-align:top;
                ">
                    {$unitContent}
                </td>


                <td style="
                    width:41%;

                    height:160pt;

                    border:1px solid #222;

                    padding:7pt 7pt;

                    text-align:left;
                    vertical-align:top;
                ">
                    {$descriptionContent}
                </td>


                <!-- DATE REQUESTED:
                     borrower request/submission date from the approved version -->

                <td style="
                    width:19%;

                    height:160pt;

                    border:1px solid #222;

                    padding:7pt 4pt;

                    text-align:center;
                    vertical-align:top;
                ">{$laundryRequestDate}</td>


                <!-- DATE COMPLETED:
                     remains blank for physical completion -->

                <td style="
                    width:20%;

                    height:160pt;

                    border:1px solid #222;

                    padding:7pt 4pt;

                    vertical-align:top;
                "></td>


            </tr>

        </tbody>

    </table>



    <!-- ======================================================
         SIGNATURE / CONTROL MATRIX

         Larger Approved By area for the official name.
         ====================================================== -->

    <table style="
        width:94%;

        margin:28pt auto 0;

        border-collapse:collapse;
        table-layout:fixed;

        font-size:7pt;
        line-height:1.05;
    ">


        <colgroup>
            <col style="width:15%;">
            <col style="width:15%;">
            <col style="width:38%;">
            <col style="width:16%;">
            <col style="width:16%;">
        </colgroup>



        <!-- RESPONSIBILITY HEADINGS -->

        <tr style="height:17pt;">


            <td style="
                border:1px solid #222;

                padding:2pt;
            "></td>


            <td style="
                border:1px solid #222;

                padding:2pt 3pt;

                text-align:center;
                vertical-align:middle;
            ">
                {$requestedByLabel}
            </td>


            <td style="
                border:1px solid #222;

                padding:2pt 3pt;

                text-align:center;
                vertical-align:middle;
            ">
                {$approvedByLabel}
            </td>


            <td style="
                border:1px solid #222;

                padding:2pt 3pt;

                text-align:center;
                vertical-align:middle;
            ">
                {$issuedByLabel}
            </td>


            <td style="
                border:1px solid #222;

                padding:2pt 3pt;

                text-align:center;
                vertical-align:middle;
            ">
                {$receivedByLabel}
            </td>


        </tr>



        <!-- SIGNATURE -->

        <tr style="height:20pt;">


            <td style="
                border:1px solid #222;

                padding:2pt 4pt;

                vertical-align:middle;
            ">
                {$signatureLabel}
            </td>


            <!-- Requested by: borrower request certification E-signature -->
            <td style="border:1px solid #222;padding:1pt;vertical-align:middle;">{$laundryBorrowerSignature}</td>

            <!-- Approved By: SPMU Head approval E-signature -->
            <td style="border:1px solid #222;padding:1pt;vertical-align:middle;">{$laundryApproverSignature}</td>

            <!-- Issued by: handwritten/wet signature of Laundry Personnel at pickup -->
            <td style="border:1px solid #222;"></td>

            <!-- Received by: handwritten/wet signature of Laundry Personnel at return -->
            <td style="border:1px solid #222;"></td>


        </tr>



        <!-- PRINTED NAME -->

        <tr style="height:22pt;">


            <td style="
                border:1px solid #222;

                padding:2pt 4pt;

                vertical-align:middle;
            ">
                {$printedNameLabel}
            </td>


            <td style="
                border:1px solid #222;

                padding:2pt 4pt;

                text-align:center;
                vertical-align:middle;

                font-size:6.8pt;
                font-weight:normal;

                line-height:1.05;
            ">
                {$borrowerName}
            </td>


            <td style="
                border:1px solid #222;
                padding:1pt 0;
                text-align:center;
                vertical-align:middle;
                font-family:Helvetica, Arial, sans-serif;
                font-size:6.2pt;
                font-weight:bold;
                line-height:1;
                letter-spacing:0;
                white-space:nowrap;
            ">{$laundryApproverName}</td>


            <!-- Laundry Personnel writes/prints their name physically. -->
            <td style="border:1px solid #222;"></td>

            <!-- Laundry Personnel writes/prints their name physically. -->
            <td style="border:1px solid #222;"></td>


        </tr>



        <!-- DESIGNATION -->

        <tr style="height:20pt;">


            <td style="
                border:1px solid #222;

                padding:2pt 4pt;

                vertical-align:middle;
            ">
                {$designationLabel}
            </td>


            <!-- Requested By designation from the borrower's actual profile. -->
            <td style="
                border:1px solid #222;
                padding:1pt 2pt;
                text-align:center;
                vertical-align:middle;
                font-size:6.5pt;
                line-height:1.05;
            ">{$laundryBorrowerDesignation}</td>


            <td style="
                border:1px solid #222;
                padding:1pt 0;
                text-align:center;
                vertical-align:middle;
                font-family:Helvetica, Arial, sans-serif;
                font-size:6.2pt;
                font-weight:normal;
                line-height:1;
                letter-spacing:0;
                white-space:nowrap;
            ">{$laundryApproverDesignation}</td>


            <td style="border:1px solid #222;"></td>

            <td style="border:1px solid #222;"></td>


        </tr>



        <!-- DATE -->

        <tr style="height:19pt;">


            <td style="
                border:1px solid #222;

                padding:2pt 4pt;

                vertical-align:middle;
            ">
                {$dateRowLabel}
            </td>


            <td style="border:1px solid #222;text-align:center;vertical-align:middle;">{$laundryRequestDate}</td>

            <td style="border:1px solid #222;text-align:center;vertical-align:middle;">{$laundryApprovalDate}</td>

            <!-- Issued by date is handwritten by Laundry Personnel at issuance. -->
            <td style="border:1px solid #222;"></td>

            <!-- Received by date is handwritten by Laundry Personnel at return. -->
            <td style="border:1px solid #222;"></td>


        </tr>


    </table>



    <!-- ======================================================
         DOCUMENT CONTROL FOOTER

         IMPORTANT:
         width is EXACTLY 94%, same as:
           - request information
           - main table
           - signature/control table

         This makes the horizontal rule align exactly with both
         left and right edges of the form tables.
         ====================================================== -->

    <table style="
        width:94%;

        margin:31pt auto 0;

        border-collapse:collapse;
        border-top:1px solid #222;

        font-size:6.2pt;
        line-height:1;
    ">


        <tr>


            <td style="
                width:33%;

                padding-top:5pt;

                text-align:left;

                font-weight:bold;
            ">
                Effective Date: {$footerEffectivity}
            </td>


            <td style="
                width:34%;

                padding-top:5pt;

                text-align:center;

                font-weight:bold;
            ">
                {$footerRevision}
            </td>


            <td style="
                width:33%;

                padding-top:5pt;

                text-align:right;

                font-weight:bold;
            ">
                Page {$pageNumber} of {$pageCount}
            </td>


        </tr>


    </table>


    <!--
        Intentional large blank area below this point.

        This mirrors the physical CSPC-F-SPMU-62 form instead
        of forcing the document-control footer to the absolute
        bottom edge of the A4 page.
    -->


</section>
HTML;


        return $documentShell
            ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>'
            : $body;
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

    public function billingStatement(BillingStatement $billing): GeneratedDocument
    {
        $billing->loadMissing([
            'borrower',
            'responsibleSpmuUser',
            'lines.incident.custody.request',
            'lines.penalty.incident.custody.request',
            'lines.penalty.custody.request',
        ]);
        if ($customTemplate = $this->activeUploadedTemplate('BILLING_STATEMENT')) {
            return $this->saveRenderedTemplate(
                $customTemplate,
                'BILLING_STATEMENT',
                $this->templateRenderer->render($customTemplate, $this->billingStatementRenderData($billing)),
                null,
                $billing::class,
                $billing->id,
                'FINAL',
                $billing->billing_no.'.pdf',
            );
        }
        $lines = [
            'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU',
            'BILLING STATEMENT - PENALTIES AND PROPERTY CHARGES ONLY',
            'Billing No.: '.$billing->billing_no,
            'Borrower: '.$billing->borrower->full_name,
            'Issued: '.$billing->issued_at->format('F j, Y'),
            '',
        ];
        foreach ($billing->lines as $line) {
            $lines[] = sprintf('%s | %s | PHP %s', $line->line_type, $line->description, number_format((float) $line->amount, 2));
        }
        $lines[] = '';
        $lines[] = 'TOTAL: PHP '.number_format((float) $billing->total_amount, 2);
        $lines[] = 'Payment is processed externally through Accounting/Cashier. Submit Official Receipt evidence to SPMU for verification.';

        /*
         * A Billing Statement is a printed, handwritten-signed instrument that
         * the borrower carries to the CSPC Cashier, so this is a named wet
         * signature block rather than an embedded E-signature. Previously the
         * statement carried no signature area at all.
         */
        $lines[] = '';
        $lines[] = 'ISSUED BY (Supply and Property Management Unit):';
        $lines[] = '';
        $lines[] = '_________________________________________';
        $lines[] = strtoupper((string) ($billing->responsibleSpmuUser?->full_name ?: 'Authorized SPMU Signatory'));
        $lines[] = (string) ($billing->responsibleSpmuUser?->designation ?: 'Supply and Property Management Unit');
        $lines[] = 'Signature over Printed Name / Date';

        return $this->save('BILLING_STATEMENT', $lines, null, $billing::class, $billing->id, 'FINAL', $billing->billing_no.'.pdf');
    }

    public function rslddp(Incident $incident): GeneratedDocument
    {
        $incident->loadMissing(['borrower', 'custody.request.currentVersion', 'lines.custodyLine.requestItem', 'reportedBy']);
        if ($customTemplate = $this->activeUploadedTemplate('RSLDDP')) {
            $document = $this->saveRenderedTemplate(
                $customTemplate,
                'RSLDDP',
                $this->templateRenderer->render($customTemplate, $this->rslddpRenderData($incident)),
                $incident->custody->request->currentVersion,
                $incident::class,
                $incident->id,
                'FINAL',
                $incident->incident_no.'-RSLDDP.pdf',
            );
            $incident->update(['rslddp_reference' => $document->document_no]);

            return $document;
        }
        $lines = [
            'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU',
            'OFFICIAL RSLDDP REPORT',
            'Controlled output enabled after client approval of the configured template status.',
            'Incident No.: '.$incident->incident_no,
            'Borrower: '.$incident->borrower->full_name,
            'Custody No.: '.$incident->custody->custody_no,
            'Incident type: '.$incident->incident_type,
            'Reported: '.$incident->reported_at->format('F j, Y g:i A'),
            'Police blotter reference: '.($incident->police_blotter_reference ?: 'Not applicable'),
            'Remarks: '.($incident->remarks ?: 'None'),
            '',
            'AFFECTED PROPERTY',
        ];
        foreach ($incident->lines as $line) {
            $lines[] = sprintf('Custody line %s | Quantity: %s | Condition: %s', $line->custody_line_id, $line->quantity + 0, $line->observed_condition);
        }

        /*
         * The RSLDDP is a printed controlled report. It is reported by the SPMU
         * Action Officer who inspected the property and noted by the SPMU Head.
         * Both are handwritten signatures on the printed report; previously the
         * report carried no signature area at all.
         */
        $incident->loadMissing('reportedBy');

        $lines[] = '';
        $lines[] = 'REPORTED BY (SPMU Action Officer):';
        $lines[] = '_________________________________________';
        $lines[] = strtoupper((string) ($incident->reportedBy?->full_name ?: 'SPMU Action Officer'));
        $lines[] = 'Signature over Printed Name / Date';
        $lines[] = '';
        $lines[] = 'NOTED BY (Head, Supply and Property Management Unit):';
        $lines[] = '_________________________________________';
        $lines[] = 'Signature over Printed Name / Date';

        $document = $this->save(
            'RSLDDP',
            $lines,
            $incident->custody->request->currentVersion,
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
        $template = $this->activeTemplate($type);

        return $template?->source_mode === 'OFFICIAL_LAYOUT' ? $template : null;
    }

    /** @return array<string,mixed> */
    private function borrowerSlipRenderData(CustodyTransaction $custody): array
    {
        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $return = $this->returnInspectionData($custody);
        $approval = $this->approvalSignatory($version);
        $issuance = $this->issuanceSignatory($custody);
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
            'borrowed_by_signature' => $this->templateSignatureAsset($version?->borrowerSignature),
            'borrowed_by_printed_name' => (string) ($borrower?->full_name ?? ''),
            'borrowed_by_designation' => $borrowerDesignation,
            'borrowed_by_date' => $version?->signed_at?->format('F j, Y') ?: '',
            'approved_by_signature' => $this->templateSignatureAsset($approval['snapshot'] ?? null),
            'approved_by_printed_name' => (string) ($approval['name'] ?? ''),
            'approved_by_designation' => (string) ($approval['designation'] ?? ''),
            'approved_by_date' => $approval['signed_at']?->format('F j, Y') ?: '',
            'issued_by_signature' => $this->templateSignatureAsset($issuance['snapshot'] ?? null),
            'issued_by_printed_name' => (string) ($issuance['name'] ?? ''),
            'issued_by_designation' => (string) ($issuance['designation'] ?? ''),
            'issued_by_date' => $issuance['signed_at']?->format('F j, Y') ?: '',
            'return_received_by_signature' => $this->templateSignatureAsset($return['signature'] ?? null),
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
    private function laundryFormRenderData(CustodyTransaction $custody): array
    {
        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $job = $custody->laundryJob;
        $approval = $this->approvalSignatory($version);
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
            'requested_by_signature' => $this->templateSignatureAsset($version?->borrowerSignature),
            'requested_by_printed_name' => (string) ($borrower?->full_name ?? ''),
            'requested_by_designation' => $this->templatePrintedDesignation($borrower),
            'requested_by_date' => $dateRequested,
            'approved_by_signature' => $this->templateSignatureAsset($approval['snapshot'] ?? null),
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
    private function gatePassRenderData(CustodyTransaction $custody): array
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
            'requested_by_signature' => $this->templateSignatureAsset($version?->borrowerSignature),
            'requested_by_printed_name' => (string) ($borrower?->full_name ?? ''),
            'requested_by_designation' => $this->templatePrintedDesignation($borrower),
            'requested_by_date' => $version?->signed_at?->format('F j, Y') ?: '',
            'verified_by_signature' => $this->templateSignatureAsset($gatePass?->preparedVerifierSignature),
            'verified_by_printed_name' => (string) ($gatePass?->preparedVerifier?->full_name ?? ''),
            'verified_by_designation' => $this->templatePrintedDesignation($gatePass?->preparedVerifier),
            'verified_by_date' => $gatePass?->prepared_verified_at?->format('F j, Y') ?: '',
            'approved_by_signature' => $this->templateSignatureAsset($gatePass?->approverSignature),
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
    private function billingStatementRenderData(BillingStatement $billing): array
    {
        $incidents = $billing->lines
            ->map(fn ($line) => $line->incident ?: $line->penalty?->incident)
            ->filter();
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
            // A responsible issuer is stored; a billing-signature snapshot is
            // not. Signature therefore remains blank in production.
            'issuer_signature' => null,
            'issuer_printed_name' => (string) ($billing->responsibleSpmuUser?->full_name ?? ''),
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
        if (! in_array($type, ['BORROWER_SLIP', 'LAUNDRY_FORM', 'GATE_PASS', 'BILLING_STATEMENT', 'RSLDDP'], true)) {
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
    private function approvalSignatory(?RequestVersion $version): array
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
            'snapshot' => $step?->signatureSnapshot,
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
    private function issuanceSignatory(CustodyTransaction $custody): array
    {
        $officer = $custody->releasedBy;
        $designation = trim((string) $officer?->designation);

        return [
            'name' => $officer?->full_name ?: '',
            'designation' => $designation !== '' ? $designation : 'SPMU Action Officer',
            'snapshot' => $custody->releaseSignature,
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
    private function returnInspectionData(CustodyTransaction $custody): array
    {
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

        $findings = $return?->lines
            ?->groupBy('custody_line_id')
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
            ->all() ?? [];

        return [
            'exists' => $return !== null,
            'signed_at' => $return?->received_at,
            'findings' => $findings,
            'received_by_name' => (string) ($return?->receivedBy?->full_name ?? ''),
            'received_by_designation' => (string) ($return?->receivedBy?->designation ?? ''),
            'signature' => $return?->inspectionSignature,
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
