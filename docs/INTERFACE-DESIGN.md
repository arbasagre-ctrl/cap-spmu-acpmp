# SPMU-ACPMP Interface Design

## Design direction

The interface uses a compact navy role menu, white work area, clear status badges, focused forms and role-specific actions. Navigation exposes only what the signed-in classification needs.

## Portal menus

### Borrower
- Dashboard
- Available Items
- Borrowing Calendar
- My Requests
- My Borrowings
- Accountability

### SPMU
- Dashboard
- Approval Queue
- All Requests
- Inventory
- Borrowing Calendar
- Release and Return
- Laundry Operations
- Accountability
- Reports
- Configuration (as authorized)

### ICTU
- Dashboard
- User Accounts
- Delegated Approvers
- System Settings
- Audit Trail
- Delivery Records

There are no GSU or VPAF portals. Their names appear only where the physical Borrowing Request Letter identifies required institutional signatories.

## SPMU verification workspace

For a submitted request, SPMU uses a split review screen:

- left: scanned fully signed Borrowing Request Letter (PDF/image preview);
- right: verification checklist and decision controls;
- decisions: Verify & Approve, Return for Revision, Reject.

No e-signature control appears in this workspace.

## Shared usability rules

1. Show only role-authorized tools.
2. Use plain-language actions and concise helper copy.
3. Keep status/action information visible without exposing unnecessary technical metadata.
4. Use responsive tables/cards and accessible focus/labels.
5. Avoid duplicate controls and old workspace-switching UI.
6. Keep physical-document actions explicit: print, wet-sign, scan, upload, verify.

## Protected process rules

UI simplification must not bypass SPMU verification, self-action restrictions, exact reservation, physical preparation/release, Gate Pass/Laundry conditions, return inspection, accountability evidence, delegation attribution or audit records.
