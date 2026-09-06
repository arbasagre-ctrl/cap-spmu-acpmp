<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class DocumentTemplateDefinitionService
{
    /**
     * Editable text only. Dynamic transaction values are intentionally absent
     * from this list so SPMU Admin cannot accidentally replace/remove them.
     */
    private const DEFINITIONS = [
        'BORROWER_SLIP' => [
            'label' => "Borrower's Slip Template",
            'defaults' => [
                'form_code' => 'CSPC-F-SPMU-26',
                'title' => "BORROWER'S SLIP",
                'reference_label' => 'Reference No.:',
                'request_label' => 'Request No.:',
                'recipient_name' => 'ANGELICA P. REGONDOLA, PhD',
                'recipient_position' => 'Administrative Officer V, Supply Officer III',
                'recipient_institution' => 'This Institution',
                'date_label' => 'Date:',
                'salutation' => "Ma'am:",
                'intro_prefix' => 'I have the honor to borrow the equipment indicated hereunder which will be used for',
                'intro_suffix' => 'It is understood that I shall be held responsible for said items while in my possession until officially returned on',
                'qty_label' => 'QTY.',
                'unit_label' => 'UNIT',
                'description_label' => 'ARTICLE/DESCRIPTION',
                'receipt_signature_label' => "BORROWER'S SIGNATURE UPON RECEIPT OF ITEMS",
                'remarks_heading' => 'Remarks upon return of items',
                'closing' => 'Very truly yours,',
                'borrower_signature_caption' => 'Signature over Printed Name',
                'designation_label' => 'Designation',
                'approved_label' => 'APPROVED:',
                'footer_effectivity' => 'August 2025',
                'footer_revision' => 'Rev. 3',
            ],
        ],
        'LAUNDRY_FORM' => [
            'label' => 'Laundry Form Template',
            'defaults' => [
                'form_code' => 'CSPC-F-SPMU-62',
                'title' => 'REQUEST AND COMPLETION FOR LAUNDRY SERVICES',
                'requesting_office_label' => 'Requesting Office:',
                'request_no_label' => 'Request No.:',
                'qty_label' => 'QTY',
                'unit_label' => 'UNIT',
                'description_label' => 'DESCRIPTION',
                'date_requested_label' => 'DATE REQUESTED',
                'date_completed_label' => 'DATE COMPLETED',
                'requested_by_label' => 'Requested by:',
                'approved_by_label' => 'Approved By:',
                'issued_by_label' => 'Issued by:',
                'received_by_label' => 'Received by:',
                'signature_label' => 'Signature',
                'printed_name_label' => 'Printed Name',
                'designation_label' => 'Designation',
                'date_label' => 'Date',
                'footer_effectivity' => 'December 2025',
                'footer_revision' => 'Rev. 2',
            ],
        ],
        'GATE_PASS' => [
            'label' => 'Gate Pass Template',
            'defaults' => [
                'form_code' => 'CSPC-F-SPMU',
                'gp_no_label' => 'GP No.',
                'date_label' => 'Date:',
                'title' => 'GATE PASS',
                'to_label' => 'TO:',
                'to_value' => 'Security Guard on Duty',
                'intro_prefix' => 'Please allow the bearer',
                'intro_suffix' => 'whose signature appears below to bring out of the CSPC premises the articles listed below.',
                'quantity_label' => 'Quantity',
                'unit_label' => 'Unit',
                'description_label' => 'Description',
                'purpose_label' => 'Purpose:',
                'remarks_label' => 'Remarks:',
                'bearer_label' => 'Name & Signature of Bearer (Accountable Person)',
                'verified_by_label' => 'Verified By:',
                'verified_role' => 'SPMU Action Officer',
                'approved_by_label' => 'Approved By:',
                'approved_role' => 'Head, Supply and Property Management Unit',
                'released_by_label' => 'Released by:',
                'guard_role' => 'Guard on Duty',
                'released_date_label' => 'Date:',
                'released_time_label' => 'Time:',
                'footer_effectivity' => 'August 2025',
                'footer_revision' => 'Rev. 3',
            ],
        ],
    ];

    public function supports(string $type): bool
    {
        return isset(self::DEFINITIONS[$this->normalize($type)]);
    }

    public function definition(string $type): array
    {
        $type = $this->normalize($type);
        abort_unless(isset(self::DEFINITIONS[$type]), 404);

        return self::DEFINITIONS[$type];
    }

    public function defaults(string $type): array
    {
        return $this->definition($type)['defaults'];
    }

    public function resolve(string $type): array
    {
        /*
         * The former browser-based system editor is intentionally inert.
         * Historical SYSTEM_EDITOR content is retained in the database for
         * audit only and cannot change a future controlled document.
         */
        return $this->defaults($type);
    }

    public function sanitize(string $type, array $input): array
    {
        $defaults = $this->defaults($type);
        $result = [];
        $errors = [];

        foreach ($defaults as $key => $default) {
            $value = trim((string) ($input[$key] ?? $default));
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

            if ($value === '') {
                $errors["template_config.{$key}"] = 'This template text cannot be blank.';
                continue;
            }

            if (mb_strlen($value) > 1000) {
                $errors["template_config.{$key}"] = 'This template text is too long.';
                continue;
            }

            $result[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    private function normalize(string $type): string
    {
        return strtoupper(str_replace('-', '_', trim($type)));
    }
}
