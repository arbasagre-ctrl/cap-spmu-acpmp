<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Read-only analysis of an approved document source.
 *
 * An official layout is either deterministically usable by the production
 * renderer or it is rejected for automatic preparation. This service
 * deliberately never turns an uncertain label into an administrator task.
 */
class DocumentTemplateLayoutService
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_ARCHIVE_ENTRIES = 2000;
    private const MAX_ARCHIVE_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;
    private const MAX_SCHEMA_NODES = 10000;
    private const SCHEMA_WARNING_SEVERITIES = ['INFO', 'WARNING', 'BLOCKING'];

    public function __construct(
        private GenericPdfLayoutReader $pdfReader,
        private DocumentTemplateSemanticInterpreter $interpreter,
    ) {}

    /** @var array<string, array<string, array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string}>> */
    private const FIELD_DEFINITIONS = [
        'BORROWER_SLIP' => [
            // The official form's top data is filled only from the existing
            // request/custody profile. Signature images are supported only
            // where an immutable workflow signature snapshot exists; all
            // other handwritten signature cells remain outside this schema.
            'document_date' => ['label' => 'Document Date', 'aliases' => ['date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date', 'placement' => 'header'],
            'employee_checkbox' => ['label' => 'Employee', 'aliases' => ['employee'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'checkbox'],
            'others_checkbox' => ['label' => 'Others', 'aliases' => ['others'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'checkbox'],
            'other_classification' => ['label' => 'Others Classification', 'aliases' => ['others classification'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'items.qty' => ['label' => 'Quantity', 'aliases' => ['qty', 'quantity'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'items.unit' => ['label' => 'Unit', 'aliases' => ['unit'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'items.description' => ['label' => 'Article / Description', 'aliases' => ['article / description', 'article description', 'description'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'purpose' => ['label' => 'Purpose', 'aliases' => ['purpose', 'will be used for', 'used for'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'expected_return_date' => ['label' => 'Expected Return Date', 'aliases' => ['expected date of return', 'expected return date', 'date of return', 'returned on'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'date_released' => ['label' => 'Date Released', 'aliases' => ['date released', 'release date'], 'required' => false, 'manual' => false, 'table' => 'release_return'],
            'release_time' => ['label' => 'Release Time', 'aliases' => ['release time', 'time released'], 'required' => false, 'manual' => false, 'table' => 'release_return'],
            'date_returned' => ['label' => 'Date Returned', 'aliases' => ['date returned', 'return date'], 'required' => false, 'manual' => false, 'table' => 'release_return'],
            'remarks' => ['label' => 'Remarks', 'aliases' => ['remarks', 'return remarks'], 'required' => false, 'manual' => false, 'table' => 'release_return'],
            'borrowed_by_signature' => ['label' => 'Borrowed By Signature', 'aliases' => ['borrowed by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['borrowed by']]],
            'borrowed_by_printed_name' => ['label' => 'Borrowed By Printed Name', 'aliases' => ['borrowed by'], 'required' => false, 'manual' => false, 'table' => null],
            'borrowed_by_designation' => ['label' => 'Borrowed By Designation', 'aliases' => ['borrowed by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'borrowed_by_date' => ['label' => 'Borrowed By Date', 'aliases' => ['borrowed by date'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_signature' => ['label' => 'Approved By Signature', 'aliases' => ['approved by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['approved by', 'approved']]],
            'approved_by_printed_name' => ['label' => 'Approved By Printed Name', 'aliases' => ['approved by'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_designation' => ['label' => 'Approved By Designation', 'aliases' => ['approved by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_date' => ['label' => 'Approved By Date', 'aliases' => ['approved by date'], 'required' => false, 'manual' => false, 'table' => null],
            'issued_by_signature' => ['label' => 'Issued By Signature', 'aliases' => ['issued by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['issued by']]],
            'issued_by_printed_name' => ['label' => 'Issued By Printed Name', 'aliases' => ['issued by'], 'required' => false, 'manual' => false, 'table' => null],
            'issued_by_designation' => ['label' => 'Issued By Designation', 'aliases' => ['issued by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'issued_by_date' => ['label' => 'Issued By Date', 'aliases' => ['issued by date'], 'required' => false, 'manual' => false, 'table' => null],
            'return_received_by_signature' => ['label' => 'Return Received By Signature', 'aliases' => ['return received by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['received by'], 'after_headers' => ['returned by'], 'before_headers' => ['verified by']]],
            'return_received_by_printed_name' => ['label' => 'Return Received By Printed Name', 'aliases' => ['received by'], 'required' => false, 'manual' => false, 'table' => null],
            'return_received_by_designation' => ['label' => 'Return Received By Designation', 'aliases' => ['return received by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'return_received_by_date' => ['label' => 'Return Received By Date', 'aliases' => ['return received by date'], 'required' => false, 'manual' => false, 'table' => null],
            'borrowed_by' => ['label' => 'Borrowed By', 'aliases' => ['borrowed by', 'borrower signature'], 'required' => false, 'manual' => true, 'table' => null],
            'approved_by' => ['label' => 'Approved By', 'aliases' => ['approved by', 'approved'], 'required' => false, 'manual' => false, 'table' => null],
            'issued_by' => ['label' => 'Issued By', 'aliases' => ['issued by'], 'required' => false, 'manual' => true, 'table' => null],
            'received_by' => ['label' => 'Received By', 'aliases' => ['received by'], 'required' => false, 'manual' => true, 'table' => null],
            'returned_by' => ['label' => 'Returned By', 'aliases' => ['returned by'], 'required' => false, 'manual' => true, 'table' => null],
            'return_received_by' => ['label' => 'Return Received By', 'aliases' => ['return received by'], 'required' => false, 'manual' => true, 'table' => null],
            'verified_by' => ['label' => 'Verified By', 'aliases' => ['verified by'], 'required' => false, 'manual' => true, 'table' => null],
        ],
        'LAUNDRY_FORM' => [
            'request_no' => ['label' => 'Request Number', 'aliases' => ['request no', 'request number', 'request reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'custody_no' => ['label' => 'Custody Number', 'aliases' => ['custody no', 'custody number', 'custody reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'borrower_name' => ['label' => 'Borrower', 'aliases' => ['borrower', 'requested by', 'requester'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'requesting_office' => ['label' => 'Requesting Office', 'aliases' => ['requesting office', 'office', 'division', 'department'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'date_requested' => ['label' => 'Date Requested', 'aliases' => ['date requested', 'request date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'date_released' => ['label' => 'Date Released', 'aliases' => ['date released', 'release date', 'date issued'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'items.qty' => ['label' => 'Quantity', 'aliases' => ['qty', 'quantity', 'no of units', 'number'], 'required' => true, 'manual' => false, 'table' => 'laundry_items'],
            'items.unit' => ['label' => 'Unit', 'aliases' => ['unit', 'unit of measure'], 'required' => true, 'manual' => false, 'table' => 'laundry_items'],
            'items.description' => ['label' => 'Description', 'aliases' => ['description', 'article', 'article / description', 'linen description', 'particulars'], 'required' => true, 'manual' => false, 'table' => 'laundry_items'],
            // Physical Laundry dates are item-table semantics whenever an
            // uploaded layout presents them as columns. The same authoritative
            // job timestamp may legitimately appear in each affected row.
            'items.date_requested' => ['label' => 'Date Requested', 'aliases' => ['date requested', 'request date'], 'required' => false, 'manual' => false, 'table' => 'laundry_items', 'field_type' => 'date'],
            'items.date_received' => ['label' => 'Date Received', 'aliases' => ['date received', 'received date', 'date of receipt', 'physical received date'], 'required' => false, 'manual' => false, 'table' => 'laundry_items', 'field_type' => 'date'],
            'items.date_completed' => ['label' => 'Date Completed', 'aliases' => ['date completed', 'date returned', 'completed date', 'returned date'], 'required' => false, 'manual' => false, 'table' => 'laundry_items', 'field_type' => 'date'],
            'items.received_quantity' => ['label' => 'Received Quantity', 'aliases' => ['received qty', 'quantity received', 'qty received'], 'required' => false, 'manual' => false, 'table' => 'laundry_items'],
            'items.completed_quantity' => ['label' => 'Completed Quantity', 'aliases' => ['completed qty', 'quantity completed', 'qty completed'], 'required' => false, 'manual' => false, 'table' => 'laundry_items'],
            'items.affected_quantity' => ['label' => 'Affected Quantity', 'aliases' => ['affected qty', 'quantity affected', 'qty affected'], 'required' => false, 'manual' => false, 'table' => 'laundry_items'],
            'items.issue_type' => ['label' => 'Issue Type', 'aliases' => ['issue type', 'finding', 'condition'], 'required' => false, 'manual' => false, 'table' => 'laundry_items'],
            'items.remarks' => ['label' => 'Remarks', 'aliases' => ['remarks', 'notes', 'findings'], 'required' => false, 'manual' => false, 'table' => 'laundry_items'],
            'requested_by_signature' => ['label' => 'Requested By Signature', 'aliases' => ['requested by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['requested by', 'requester']]],
            'requested_by_printed_name' => ['label' => 'Requested By Printed Name', 'aliases' => ['requested by'], 'required' => false, 'manual' => false, 'table' => null],
            'requested_by_designation' => ['label' => 'Requested By Designation', 'aliases' => ['requested by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'requested_by_date' => ['label' => 'Requested By Date', 'aliases' => ['requested by date'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_signature' => ['label' => 'Approved By Signature', 'aliases' => ['approved by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['approved by', 'approved']]],
            'approved_by_printed_name' => ['label' => 'Approved By Printed Name', 'aliases' => ['approved by'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_designation' => ['label' => 'Approved By Designation', 'aliases' => ['approved by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_date' => ['label' => 'Approved By Date', 'aliases' => ['approved by date'], 'required' => false, 'manual' => false, 'table' => null],
            // The worker is an offline physical actor. Their recorded name
            // can populate a Printed Name row, but no system e-signature or
            // designation is invented.
            'received_by_signature' => ['label' => 'Received By Signature', 'aliases' => ['received by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['received by', 'received from laundry']]],
            'received_by_printed_name' => ['label' => 'Received By Printed Name', 'aliases' => ['received by'], 'required' => false, 'manual' => false, 'table' => null],
            'received_by_designation' => ['label' => 'Received By Designation', 'aliases' => ['received by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'received_by_date' => ['label' => 'Received By Date', 'aliases' => ['received by date'], 'required' => false, 'manual' => false, 'table' => null],
            'verified_by_signature' => ['label' => 'Verified By Signature', 'aliases' => ['verified by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['verified by', 'form verified by']]],
            'verified_by_printed_name' => ['label' => 'Verified By Printed Name', 'aliases' => ['verified by'], 'required' => false, 'manual' => false, 'table' => null],
            'verified_by_designation' => ['label' => 'Verified By Designation', 'aliases' => ['verified by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'verified_by_date' => ['label' => 'Verified By Date', 'aliases' => ['verified by date'], 'required' => false, 'manual' => false, 'table' => null],
        ],
        'GATE_PASS' => [
            'gate_pass_no' => ['label' => 'Gate Pass Number', 'aliases' => ['gp no', 'gate pass no', 'gate pass number', 'gate pass reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'request_no' => ['label' => 'Request Number', 'aliases' => ['request no', 'request number', 'request reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'custody_no' => ['label' => 'Custody Number', 'aliases' => ['custody no', 'custody number', 'custody reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'document_date' => ['label' => 'Document Date', 'aliases' => ['date', 'date issued', 'release date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date', 'placement' => 'header'],
            'borrower_name' => ['label' => 'Bearer', 'aliases' => ['bearer', 'accountable person', 'borrower'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'requesting_office' => ['label' => 'Office / College / Unit', 'aliases' => ['office', 'division', 'department', 'requesting office'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'purpose' => ['label' => 'Purpose', 'aliases' => ['purpose', 'purpose of movement', 'reason'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'destination' => ['label' => 'Destination', 'aliases' => ['destination', 'place of destination', 'location'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'movement_scope' => ['label' => 'Movement Scope', 'aliases' => ['premises', 'off campus', 'on campus', 'movement type'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'exit_date' => ['label' => 'Exit Date', 'aliases' => ['exit date', 'date released', 'actual release date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'verification_remarks' => ['label' => 'Remarks', 'aliases' => ['remarks', 'verification remarks', 'notes'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'items.qty' => ['label' => 'Quantity', 'aliases' => ['quantity', 'qty', 'no of units', 'number'], 'required' => true, 'manual' => false, 'table' => 'gate_items'],
            'items.unit' => ['label' => 'Unit', 'aliases' => ['unit', 'unit of measure'], 'required' => true, 'manual' => false, 'table' => 'gate_items'],
            'items.description' => ['label' => 'Description', 'aliases' => ['description', 'article', 'article / description', 'item description', 'particulars'], 'required' => true, 'manual' => false, 'table' => 'gate_items'],
            'items.movement_scope' => ['label' => 'Premises', 'aliases' => ['premises', 'location', 'movement type', 'use location'], 'required' => false, 'manual' => false, 'table' => 'gate_items'],
            'requested_by_signature' => ['label' => 'Requested By Signature', 'aliases' => ['requested by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['requested by', 'requester', 'bearer']]],
            'requested_by_printed_name' => ['label' => 'Requested By Printed Name', 'aliases' => ['requested by', 'requester', 'bearer'], 'required' => false, 'manual' => false, 'table' => null],
            'requested_by_designation' => ['label' => 'Requested By Designation', 'aliases' => ['requested by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'requested_by_date' => ['label' => 'Requested By Date', 'aliases' => ['requested by date'], 'required' => false, 'manual' => false, 'table' => null],
            'verified_by_signature' => ['label' => 'Verified By Signature', 'aliases' => ['verified by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['verified by', 'prepared and verified by']]],
            'verified_by_printed_name' => ['label' => 'Verified By Printed Name', 'aliases' => ['verified by'], 'required' => false, 'manual' => false, 'table' => null],
            'verified_by_designation' => ['label' => 'Verified By Designation', 'aliases' => ['verified by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'verified_by_date' => ['label' => 'Verified By Date', 'aliases' => ['verified by date'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_signature' => ['label' => 'Approved By Signature', 'aliases' => ['approved by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['approved by', 'approved']]],
            'approved_by_printed_name' => ['label' => 'Approved By Printed Name', 'aliases' => ['approved by'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_designation' => ['label' => 'Approved By Designation', 'aliases' => ['approved by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_date' => ['label' => 'Approved By Date', 'aliases' => ['approved by date'], 'required' => false, 'manual' => false, 'table' => null],
            'guard_signature' => ['label' => 'Guard Signature', 'aliases' => ['guard signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['guard', 'security guard', 'released by']]],
            'guard_printed_name' => ['label' => 'Guard Printed Name', 'aliases' => ['guard', 'security guard', 'released by'], 'required' => false, 'manual' => false, 'table' => null],
            'guard_designation' => ['label' => 'Guard Designation', 'aliases' => ['guard designation'], 'required' => false, 'manual' => false, 'table' => null],
            'guard_date' => ['label' => 'Guard Date', 'aliases' => ['guard date'], 'required' => false, 'manual' => false, 'table' => null],
        ],
        'BILLING_STATEMENT' => [
            'billing_no' => ['label' => 'Billing Statement Number', 'aliases' => ['billing no', 'billing statement no', 'billing statement number', 'statement no'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'borrower_name' => ['label' => 'Borrower', 'aliases' => ['borrower', 'billed to', 'accountable person'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'request_no' => ['label' => 'Request Number', 'aliases' => ['request no', 'request number', 'request reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'custody_no' => ['label' => 'Custody Number', 'aliases' => ['custody no', 'custody number', 'custody reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'incident_no' => ['label' => 'Incident Number', 'aliases' => ['incident no', 'incident number', 'accountability reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'issued_date' => ['label' => 'Issued Date', 'aliases' => ['issued date', 'date issued', 'statement date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'due_date' => ['label' => 'Due Date', 'aliases' => ['due date', 'payment due date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'statement_remarks' => ['label' => 'Remarks', 'aliases' => ['remarks', 'notes', 'details'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'items.description' => ['label' => 'Description', 'aliases' => ['description', 'particulars', 'item description', 'charge description'], 'required' => true, 'manual' => false, 'table' => 'billing_lines'],
            'items.line_type' => ['label' => 'Line Type', 'aliases' => ['line type', 'charge type', 'classification', 'type'], 'required' => false, 'manual' => false, 'table' => 'billing_lines'],
            'items.basis' => ['label' => 'Basis', 'aliases' => ['basis', 'basis of charge', 'basis for charge'], 'required' => false, 'manual' => false, 'table' => 'billing_lines'],
            'items.penalty_type' => ['label' => 'Penalty Type', 'aliases' => ['penalty type', 'fine type', 'loss or damage type'], 'required' => false, 'manual' => false, 'table' => 'billing_lines'],
            'items.incident_no' => ['label' => 'Incident Reference', 'aliases' => ['incident no', 'incident reference', 'reference no'], 'required' => false, 'manual' => false, 'table' => 'billing_lines'],
            'items.amount' => ['label' => 'Amount', 'aliases' => ['amount', 'assessed amount', 'charge amount'], 'required' => true, 'manual' => false, 'table' => 'billing_lines'],
            'total_amount' => ['label' => 'Total Amount', 'aliases' => ['total amount', 'total due', 'total'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            // Billing records a responsible issuer, but no immutable billing
            // signature snapshot. The generic renderer therefore prints the
            // factual name/role/date only and leaves Signature empty.
            'issuer_signature' => ['label' => 'Issuer Signature', 'aliases' => ['issuer signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['issued by', 'issuer', 'prepared by']]],
            'issuer_printed_name' => ['label' => 'Issuer Printed Name', 'aliases' => ['issued by', 'issuer', 'prepared by'], 'required' => false, 'manual' => false, 'table' => null],
            'issuer_designation' => ['label' => 'Issuer Designation', 'aliases' => ['issuer designation'], 'required' => false, 'manual' => false, 'table' => null],
            'issuer_date' => ['label' => 'Issuer Date', 'aliases' => ['issuer date'], 'required' => false, 'manual' => false, 'table' => null],
            'head_signature' => ['label' => 'SPMU Head Authorization Signature', 'aliases' => ['head signature', 'authorized by signature', 'approved by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['authorized by', 'approved by', 'spmu head']]],
            'head_printed_name' => ['label' => 'SPMU Head Printed Name', 'aliases' => ['authorized by', 'approved by', 'spmu head'], 'required' => false, 'manual' => false, 'table' => null],
            'head_designation' => ['label' => 'SPMU Head Designation', 'aliases' => ['head designation', 'designation'], 'required' => false, 'manual' => false, 'table' => null],
            'head_date' => ['label' => 'SPMU Head Authorization Date', 'aliases' => ['head date', 'authorization date', 'approved date'], 'required' => false, 'manual' => false, 'table' => null],
        ],
        'ACCOUNTABILITY_COMPLIANCE_NOTICE' => [
            'incident_no' => ['label' => 'Case Number', 'aliases' => ['case no', 'case number', 'incident no', 'accountability reference'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'decision_date' => ['label' => 'Decision Date', 'aliases' => ['decision date', 'date issued', 'date confirmed'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'borrower_name' => ['label' => 'Borrower', 'aliases' => ['borrower', 'accountable person'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'office_unit' => ['label' => 'Office / College / Unit', 'aliases' => ['office', 'office unit', 'department', 'unit'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'request_no' => ['label' => 'Request Number', 'aliases' => ['request no', 'request number'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'custody_no' => ['label' => 'Custody Number', 'aliases' => ['custody no', 'custody number'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'incident_type' => ['label' => 'Finding / Case Type', 'aliases' => ['finding', 'case type', 'incident type'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'decision_remarks' => ['label' => 'Required Compliance / Head Instruction', 'aliases' => ['required compliance', 'head instruction', 'decision remarks', 'instruction'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'items.description' => ['label' => 'Item', 'aliases' => ['item', 'property', 'description'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'items.qty' => ['label' => 'Quantity', 'aliases' => ['qty', 'quantity'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'items.finding' => ['label' => 'Finding', 'aliases' => ['finding', 'condition'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'items.disposition' => ['label' => 'Required Action', 'aliases' => ['required action', 'disposition', 'action'], 'required' => false, 'manual' => false, 'table' => 'affected_items'],
            'head_signature' => ['label' => 'SPMU Head Signature', 'aliases' => ['head signature', 'confirmed by signature', 'approved by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['confirmed by', 'spmu head', 'approved by']]],
            'head_printed_name' => ['label' => 'SPMU Head Printed Name', 'aliases' => ['confirmed by', 'spmu head', 'approved by'], 'required' => false, 'manual' => false, 'table' => null],
            'head_designation' => ['label' => 'SPMU Head Designation', 'aliases' => ['head designation', 'designation'], 'required' => false, 'manual' => false, 'table' => null],
            'head_date' => ['label' => 'SPMU Head Date', 'aliases' => ['head date', 'date confirmed', 'decision date'], 'required' => false, 'manual' => false, 'table' => null],
        ],
        'ADMINISTRATIVE_SANCTION_NOTICE' => [
            'borrower_name' => ['label' => 'Borrower', 'aliases' => ['borrower', 'accountable person'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'office_unit' => ['label' => 'Office / College / Unit', 'aliases' => ['office', 'office unit', 'department', 'unit'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'request_no' => ['label' => 'Request Number', 'aliases' => ['request no', 'request number'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'custody_no' => ['label' => 'Custody Number', 'aliases' => ['custody no', 'custody number'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'academic_period' => ['label' => 'Academic Period', 'aliases' => ['academic period', 'semester'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'offense_level' => ['label' => 'Offense Level', 'aliases' => ['offense level', 'offense no', 'offense number'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'confirmed_date' => ['label' => 'Date Confirmed', 'aliases' => ['date confirmed', 'confirmed date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'reason_text' => ['label' => 'Confirmed Offense Basis', 'aliases' => ['offense basis', 'finding', 'reason'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'sanction_label' => ['label' => 'Administrative Sanction', 'aliases' => ['sanction', 'administrative sanction'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'effective_from' => ['label' => 'Effective From', 'aliases' => ['effective from', 'start date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'effective_to' => ['label' => 'Effective Until', 'aliases' => ['effective until', 'end date'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'head_remarks' => ['label' => 'Head Remarks', 'aliases' => ['head remarks', 'remarks', 'decision remarks'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'head_signature' => ['label' => 'SPMU Head Signature', 'aliases' => ['head signature', 'confirmed by signature', 'approved by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['confirmed by', 'spmu head', 'approved by']]],
            'head_printed_name' => ['label' => 'SPMU Head Printed Name', 'aliases' => ['confirmed by', 'spmu head', 'approved by'], 'required' => false, 'manual' => false, 'table' => null],
            'head_designation' => ['label' => 'SPMU Head Designation', 'aliases' => ['head designation', 'designation'], 'required' => false, 'manual' => false, 'table' => null],
            'head_date' => ['label' => 'SPMU Head Date', 'aliases' => ['head date', 'date confirmed'], 'required' => false, 'manual' => false, 'table' => null],
        ],
        'RSLDDP' => [
            'rslddp_reference' => ['label' => 'RSLDDP Number', 'aliases' => ['rslddp no', 'rslddp number', 'rslddp reference', 'document no'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'incident_no' => ['label' => 'Incident Number', 'aliases' => ['incident no', 'incident number', 'incident reference'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'custody_no' => ['label' => 'Custody Number', 'aliases' => ['custody no', 'custody number', 'custody reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'request_no' => ['label' => 'Request Number', 'aliases' => ['request no', 'request number', 'request reference'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'police_blotter_reference' => ['label' => 'Blotter Reference', 'aliases' => ['police blotter reference', 'blotter reference', 'blotter no'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'borrower_name' => ['label' => 'Borrower', 'aliases' => ['borrower', 'accountable person', 'responsible person'], 'required' => true, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'incident_type' => ['label' => 'Incident Type', 'aliases' => ['incident type', 'type of incident', 'loss or damage type'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'reported_date' => ['label' => 'Reported Date', 'aliases' => ['reported date', 'date reported', 'date of incident'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'date'],
            'incident_remarks' => ['label' => 'Incident Remarks', 'aliases' => ['remarks', 'incident remarks', 'details', 'narrative'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'appraisal_amount' => ['label' => 'Appraised Amount', 'aliases' => ['appraised amount', 'appraisal amount', 'assessed amount', 'total appraisal'], 'required' => false, 'manual' => false, 'table' => null, 'field_type' => 'text'],
            'items.qty' => ['label' => 'Affected Quantity', 'aliases' => ['quantity', 'qty', 'affected qty', 'no of units'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'items.description' => ['label' => 'Affected Property', 'aliases' => ['affected property', 'property description', 'description', 'article / description', 'particulars'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'items.condition' => ['label' => 'Observed Condition', 'aliases' => ['observed condition', 'condition', 'condition found'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'items.assessed_value' => ['label' => 'Assessed Value', 'aliases' => ['assessed value', 'appraised value', 'amount'], 'required' => false, 'manual' => false, 'table' => 'affected_items'],
            'items.disposition' => ['label' => 'Disposition', 'aliases' => ['disposition', 'recommended disposition', 'action taken'], 'required' => false, 'manual' => false, 'table' => 'affected_items'],
            // Incident reporting has a recorded officer and date, but no
            // incident-signature snapshot. Its signature row remains blank.
            'reported_by_signature' => ['label' => 'Reported By Signature', 'aliases' => ['reported by signature'], 'required' => false, 'manual' => false, 'table' => null, 'signatory' => ['header_aliases' => ['reported by', 'reported and inspected by']]],
            'reported_by_printed_name' => ['label' => 'Reported By Printed Name', 'aliases' => ['reported by'], 'required' => false, 'manual' => false, 'table' => null],
            'reported_by_designation' => ['label' => 'Reported By Designation', 'aliases' => ['reported by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'reported_by_date' => ['label' => 'Reported By Date', 'aliases' => ['reported by date'], 'required' => false, 'manual' => false, 'table' => null],
            'noted_by' => ['label' => 'Noted By', 'aliases' => ['noted by'], 'required' => false, 'manual' => true, 'table' => null],
        ],
    ];

    /** @return array<string,string> */
    public function fields(string $type): array
    {
        return collect(self::FIELD_DEFINITIONS[$type] ?? [])->map(fn (array $field): string => $field['label'])->all();
    }

    /** @return array<string,array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string}> */
    public function fieldDefinitions(string $type): array
    {
        return self::FIELD_DEFINITIONS[$type] ?? [];
    }

    /** @return list<string> */
    public function requiredFields(string $type): array
    {
        return array_keys(array_filter(self::FIELD_DEFINITIONS[$type] ?? [], fn (array $field): bool => $field['required']));
    }

    /** @return array{format:string,review:array<string,mixed>} */
    public function inspectUpload(UploadedFile $upload): array
    {
        if (($upload->getSize() ?? 0) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['template_file' => 'The approved layout must be 10 MB or smaller.']);
        }
        $bytes = (string) file_get_contents($upload->getRealPath());
        return $this->inspectSource(
            $bytes,
            $upload->getClientOriginalName(),
            $upload->getMimeType(),
            $upload->getClientOriginalExtension(),
        );
    }

    /**
     * Re-identify a preserved source before preparation. The stored metadata
     * is informative only: a valid PDF header must remain a PDF even if an
     * earlier draft did not persist its format value correctly.
     *
     * @return array{format:string,review:array<string,mixed>}
     */
    public function inspectStoredSource(string $bytes, ?string $originalName = null, ?string $mimeType = null, ?string $declaredFormat = null, bool $withLayoutAnalysis = false): array
    {
        return $this->inspectSource($bytes, $originalName, $mimeType, $declaredFormat, $withLayoutAnalysis);
    }

    /**
     * Phase 1 stores only the uploaded Office document's structure and
     * diagnostics. Runtime values, generated output, and user data do not
     * belong in this versioned schema.
     *
     * @param array<string,mixed> $review
     * @return array<string,mixed>|null
     */
    public function schemaForStoredSource(string $format, array $review, string $sha256, int $storedFileId): ?array
    {
        if (! in_array($format, ['DOCX', 'XLSX'], true)) {
            return null;
        }

        $schema = $review['dynamic_schema'] ?? null;
        if (! is_array($schema)) {
            throw ValidationException::withMessages([
                'template_file' => 'The uploaded Office layout could not be read into a safe document schema.',
            ]);
        }

        $schema['source'] = [
            'format' => $format,
            'sha256' => $sha256,
            'stored_file_id' => $storedFileId,
        ];
        $this->assertValidDynamicSchema($schema, $format);

        return $schema;
    }

    /** @return array{format:string,review:array<string,mixed>} */
    private function inspectSource(string $bytes, ?string $originalName, ?string $mimeType, ?string $declaredFormat, bool $withLayoutAnalysis = false): array
    {
        $format = $this->detectSourceFormat($bytes, $originalName, $mimeType, $declaredFormat);

        return match ($format) {
            'PDF' => ['format' => 'PDF', 'review' => $this->inspectPdf($bytes, $withLayoutAnalysis)],
            'DOCX' => ['format' => 'DOCX', 'review' => $this->inspectDocx($bytes)],
            'XLSX' => ['format' => 'XLSX', 'review' => $this->inspectXlsx($bytes)],
            default => throw ValidationException::withMessages(['template' => 'The uploaded layout has no supported production format.']),
        };
    }

    private function detectSourceFormat(string $bytes, ?string $originalName, ?string $mimeType, ?string $declaredFormat): ?string
    {
        $mimeType = strtolower(trim((string) $mimeType));
        $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        $declaredFormat = strtoupper(trim((string) $declaredFormat));
        $hasPdfHeader = str_starts_with(ltrim($bytes), '%PDF-');

        // Content is authoritative for PDFs. MIME and filename are retained
        // as corroborating signals and to give malformed PDF uploads a clear
        // PDF validation error instead of misclassifying them as unsupported.
        if ($hasPdfHeader || str_contains($mimeType, 'pdf') || $extension === 'pdf' || $declaredFormat === 'PDF') {
            return 'PDF';
        }

        $isDocx = str_contains($mimeType, 'wordprocessingml.document') || $extension === 'docx' || $declaredFormat === 'DOCX';
        if ($isDocx) {
            return 'DOCX';
        }

        $isXlsx = str_contains($mimeType, 'spreadsheetml.sheet') || $extension === 'xlsx' || $declaredFormat === 'XLSX';
        if ($isXlsx) {
            return 'XLSX';
        }

        return null;
    }

    /** @return array{format:string,render_representation:string,review:array<string,mixed>,mappings:list<array<string,mixed>>,table_layouts:array<string,array<string,mixed>>,analysis:array<string,mixed>,preparation:array<string,mixed>} */
    public function configuration(DocumentTemplate $template): array
    {
        $decoded = json_decode((string) $template->content_template, true);
        $mappings = is_array($decoded['mappings'] ?? null) ? array_values($decoded['mappings']) : [];
        $tableLayouts = $this->normalizeTableLayouts((string) $template->document_type, is_array($decoded['table_layouts'] ?? null) ? $decoded['table_layouts'] : []);
        $analysis = is_array($decoded['analysis'] ?? null)
            ? $decoded['analysis']
            : $this->analysis((string) $template->document_type, $mappings, [], $tableLayouts);
        return [
            'format' => (string) ($decoded['format'] ?? ''),
            'render_representation' => (string) ($decoded['render_representation'] ?? ''),
            'review' => is_array($decoded['review'] ?? null) ? $decoded['review'] : [],
            'mappings' => $mappings,
            'table_layouts' => $tableLayouts,
            'analysis' => $analysis,
            // Consumed by DocumentTemplateController's preview()/sample() to
            // gate an Office Draft's readiness (state/page_count) - present
            // in the persisted content_template since prepareOfficeDraft(),
            // but previously dropped here, which made Preview 422 for every
            // Office Draft regardless of its actual preparation state.
            'preparation' => is_array($decoded['preparation'] ?? null) ? $decoded['preparation'] : ['state' => 'PENDING'],
        ];
    }

    /** @param array<int,array<string,mixed>> $mappings @param array<string,mixed> $suggestions @param array<string,array{row_height_percent:float,max_rows:int}> $tableLayouts @return array<string,mixed> */
    public function analysis(string $type, array $mappings, array $suggestions = [], array $tableLayouts = []): array
    {
        $definitions = $this->fieldDefinitions($type);
        $mapped = collect($mappings)->pluck('field')->filter()->unique()->values()->all();
        $required = $this->requiredFields($type);
        $missing = array_values(array_filter($required, fn (string $key): bool => ! in_array($key, $mapped, true)));
        $mappedByField = collect($mappings)->filter(fn ($mapping) => is_array($mapping) && filled($mapping['field'] ?? null))->keyBy('field');
        $renderMissing = array_values(array_filter($required, function (string $field) use ($mappedByField): bool {
            $mapping = $mappedByField->get($field);

            return ! is_array($mapping)
                || ! str_starts_with((string) ($mapping['target'] ?? ''), 'click:')
                || ! is_numeric($mapping['page'] ?? null)
                || ! is_numeric($mapping['x'] ?? null)
                || ! is_numeric($mapping['y'] ?? null);
        }));
        $tables = [];
        $tableRequirements = [];
        foreach (collect($definitions)->pluck('table')->filter()->unique() as $table) {
            $tableFields = array_keys(array_filter($definitions, fn (array $field): bool => $field['table'] === $table && $field['required']));
            $tables[$table] = [
                'label' => match ($table) {
                    'borrowed_items' => 'Borrowed Items Table',
                    'release_return' => 'Release / Return Table',
                    'laundry_items' => 'Laundry Items Table',
                    'gate_items' => 'Gate Pass Items Table',
                    'billing_lines' => 'Billing Lines Table',
                    default => 'Affected Property Table',
                },
                'detected' => $tableFields !== [] && count(array_diff($tableFields, $mapped)) === 0,
            ];

            $repeatingFields = array_keys(array_filter($definitions, fn (array $field, string $key): bool => $field['table'] === $table && str_starts_with($key, 'items.'), ARRAY_FILTER_USE_BOTH));
            if ($repeatingFields !== []) {
                $layout = $tableLayouts[$table] ?? null;
                $tableRequirements[$table] = [
                    'label' => $tables[$table]['label'],
                    'configured' => is_array($layout)
                        && ($layout['row_height_percent'] ?? 0) > 0
                        && ($layout['max_rows'] ?? 0) > 0,
                ];
            }
        }
        $tableMissing = array_keys(array_filter($tableRequirements, fn (array $table): bool => ! $table['configured']));

        return [
            'detected_count' => count(array_intersect($required, $mapped)),
            'required_count' => count($required),
            'missing' => $missing,
            'render_missing' => $renderMissing,
            'suggestions' => $suggestions,
            'tables' => $tables,
            'table_requirements' => $tableRequirements,
            'table_missing' => $tableMissing,
            'ready' => $missing === [] && $renderMissing === [] && $tableMissing === [],
        ];
    }

    /** @param array<string,mixed> $review @param array<string,mixed> $analysis */
    public function preparationFeedback(string $format, array $review, array $analysis): string
    {
        if ($format === 'PDF' && ! empty($review['scanned']) && empty($review['fillable_widgets'])) {
            return 'The layout contains no readable structure the system can use to prepare document data.';
        }
        if (($analysis['suggestions'] ?? []) !== []) {
            return 'The layout has readable labels, but a required section could not be identified automatically.';
        }

        return 'The system could not identify every required section needed to generate this document.';
    }

    /**
     * Normalise only page-relative table geometry discovered from an uploaded
     * form.  No administrator-entered coordinates or document revision data
     * are accepted here.
     *
     * @param array<string,mixed> $tableLayouts
     * @return array<string,array<string,mixed>>
     */
    public function normalizeTableLayouts(string $type, array $tableLayouts): array
    {
        $allowedTables = array_values(array_unique(array_filter(array_map(
            fn (array $definition): ?string => $definition['table'] ?? null,
            $this->fieldDefinitions($type),
        ))));
        $normalized = [];

        foreach ($allowedTables as $table) {
            $layout = $tableLayouts[$table] ?? null;
            if (! is_array($layout)) {
                continue;
            }
            $rowHeight = is_numeric($layout['row_height_percent'] ?? null) ? (float) $layout['row_height_percent'] : 0.0;
            $maxRows = filter_var($layout['max_rows'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            if ($rowHeight <= 0 || $rowHeight > 25 || $maxRows < 1 || $maxRows > 100) {
                continue;
            }
            $maximumHeight = is_numeric($layout['max_height_percent'] ?? null)
                ? (float) $layout['max_height_percent']
                : $rowHeight * $maxRows;
            if ($maximumHeight < $rowHeight || $maximumHeight > 95) {
                continue;
            }
            $normalized[$table] = [
                'row_height_percent' => round($rowHeight, 2),
                'max_rows' => $maxRows,
                'max_height_percent' => round($maximumHeight, 2),
            ];
            $grid = $this->normalizeTableGrid($layout['grid'] ?? null);
            if ($grid !== null) {
                $normalized[$table]['grid'] = $grid;
            }
        }

        return $normalized;
    }

    /** @return array<string,mixed>|null */
    private function normalizeTableGrid(mixed $grid): ?array
    {
        if (! is_array($grid)) {
            return null;
        }
        $page = filter_var($grid['page'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $left = is_numeric($grid['left_percent'] ?? null) ? (float) $grid['left_percent'] : -1.0;
        $right = is_numeric($grid['right_percent'] ?? null) ? (float) $grid['right_percent'] : -1.0;
        $top = is_numeric($grid['body_top_percent'] ?? null) ? (float) $grid['body_top_percent'] : -1.0;
        $bottom = is_numeric($grid['body_bottom_percent'] ?? null) ? (float) $grid['body_bottom_percent'] : -1.0;
        if ($page < 1 || $left < 0 || $right <= $left || $right > 100 || $top < 0 || $bottom <= $top || $bottom > 100) {
            return null;
        }
        $boundaries = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?float => is_numeric($value) && (float) $value >= $left && (float) $value <= $right ? round((float) $value, 2) : null,
            is_array($grid['vertical_boundaries_percent'] ?? null) ? $grid['vertical_boundaries_percent'] : [],
        ), fn (?float $value): bool => $value !== null)));
        sort($boundaries, SORT_NUMERIC);
        if (count($boundaries) < 2 || abs($boundaries[0] - $left) > 0.6 || abs($boundaries[array_key_last($boundaries)] - $right) > 0.6) {
            return null;
        }

        return [
            'page' => $page,
            'left_percent' => round($left, 2),
            'right_percent' => round($right, 2),
            'body_top_percent' => round($top, 2),
            'body_bottom_percent' => round($bottom, 2),
            'vertical_boundaries_percent' => $boundaries,
        ];
    }

    /** @return array<string,mixed> */
    public function sampleValues(string $type): array
    {
        $common = [
            'borrower_name' => 'Sample Borrower',
            'document_date' => '10 September 2026',
            'employee_checkbox' => 'X',
            'others_checkbox' => '',
            'other_classification' => '',
            'purpose' => 'Seminar',
            'issued_date' => '10 September 2026',
            'expected_return_date' => '12 September 2026',
            'date_released' => '10 September 2026',
            'release_time' => '2:15 PM',
            'date_returned' => '12 September 2026',
            'remarks' => 'Complete',
            'borrowed_by_printed_name' => 'JUAN DELA CRUZ',
            'borrowed_by_designation' => 'Instructor',
            'borrowed_by_date' => '10 September 2026',
            'approved_by_printed_name' => 'SAMPLE SPMU HEAD',
            'approved_by_designation' => 'SPMU Head',
            'approved_by_date' => '9 September 2026',
            'issued_by_printed_name' => 'SAMPLE ACTION OFFICER',
            'issued_by_designation' => 'SPMU Action Officer',
            'issued_by_date' => '10 September 2026',
            'return_received_by_printed_name' => 'SAMPLE RECEIVING OFFICER',
            'return_received_by_designation' => 'SPMU Action Officer',
            'return_received_by_date' => '12 September 2026',
            'items.qty' => '15',
            'items.unit' => 'Piece',
            'items.description' => 'Monoblock Chair',
            'approved_by' => 'SPMU Head approval record',
        ];
        return match ($type) {
            'LAUNDRY_FORM' => $common + [
                'request_no' => 'BR-2026-0001', 'custody_no' => 'CUS-2026-0001', 'requesting_office' => 'Sample Office',
                'date_requested' => '10 September 2026', 'date_released' => '10 September 2026',
                'items.date_requested' => '10 September 2026', 'items.date_received' => '12 September 2026',
                'items.date_completed' => '13 September 2026', 'items.received_quantity' => '15',
                'items.completed_quantity' => '15', 'items.affected_quantity' => '0', 'items.issue_type' => 'Serviceable',
                'items.remarks' => 'Sample physical laundry finding',
                'requested_by_printed_name' => 'SAMPLE BORROWER', 'requested_by_designation' => 'Instructor',
                'requested_by_date' => '10 September 2026', 'approved_by_printed_name' => 'SAMPLE SPMU HEAD',
                'approved_by_designation' => 'SPMU Head', 'approved_by_date' => '10 September 2026',
                'received_by_printed_name' => 'SAMPLE LAUNDRY WORKER', 'received_by_date' => '12 September 2026',
                'verified_by_printed_name' => 'SAMPLE ACTION OFFICER', 'verified_by_designation' => 'SPMU Action Officer',
                'verified_by_date' => '13 September 2026',
            ],
            'GATE_PASS' => $common + [
                'gate_pass_no' => 'GP-2026-0001', 'request_no' => 'BR-2026-0001', 'custody_no' => 'CUS-2026-0001',
                'document_date' => '10 September 2026', 'requesting_office' => 'Sample Office',
                'destination' => 'Approved off-campus location', 'movement_scope' => 'Off Campus', 'exit_date' => '10 September 2026',
                'verification_remarks' => 'Verified for approved movement', 'items.movement_scope' => 'Off Campus',
                'requested_by_printed_name' => 'SAMPLE BORROWER', 'requested_by_designation' => 'Instructor',
                'requested_by_date' => '10 September 2026', 'verified_by_printed_name' => 'SAMPLE ACTION OFFICER',
                'verified_by_designation' => 'SPMU Action Officer', 'verified_by_date' => '10 September 2026',
                'approved_by_printed_name' => 'SAMPLE SPMU HEAD', 'approved_by_designation' => 'SPMU Head',
                'approved_by_date' => '10 September 2026', 'guard_printed_name' => 'SAMPLE SECURITY GUARD',
                'guard_date' => '10 September 2026',
            ],
            'BILLING_STATEMENT' => $common + [
                'billing_no' => 'BILL-2026-0001', 'request_no' => 'BR-2026-0001', 'custody_no' => 'CUS-2026-0001',
                'incident_no' => 'INC-2026-0001', 'due_date' => '20 September 2026',
                'statement_remarks' => 'Sample accountability billing details', 'items.line_type' => 'PROPERTY CHARGE',
                'items.basis' => 'Approved accountability assessment', 'items.penalty_type' => 'Damage',
                'items.incident_no' => 'INC-2026-0001', 'items.amount' => 'PHP 150.00', 'total_amount' => 'PHP 150.00',
                'issuer_printed_name' => 'SAMPLE SPMU OFFICER', 'issuer_designation' => 'SPMU Action Officer',
                'issuer_date' => '10 September 2026',
            ],
            'ACCOUNTABILITY_COMPLIANCE_NOTICE' => $common + [
                'incident_no' => 'INC-2026-0001', 'decision_date' => '10 September 2026',
                'office_unit' => 'College of Sample Studies', 'request_no' => 'BR-2026-0001',
                'custody_no' => 'CUS-2026-0001', 'incident_type' => 'Damaged',
                'decision_remarks' => 'Repair or replace the affected property and coordinate with SPMU for verification.',
                'items.finding' => 'Damaged', 'items.disposition' => 'Repair / Replacement',
                'head_printed_name' => 'SAMPLE SPMU HEAD', 'head_designation' => 'Head, Supply and Property Management Unit',
                'head_date' => '10 September 2026',
            ],
            'ADMINISTRATIVE_SANCTION_NOTICE' => $common + [
                'office_unit' => 'College of Sample Studies', 'request_no' => 'BR-2026-0001',
                'custody_no' => 'CUS-2026-0001', 'academic_period' => '2026-2027 · 1st Semester',
                'offense_level' => '1st Offense', 'confirmed_date' => '10 September 2026',
                'reason_text' => 'Damaged', 'sanction_label' => 'Written Reprimand',
                'effective_from' => '10 September 2026', 'effective_to' => '',
                'head_remarks' => 'Confirmed after review of the recorded finding and supporting evidence.',
                'head_printed_name' => 'SAMPLE SPMU HEAD', 'head_designation' => 'Head, Supply and Property Management Unit',
                'head_date' => '10 September 2026',
            ],
            'RSLDDP' => $common + [
                'rslddp_reference' => 'RSLDDP-2026-0001', 'incident_no' => 'INC-2026-0001',
                'custody_no' => 'CUS-2026-0001', 'request_no' => 'BR-2026-0001',
                'police_blotter_reference' => 'BLT-2026-0001', 'incident_type' => 'Damaged property',
                'reported_date' => '10 September 2026', 'incident_remarks' => 'Sample incident details',
                'appraisal_amount' => 'PHP 150.00', 'items.condition' => 'Damaged',
                'items.assessed_value' => 'PHP 150.00', 'items.disposition' => 'For accountability assessment',
                'reported_by_printed_name' => 'SAMPLE ACTION OFFICER', 'reported_by_designation' => 'SPMU Action Officer',
                'reported_by_date' => '10 September 2026',
            ],
            default => $common,
        };
    }

    /** @return array<string,mixed> */
    private function inspectPdf(string $bytes, bool $withLayoutAnalysis = false): array
    {
        if (! str_starts_with(ltrim($bytes), '%PDF-')) {
            throw ValidationException::withMessages(['template_file' => 'The uploaded PDF is unreadable or corrupt.']);
        }
        if (preg_match('/\/Encrypt\b/', $bytes) === 1) {
            throw ValidationException::withMessages(['template_file' => 'Encrypted PDFs cannot be activated. Upload an unlocked approved layout.']);
        }
        preg_match_all('/\((?:\\\\.|[^)]){1,180}\)/s', $bytes, $matches);
        $strings = array_map(function (string $value): string {
            $value = trim($value, '()');
            return trim(str_replace(['\\n', '\\r', '\\(', '\\)'], [' ', ' ', '(', ')'], $value));
        }, $matches[0] ?? []);
        preg_match_all('/\/T\s*\(([^)]+)\)/', $bytes, $fieldNames);
        $text = implode("\n", array_filter([...$strings, ...($fieldNames[1] ?? [])]));
        $pages = preg_match_all('/\/Type\s*\/Page\b/', $bytes);
        $widgets = $this->pdfFormWidgets($bytes);
        $layout = $withLayoutAnalysis
            ? $this->pdfReader->read($bytes)
            : ['source' => 'NOT_REQUESTED', 'words' => [], 'lines' => [], 'model' => []];

        return [
            'pages' => max(1, (int) $pages),
            'scanned' => trim($text) === '',
            'text' => $text,
            'labels' => $this->labelsFromText($text),
            'fillable_fields' => array_values(array_unique([...($fieldNames[1] ?? []), ...array_column($widgets, 'name')])),
            // This is structural metadata only. It is used internally to
            // prepare a production layout and is never shown to an admin.
            'fillable_widgets' => $widgets,
            'layout_source' => $layout['source'],
            'layout_words' => $layout['words'],
            'layout_lines' => $layout['lines'],
            // Generic, page-relative structural model. It is consumed only
            // while preparing a template and is never exposed to an admin.
            'layout_model' => $layout['model'],
        ];
    }

    /**
     * Read enough uncompressed AcroForm metadata to make a deterministic
     * decision. PDFs using object streams remain valid uploads, but are not
     * treated as system-ready unless their field geometry is available.
     *
     * @return list<array{name:string,page:int,x:float,y:float}>
     */
    private function pdfFormWidgets(string $bytes): array
    {
        preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)\bendobj\b/s', $bytes, $objectMatches, PREG_SET_ORDER);
        $objects = [];
        foreach ($objectMatches as $match) {
            $objects[(int) $match[1]] = (string) $match[2];
        }
        if ($objects === []) {
            return [];
        }

        $defaultBox = $this->pdfMediaBox($bytes);
        $pages = [];
        foreach ($objects as $number => $object) {
            if (preg_match('/\/Type\s*\/Page\b/', $object) !== 1) {
                continue;
            }
            $pages[$number] = [
                'page' => count($pages) + 1,
                'box' => $this->pdfMediaBox($object) ?? $defaultBox,
            ];
        }

        $widgets = [];
        foreach ($objects as $object) {
            if (preg_match('/\/Subtype\s*\/Widget\b/', $object) !== 1) {
                continue;
            }
            $name = $this->pdfFieldName($object);
            if ($name === null && preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $object, $parent) === 1) {
                $name = $this->pdfFieldName($objects[(int) $parent[1]] ?? '');
            }
            $rect = $this->pdfRect($object);
            $pageObject = preg_match('/\/P\s+(\d+)\s+\d+\s+R/', $object, $pageReference) === 1 ? (int) $pageReference[1] : null;
            $page = $pageObject !== null ? ($pages[$pageObject] ?? null) : (count($pages) === 1 ? reset($pages) : null);
            if ($name === null || $rect === null || ! is_array($page) || ! is_array($page['box'])) {
                continue;
            }
            [$left, $bottom, $right, $top] = $rect;
            [$boxLeft, $boxBottom, $boxRight, $boxTop] = $page['box'];
            $width = $boxRight - $boxLeft;
            $height = $boxTop - $boxBottom;
            if ($width <= 0 || $height <= 0) {
                continue;
            }
            $widgets[] = [
                'name' => $name,
                'page' => (int) $page['page'],
                'x' => round((min($left, $right) - $boxLeft) / $width * 100, 2),
                'y' => round(($boxTop - max($bottom, $top)) / $height * 100, 2),
            ];
        }

        return $widgets;
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function pdfMediaBox(string $pdf): ?array
    {
        if (preg_match('/\/MediaBox\s*\[\s*([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s*\]/', $pdf, $match) !== 1) {
            return null;
        }

        return [(float) $match[1], (float) $match[2], (float) $match[3], (float) $match[4]];
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function pdfRect(string $object): ?array
    {
        if (preg_match('/\/Rect\s*\[\s*([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s*\]/', $object, $match) !== 1) {
            return null;
        }

        return [(float) $match[1], (float) $match[2], (float) $match[3], (float) $match[4]];
    }

    private function pdfFieldName(string $object): ?string
    {
        if (preg_match('/\/T\s*\(((?:\\\\.|[^)])+)\)/s', $object, $match) === 1) {
            return trim(str_replace(['\\\\(', '\\\\)', '\\\\n', '\\\\r'], ['(', ')', ' ', ' '], $match[1]));
        }
        if (preg_match('/\/T\s*\/([^\s\/>\[\]()]+)/', $object, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function inspectDocx(string $bytes): array
    {
        [$zip, $path] = $this->openZip($bytes, 'DOCX');
        try {
            if ($zip->getFromName('[Content_Types].xml') === false) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded DOCX is missing its Office package manifest.']);
            }
            $document = $zip->getFromName('word/document.xml');
            if ($document === false) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded DOCX is missing its Word document content.']);
            }
            $xml = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_starts_with($name, 'word/') && str_ends_with($name, '.xml')) {
                    $xml .= (string) $zip->getFromIndex($i);
                }
            }
            preg_match_all('/\{\{[a-z0-9_.]+\}\}/i', $xml, $matches);
            preg_match_all('/(?:w:tag|w:alias)\s+w:val="([^"]+)"/i', $xml, $controls);
            $text = trim(html_entity_decode(strip_tags(preg_replace('/<w:tab[^>]*\/>/i', ' ', $xml) ?? $xml)));
            return [
                'placeholders' => array_values(array_unique($matches[0] ?? [])),
                'content_controls' => array_values(array_unique($controls[1] ?? [])),
                'has_tables' => str_contains($xml, '<w:tbl'),
                'text' => $text,
                'labels' => $this->labelsFromText($text),
                'dynamic_schema' => $this->docxDynamicSchema($zip),
            ];
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    private function inspectXlsx(string $bytes): array
    {
        [$zip, $path] = $this->openZip($bytes, 'XLSX');
        try {
            if ($zip->getFromName('[Content_Types].xml') === false) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded XLSX is unreadable or corrupt.']);
            }
            $shared = $this->sharedStrings((string) ($zip->getFromName('xl/sharedStrings.xml') ?: ''));
            $labels = [];
            $worksheets = [];
            $tableNames = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $match) !== 1) {
                    continue;
                }
                $sheetName = 'Sheet '.$match[1];
                $worksheets[] = $sheetName;
                $sheetXml = (string) $zip->getFromIndex($i);
                preg_match_all('/<c\b[^>]*\br="([A-Z]+\d+)"([^>]*)>(.*?)<\/c>/is', $sheetXml, $cells, PREG_SET_ORDER);
                foreach ($cells as $cell) {
                    $value = $this->xlsxCellValue($cell[2], $cell[3], $shared);
                    if ($value !== '') {
                        $labels[] = ['text' => $value, 'location' => $sheetName.'!'.$cell[1]];
                    }
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_starts_with($name, 'xl/tables/') && str_ends_with($name, '.xml')) {
                    $table = (string) $zip->getFromIndex($i);
                    if (preg_match('/\bdisplayName="([^"]+)"/i', $table, $match) === 1) {
                        $tableNames[] = html_entity_decode($match[1]);
                    }
                }
            }
            if ($worksheets === []) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded XLSX has no readable worksheets.']);
            }
            return [
                'worksheets' => $worksheets,
                'labels' => $labels,
                'text' => implode("\n", array_column($labels, 'text')),
                'tables' => array_values(array_unique($tableNames)),
                'dynamic_schema' => $this->xlsxDynamicSchema($zip),
            ];
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** @return list<array{text:string,location:string}> */
    private function labelsFromText(string $text): array
    {
        $chunks = preg_split('/[\r\n]+|(?<=[.:;])\s{2,}/', $text) ?: [];
        $labels = [];
        foreach ($chunks as $chunk) {
            $chunk = trim(preg_replace('/\s+/', ' ', $chunk) ?? '');
            if ($chunk !== '' && mb_strlen($chunk) <= 180) {
                $labels[] = ['text' => $chunk, 'location' => ''];
            }
        }
        return array_slice(array_values(array_unique($labels, SORT_REGULAR)), 0, 250);
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower($value);
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '');
    }

    /** @return list<string> */
    private function sharedStrings(string $xml): array
    {
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $xml, $items);
        return array_map(fn (string $item): string => trim(html_entity_decode(strip_tags($item))), $items[1] ?? []);
    }

    /** @param list<string> $shared */
    private function xlsxCellValue(string $attributes, string $content, array $shared): string
    {
        if (preg_match('/\bt="s"/i', $attributes) === 1 && preg_match('/<v>(\d+)<\/v>/i', $content, $match) === 1) {
            return (string) ($shared[(int) $match[1]] ?? '');
        }
        if (preg_match('/<t[^>]*>(.*?)<\/t>/is', $content, $match) === 1) {
            return trim(html_entity_decode(strip_tags($match[1])));
        }
        return preg_match('/<v>(.*?)<\/v>/is', $content, $match) === 1 ? trim(html_entity_decode(strip_tags($match[1]))) : '';
    }

    /** @return array<string,mixed> */
    private function docxDynamicSchema(ZipArchive $zip): array
    {
        $parts = [];
        $controls = [];
        $bookmarks = [];
        $tables = [];
        $sections = [];
        $headersFooters = [];
        $unsupported = [];
        $partNames = [];
        $truncations = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! is_string($name)) {
                continue;
            }
            if ($name === 'word/document.xml' || preg_match('#^word/(?:header|footer)\d+\.xml$#', $name) === 1) {
                $partNames[] = $name;
            }
            if (str_starts_with($name, 'word/embeddings/') || str_starts_with($name, 'word/activeX/')) {
                $unsupported[] = ['part' => $name, 'kind' => 'embedded_office_object'];
            }
        }

        foreach ($partNames as $part) {
            $xml = $zip->getFromName($part);
            if (! is_string($xml)) {
                continue;
            }
            [$document, $xpath] = $this->officeXml($xml, 'DOCX', $part, ['w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing']);
            $isHeaderFooter = $part !== 'word/document.xml';
            if ($isHeaderFooter) {
                $headersFooters[] = ['part' => $part, 'paragraphs' => $xpath->query('//w:p')->length, 'tables' => $xpath->query('//w:tbl')->length];
            }
            $parts[] = ['part' => $part, 'paragraphs' => $xpath->query('//w:p')->length, 'tables' => $xpath->query('//w:tbl')->length];

            foreach ($xpath->query('//w:sdt') as $index => $control) {
                if (! $control instanceof \DOMElement) {
                    continue;
                }
                $properties = $xpath->query('./w:sdtPr', $control)->item(0);
                $tag = $this->officeAttribute($xpath->query('./w:tag', $properties)->item(0), 'val');
                $alias = $this->officeAttribute($xpath->query('./w:alias', $properties)->item(0), 'val');
                $id = $this->officeAttribute($xpath->query('./w:id', $properties)->item(0), 'val');
                $binding = $xpath->query('./w:dataBinding', $properties)->item(0);
                $locked = $xpath->query('./w:lock', $properties)->length > 0;
                $classification = $locked
                    ? 'LOCKED_UNSUPPORTED'
                    : ($binding instanceof \DOMElement ? 'SYSTEM_CONTROLLED' : 'PENDING_RUNTIME_CLASSIFICATION');
                $entry = [
                    'part' => $part,
                    'index' => $index + 1,
                    'id' => $id,
                    'tag' => $tag,
                    'alias' => $alias,
                    'data_binding' => $binding instanceof \DOMElement ? [
                        'xpath' => $this->officeAttribute($binding, 'xpath'),
                        'store_item_id' => $this->officeAttribute($binding, 'storeItemID'),
                    ] : null,
                    'classification' => $classification,
                ];
                $controls[] = $entry;
            }

            foreach ($xpath->query('//w:bookmarkStart') as $bookmark) {
                if ($bookmark instanceof \DOMElement) {
                    $bookmarks[] = ['part' => $part, 'id' => $this->officeAttribute($bookmark, 'id'), 'name' => $this->officeAttribute($bookmark, 'name')];
                }
            }

            foreach ($xpath->query('//w:tbl') as $index => $table) {
                if (! $table instanceof \DOMElement) {
                    continue;
                }
                $rowCount = $xpath->query('./w:tr', $table)->length;
                $cellCounts = [];
                foreach ($xpath->query('./w:tr', $table) as $row) {
                    $cellCounts[] = $xpath->query('./w:tc', $row)->length;
                }
                $tables[] = ['part' => $part, 'index' => $index + 1, 'rows' => $rowCount, 'cells_per_row' => $cellCounts];
            }

            if ($part === 'word/document.xml') {
                foreach ($xpath->query('//w:sectPr') as $index => $section) {
                    if (! $section instanceof \DOMElement) {
                        continue;
                    }
                    $pageSize = $xpath->query('./w:pgSz', $section)->item(0);
                    $margins = $xpath->query('./w:pgMar', $section)->item(0);
                    $break = $xpath->query('./w:type', $section)->item(0);
                    $sections[] = [
                        'index' => $index + 1,
                        'width_twips' => $this->officeAttribute($pageSize, 'w'),
                        'height_twips' => $this->officeAttribute($pageSize, 'h'),
                        'orientation' => $this->officeAttribute($pageSize, 'orient') ?: 'portrait',
                        'margins_twips' => [
                            'top' => $this->officeAttribute($margins, 'top'), 'right' => $this->officeAttribute($margins, 'right'),
                            'bottom' => $this->officeAttribute($margins, 'bottom'), 'left' => $this->officeAttribute($margins, 'left'),
                        ],
                        'break_type' => $this->officeAttribute($break, 'val'),
                    ];
                }
            }

            if ($xpath->query('//wp:anchor | //w:txbxContent')->length > 0) {
                $unsupported[] = ['part' => $part, 'kind' => 'floating_shape_or_textbox'];
            }
            unset($document);
        }

        $customProperties = $this->customProperties($zip);
        $schema = $this->newDynamicSchema('DOCX');
        $schema['office_identity']['content_controls'] = $this->boundedSchemaNodes($controls, $truncations, 'office_identity.content_controls');
        $schema['office_identity']['bookmarks'] = $this->boundedSchemaNodes($bookmarks, $truncations, 'office_identity.bookmarks');
        $schema['office_identity']['custom_properties'] = $this->boundedSchemaNodes($customProperties, $truncations, 'office_identity.custom_properties');
        $schema['document_structure'] = [
            'parts' => $this->boundedSchemaNodes($parts, $truncations, 'document_structure.parts'),
            'sections' => $this->boundedSchemaNodes($sections, $truncations, 'document_structure.sections'),
            'tables' => $this->boundedSchemaNodes($tables, $truncations, 'document_structure.tables'),
            'headers_footers' => $this->boundedSchemaNodes($headersFooters, $truncations, 'document_structure.headers_footers'),
            // DOCX is flow-based. Pagination is intentionally not inferred
            // before LibreOffice renders the final working document.
            'pre_render_page_coordinates' => false,
        ];
        $schema['regions'] = $this->docxRegions($controls);
        $schema['style_edit_metadata'] = $this->docxStyleMetadata($zip, $truncations);
        $schema['unsupported'] = $this->boundedSchemaNodes($unsupported, $truncations, 'unsupported');
        $this->appendOfficeWarnings($schema, $controls, $unsupported);
        $this->appendSchemaTruncationWarnings($schema, $truncations);

        return $schema;
    }

    /** @return array<string,mixed> */
    private function xlsxDynamicSchema(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if (! is_string($workbookXml)) {
            throw ValidationException::withMessages(['template_file' => 'The uploaded XLSX is missing workbook metadata.']);
        }
        [, $workbookXpath] = $this->officeXml($workbookXml, 'XLSX', 'xl/workbook.xml', ['x' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships']);
        $relationships = $this->xlsxRelationships($zip);
        $worksheets = [];
        $namedRanges = [];
        $tables = [];
        $unsupported = [];
        $truncations = [];

        foreach ($workbookXpath->query('//x:definedName') as $definedName) {
            if ($definedName instanceof \DOMElement) {
                $reference = $this->safeNamedRangeReference($definedName->textContent);
                $namedRanges[] = [
                    'name' => $this->officeAttribute($definedName, 'name'),
                    'scope_sheet_id' => $this->officeAttribute($definedName, 'localSheetId'),
                    // Persist only a safe cell/range reference. Arbitrary
                    // defined-name formulas can contain source values.
                    'reference' => $reference,
                ];
            }
        }

        foreach ($workbookXpath->query('//x:sheets/x:sheet') as $sheet) {
            if (! $sheet instanceof \DOMElement) {
                continue;
            }
            $name = $this->officeAttribute($sheet, 'name');
            $relationshipId = $this->officeAttribute($sheet, 'id');
            $target = $relationships[$relationshipId] ?? null;
            $sheetPath = $target ? $this->xlsxTargetPath('xl/workbook.xml', $target) : null;
            if (! $sheetPath || ! is_string($sheetXml = $zip->getFromName($sheetPath))) {
                continue;
            }
            [, $sheetXpath] = $this->officeXml($sheetXml, 'XLSX', $sheetPath, ['x' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships']);
            $rows = [];
            foreach ($sheetXpath->query('//x:sheetData/x:row') as $row) {
                if (! $row instanceof \DOMElement) {
                    continue;
                }
                $rows[] = ['row' => $this->officeAttribute($row, 'r'), 'height' => $this->officeAttribute($row, 'ht'), 'custom_height' => $this->officeAttribute($row, 'customHeight') === '1'];
            }
            $occupiedCells = [];
            foreach ($sheetXpath->query('//x:sheetData/x:row/x:c') as $cell) {
                if (! $cell instanceof \DOMElement) {
                    continue;
                }
                $reference = $this->officeAttribute($cell, 'r');
                if ($reference !== '') {
                    // A cell address is structural metadata; its contents
                    // remain only in the immutable Office source.
                    $occupiedCells[] = $reference;
                }
            }
            $merges = [];
            foreach ($sheetXpath->query('//x:mergeCells/x:mergeCell') as $merge) {
                if ($merge instanceof \DOMElement) {
                    $merges[] = $this->officeAttribute($merge, 'ref');
                }
            }
            $columns = [];
            foreach ($sheetXpath->query('//x:cols/x:col') as $column) {
                if ($column instanceof \DOMElement) {
                    $columns[] = ['min' => $this->officeAttribute($column, 'min'), 'max' => $this->officeAttribute($column, 'max'), 'width' => $this->officeAttribute($column, 'width'), 'hidden' => $this->officeAttribute($column, 'hidden') === '1'];
                }
            }
            $pageSetup = $sheetXpath->query('//x:pageSetup')->item(0);
            $pageMargins = $sheetXpath->query('//x:pageMargins')->item(0);
            $worksheets[] = [
                'name' => $name,
                'part' => $sheetPath,
                'rows' => $this->boundedSchemaNodes($rows, $truncations, 'document_structure.worksheets.'.$name.'.rows'),
                'columns' => $this->boundedSchemaNodes($columns, $truncations, 'document_structure.worksheets.'.$name.'.columns'),
                'occupied_cells' => $this->boundedSchemaNodes($occupiedCells, $truncations, 'document_structure.worksheets.'.$name.'.occupied_cells'),
                'merged_cells' => $this->boundedSchemaNodes($merges, $truncations, 'document_structure.worksheets.'.$name.'.merged_cells'),
                'print_area' => $this->namedRangeReference($namedRanges, '_xlnm.Print_Area', $name),
                'page_setup' => [
                    'orientation' => $this->officeAttribute($pageSetup, 'orientation'),
                    'paper_size' => $this->officeAttribute($pageSetup, 'paperSize'),
                    'fit_to_width' => $this->officeAttribute($pageSetup, 'fitToWidth'),
                    'fit_to_height' => $this->officeAttribute($pageSetup, 'fitToHeight'),
                    'margins' => ['top' => $this->officeAttribute($pageMargins, 'top'), 'right' => $this->officeAttribute($pageMargins, 'right'), 'bottom' => $this->officeAttribute($pageMargins, 'bottom'), 'left' => $this->officeAttribute($pageMargins, 'left')],
                ],
            ];
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $part = $zip->getNameIndex($i);
            if (! is_string($part)) {
                continue;
            }
            if (str_starts_with($part, 'xl/tables/') && str_ends_with($part, '.xml') && is_string($tableXml = $zip->getFromIndex($i))) {
                [, $tableXpath] = $this->officeXml($tableXml, 'XLSX', $part, ['x' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main']);
                $table = $tableXpath->query('//x:table')->item(0);
                if ($table instanceof \DOMElement) {
                    $columns = [];
                    foreach ($tableXpath->query('//x:table/x:tableColumns/x:tableColumn') as $column) {
                        if ($column instanceof \DOMElement) {
                            $columns[] = $this->officeAttribute($column, 'name');
                        }
                    }
                    $tables[] = ['part' => $part, 'name' => $this->officeAttribute($table, 'displayName'), 'ref' => $this->officeAttribute($table, 'ref'), 'columns' => $columns];
                }
            }
            if (str_starts_with($part, 'xl/drawings/') || str_starts_with($part, 'xl/embeddings/')) {
                $unsupported[] = ['part' => $part, 'kind' => str_starts_with($part, 'xl/embeddings/') ? 'embedded_office_object' : 'drawing_object'];
            }
        }

        $schema = $this->newDynamicSchema('XLSX');
        $schema['office_identity']['named_ranges'] = $this->boundedSchemaNodes($namedRanges, $truncations, 'office_identity.named_ranges');
        $schema['office_identity']['tables'] = $this->boundedSchemaNodes($tables, $truncations, 'office_identity.tables');
        $schema['office_identity']['custom_properties'] = $this->boundedSchemaNodes($this->customProperties($zip), $truncations, 'office_identity.custom_properties');
        $schema['document_structure'] = [
            'worksheets' => $this->boundedSchemaNodes($worksheets, $truncations, 'document_structure.worksheets'),
            'tables' => $this->boundedSchemaNodes($tables, $truncations, 'document_structure.tables'),
        ];
        $schema['regions'] = [
            'static_editable' => [],
            // Excel built-ins such as Print_Area describe layout, not a
            // writable business-data region. They remain in the structural
            // schema but are never offered to a future runtime resolver.
            'data' => array_map(fn (array $range): array => ['identity' => 'named_range:'.$range['name'], 'classification' => 'PENDING_RUNTIME_CLASSIFICATION'], $this->runtimeNamedRanges($namedRanges)),
            'signatures' => array_values(array_filter(array_map(fn (array $range): ?array => str_contains($this->normalise($range['name']), 'signature') ? ['identity' => 'named_range:'.$range['name'], 'classification' => 'SYSTEM_CONTROLLED'] : null, $this->runtimeNamedRanges($namedRanges)))),
            'repeating' => array_map(fn (array $table): array => ['identity' => 'table:'.$table['name'], 'classification' => 'PENDING_RUNTIME_CLASSIFICATION'], $tables),
        ];
        $schema['style_edit_metadata'] = $this->xlsxStyleMetadata($zip, $truncations);
        $schema['unsupported'] = $this->boundedSchemaNodes($unsupported, $truncations, 'unsupported');
        $this->appendOfficeWarnings($schema, $namedRanges, $unsupported);
        $this->appendSchemaTruncationWarnings($schema, $truncations);

        return $schema;
    }

    /** @return array<string,mixed> */
    private function newDynamicSchema(string $format): array
    {
        return [
            'schema_version' => 1,
            'source' => ['format' => $format],
            'office_identity' => ['content_controls' => [], 'bookmarks' => [], 'named_ranges' => [], 'tables' => [], 'custom_properties' => []],
            'document_structure' => [],
            'regions' => ['static_editable' => [], 'data' => [], 'signatures' => [], 'repeating' => []],
            'style_edit_metadata' => [],
            'resolver' => ['bindings' => [], 'unresolved' => [], 'confidence' => []],
            'compatibility_warnings' => [],
            'unsupported' => [],
        ];
    }

    /** @param list<array<string,mixed>> $controls @return array<string,list<array<string,mixed>>> */
    private function docxRegions(array $controls): array
    {
        $regions = ['static_editable' => [], 'data' => [], 'signatures' => [], 'repeating' => []];
        foreach ($controls as $control) {
            $identity = trim((string) ($control['tag'] ?: $control['alias'] ?: $control['id']));
            if ($identity === '') {
                continue;
            }
            $entry = ['identity' => 'content_control:'.$identity, 'classification' => $control['classification']];
            $normalised = $this->normalise($identity.' '.($control['alias'] ?? ''));
            if (str_contains($normalised, 'signature')) {
                $regions['signatures'][] = $entry;
            } elseif (str_contains($normalised, 'repeat') || str_contains($normalised, 'collection')) {
                $regions['repeating'][] = $entry;
            } elseif (($control['data_binding'] ?? null) !== null || ($control['tag'] ?? '') !== '') {
                $regions['data'][] = $entry;
            } elseif ($control['classification'] === 'PENDING_RUNTIME_CLASSIFICATION') {
                $regions['static_editable'][] = $entry;
            }
        }

        return $regions;
    }

    /** @param array<string,mixed> $schema @param list<mixed> $identities @param list<mixed> $unsupported */
    private function appendOfficeWarnings(array &$schema, array $identities, array $unsupported): void
    {
        if ($identities === []) {
            $schema['compatibility_warnings'][] = ['severity' => 'WARNING', 'code' => 'NO_EMBEDDED_OFFICE_IDENTITY', 'message' => 'No supported hidden Office identity was found. Later phases may use structural and visible-label recognition only.'];
        } else {
            $schema['compatibility_warnings'][] = ['severity' => 'INFO', 'code' => 'OFFICE_IDENTITY_DISCOVERED', 'message' => 'Supported Office identity was discovered and remains hidden from ordinary Admin users.'];
        }
        foreach ($unsupported as $object) {
            $schema['compatibility_warnings'][] = ['severity' => 'WARNING', 'code' => 'LOCKED_UNSUPPORTED_OFFICE_OBJECT', 'message' => 'An Office object is locked for future editor changes because it cannot yet be safely preserved.', 'part' => $object['part'] ?? null];
        }
    }

    /** @param array<string,mixed> $schema @param list<array{path:string,discovered_count:int}> $truncations */
    private function appendSchemaTruncationWarnings(array &$schema, array $truncations): void
    {
        foreach ($truncations as $truncation) {
            $schema['compatibility_warnings'][] = [
                'severity' => 'BLOCKING',
                'code' => 'SCHEMA_NODE_LIMIT_EXCEEDED',
                'message' => 'The Office layout contains more structural entries than can be safely persisted for a complete template schema.',
                'path' => $truncation['path'],
                'discovered_count' => $truncation['discovered_count'],
            ];
        }
    }

    /** @return array{0:\DOMDocument,1:\DOMXPath} */
    private function officeXml(string $xml, string $format, string $part, array $namespaces): array
    {
        if (! class_exists(\DOMDocument::class)) {
            throw ValidationException::withMessages(['template_file' => "$format structure validation requires the PHP DOM extension."]);
        }
        $document = new \DOMDocument;
        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw ValidationException::withMessages(['template_file' => "The uploaded $format contains unreadable Office XML in $part."]);
        }
        $xpath = new \DOMXPath($document);
        foreach ($namespaces as $prefix => $namespace) {
            $xpath->registerNamespace($prefix, $namespace);
        }

        return [$document, $xpath];
    }

    private function officeAttribute(?\DOMNode $node, string $localName): string
    {
        if (! $node instanceof \DOMElement) {
            return '';
        }
        foreach ($node->attributes as $attribute) {
            if ($attribute->localName === $localName) {
                return $attribute->value;
            }
        }

        return '';
    }

    private function boundedText(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, $limit);
    }

    /** @param list<mixed> $nodes @param list<array{path:string,discovered_count:int}> $truncations @return list<mixed> */
    private function boundedSchemaNodes(array $nodes, array &$truncations, string $path): array
    {
        if (count($nodes) > self::MAX_SCHEMA_NODES) {
            $truncations[] = ['path' => $path, 'discovered_count' => count($nodes)];
        }

        return array_slice(array_values($nodes), 0, self::MAX_SCHEMA_NODES);
    }

    /** @return list<array<string,string>> */
    private function customProperties(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('docProps/custom.xml');
        if (! is_string($xml)) {
            return [];
        }
        [, $xpath] = $this->officeXml($xml, 'Office', 'docProps/custom.xml', ['cp' => 'http://schemas.openxmlformats.org/officeDocument/2006/custom-properties']);
        $properties = [];
        foreach ($xpath->query('//cp:property') as $property) {
            if ($property instanceof \DOMElement) {
                $properties[] = ['name' => $this->officeAttribute($property, 'name')];
            }
        }

        return $properties;
    }

    /** @return array<string,mixed> */
    private function docxStyleMetadata(ZipArchive $zip, array &$truncations): array
    {
        $xml = $zip->getFromName('word/styles.xml');
        $styles = [];
        if (is_string($xml)) {
            [, $xpath] = $this->officeXml($xml, 'DOCX', 'word/styles.xml', ['w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main']);
            foreach ($xpath->query('//w:style') as $style) {
                if ($style instanceof \DOMElement) {
                    $name = $xpath->query('./w:name', $style)->item(0);
                    $styles[] = [
                        'id' => $this->officeAttribute($style, 'styleId'),
                        'type' => $this->officeAttribute($style, 'type'),
                        'name' => $this->officeAttribute($name, 'val'),
                    ];
                }
            }
        }

        return [
            'supported_presentation_operations' => ['wording', 'alignment', 'spacing', 'wrapping', 'safe_font_size', 'table_presentation'],
            'available_styles' => $this->boundedSchemaNodes($styles, $truncations, 'style_edit_metadata.available_styles'),
        ];
    }

    /** @return array<string,mixed> */
    private function xlsxStyleMetadata(ZipArchive $zip, array &$truncations): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        $counts = ['fonts' => 0, 'fills' => 0, 'borders' => 0, 'cell_formats' => 0];
        if (is_string($xml)) {
            [, $xpath] = $this->officeXml($xml, 'XLSX', 'xl/styles.xml', ['x' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main']);
            $counts = [
                'fonts' => $xpath->query('//x:fonts/x:font')->length,
                'fills' => $xpath->query('//x:fills/x:fill')->length,
                'borders' => $xpath->query('//x:borders/x:border')->length,
                'cell_formats' => $xpath->query('//x:cellXfs/x:xf')->length,
            ];
        }

        return [
            'supported_presentation_operations' => ['wording', 'alignment', 'wrapping', 'row_height', 'column_width', 'table_presentation'],
            'style_counts' => $counts,
        ];
    }

    /** @return array<string,string> */
    private function xlsxRelationships(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! is_string($xml)) {
            throw ValidationException::withMessages(['template_file' => 'The uploaded XLSX is missing workbook relationships.']);
        }
        [, $xpath] = $this->officeXml($xml, 'XLSX', 'xl/_rels/workbook.xml.rels', ['r' => 'http://schemas.openxmlformats.org/package/2006/relationships']);
        $relationships = [];
        foreach ($xpath->query('//r:Relationship') as $relationship) {
            if ($relationship instanceof \DOMElement) {
                $relationships[$this->officeAttribute($relationship, 'Id')] = $this->officeAttribute($relationship, 'Target');
            }
        }

        return $relationships;
    }

    private function xlsxTargetPath(string $base, string $target): string
    {
        $target = str_replace('\\', '/', $target);
        $baseDirectory = str_replace('\\', '/', dirname($base));
        $segments = [];
        foreach (explode('/', $baseDirectory.'/'.$target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /** @param list<array<string,mixed>> $ranges */
    private function namedRangeReference(array $ranges, string $name, string $worksheet): string
    {
        foreach ($ranges as $range) {
            $reference = (string) ($range['reference'] ?? '');
            $worksheetReference = preg_quote($worksheet, '/');
            if (($range['name'] ?? '') === $name && preg_match("/(?:'{$worksheetReference}'|{$worksheetReference})!/", $reference) === 1) {
                return $reference;
            }
        }

        return '';
    }

    private function safeNamedRangeReference(string $formula): ?string
    {
        $reference = trim(ltrim(trim($formula), '='));
        $sheetReference = "(?:'(?:[^']|'')+'|[A-Za-z_][A-Za-z0-9_. ]*)!\\$?[A-Z]{1,3}\\$?\\d+(?::\\$?[A-Z]{1,3}\\$?\\d+)?";

        return preg_match('/^'.$sheetReference.'(?:,'.$sheetReference.')*$/', $reference) === 1
            ? $this->boundedText($reference, 300)
            : null;
    }

    /** @param list<array<string,mixed>> $ranges @return list<array<string,mixed>> */
    private function runtimeNamedRanges(array $ranges): array
    {
        return array_values(array_filter($ranges, fn (array $range): bool => ! str_starts_with(strtolower((string) ($range['name'] ?? '')), '_xlnm.')));
    }

    /** @param array<string,mixed> $schema */
    private function assertValidDynamicSchema(array $schema, string $format): void
    {
        if (($schema['schema_version'] ?? null) !== 1 || ($schema['source']['format'] ?? null) !== $format) {
            throw ValidationException::withMessages(['template_file' => 'The uploaded Office layout produced an invalid document schema.']);
        }
        foreach (['office_identity', 'document_structure', 'regions', 'resolver', 'compatibility_warnings'] as $key) {
            if (! is_array($schema[$key] ?? null)) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded Office layout produced an incomplete document schema.']);
            }
        }
        foreach ($schema['compatibility_warnings'] as $warning) {
            if (! is_array($warning) || ! in_array($warning['severity'] ?? null, self::SCHEMA_WARNING_SEVERITIES, true)) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded Office layout produced an invalid compatibility warning.']);
            }
        }
    }

    /** @return array{0:ZipArchive,1:string} */
    private function openZip(string $bytes, string $format): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['template_file' => "$format validation requires the PHP ZIP extension."]);
        }
        $path = tempnam(sys_get_temp_dir(), 'spmu-layout-');
        if ($path === false) {
            throw ValidationException::withMessages(['template_file' => 'Could not create a temporary layout-validation file.']);
        }
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw ValidationException::withMessages(['template_file' => "The uploaded $format layout is unreadable or corrupt."]);
        }
        try {
            $this->assertSafeOfficeArchive($zip, $format);
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($path);

            throw $exception;
        }
        return [$zip, $path];
    }

    private function assertSafeOfficeArchive(ZipArchive $zip, string $format): void
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw ValidationException::withMessages(['template_file' => "The uploaded $format contains too many internal files to inspect safely."]);
        }
        $uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_starts_with($name, '/') || str_contains(str_replace('\\', '/', $name), '../')) {
                throw ValidationException::withMessages(['template_file' => "The uploaded $format contains an unsafe Office package path."]);
            }
            $uncompressed += max(0, (int) ($stat['size'] ?? 0));
            if ($uncompressed > self::MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                throw ValidationException::withMessages(['template_file' => "The uploaded $format expands beyond the safe inspection limit."]);
            }
        }
    }
}
