# Client Workflow Final — 2 September 2026

This document is the current implementation baseline for the `client-workflow-update` branch.

## Active application access

- Borrower — only classification allowed to borrow.
- SPMU Admin / Head — final decision authority and oversight after Action Officer verification.
- SPMU Action Officer — request/document verification, pickup, exact preparation, release/return, laundry operations and evidence; final SPMU decision only with valid delegation.
- ICTU Maintainer — accounts, configuration, audit and technical operations.

## GSU / VPAF treatment

GSU and VPAF are **not application roles**. They do not receive portal access, approval queues, user-account assignments or electronic-signature actions. Their names remain only where needed for:

1. physical Borrowing Request Letter signatory blocks; and
2. historical database compatibility for older records.

Old GSU/VPAF account records are deactivated and hidden from active User Administration instead of being deleted, preserving audit/foreign-key history.

## Borrowing request

1. Borrower saves a Draft.
2. System generates printable Draft Borrowing Request Letter.
3. Borrower prints it and obtains the required handwritten/wet signatures.
4. Borrower scans and uploads the fully signed letter; Permission to Conduct is also uploaded for applicable student activity/organization requests.
5. Borrower submits to SPMU.
6. SPMU Action Officer verifies the uploaded scan and request details or returns an incomplete request for correction. Verification is not approval and creates no reservation.
7. A `VERIFIED` request is routed to the SPMU Head for the separate Approve, Reject, or Return for Revision decision.
8. Only Head approval reserves the exact quantity and creates the operational documents.

## Pickup / release

- Custody/pickup record is created immediately after SPMU approval/reservation.
- System automatically generates the Borrower Slip and applicable Gate Pass after Head approval, never before it.
- Borrower views/downloads both applicable documents and brings them to SPMU on the scheduled pickup date.
- SPMU Action Officer schedules pickup, validates the approved/generated documents, and prepares the exact approved quantity.
- Signatures on these forms are physical handwritten/wet signatures.
- SPMU records physical handover and moves custody to Released / On Custody; no duplicate Gate Pass request is created.

## Linen / Laundry Operations

When released custody contains a laundry-required linen item:

1. a Laundry Job appears under SPMU Action Officer Laundry Operations;
2. borrower brings used linen and the physical Laundry Form to Laundry after use;
3. the SPMU Action Officer records turnover and laundry processing details;
4. cleaned linen and the same form continue directly into the existing SPMU Return & Inspection flow;
5. the authorized SPMU signatory completes final acceptance where required, and the Action Officer archives the accomplished form;
6. linen returns to Available only through final SPMU physical return completion.

## Compatibility

Legacy enum/status/schema fields remain where removing them would break historical records. They are not exposed as current portals or workflow stages.
