# Dashboard Accountability Corrections Design

## Goal

Make every affected dashboard KPI open the exact records it counts, give every accountability surface one case identity, and complete Head/Admin review information for current RSLDDP settlement without changing approved Analytics, Inventory, compliance, or historical workflows.

## Decisions

### Canonical accountability case identity

- An `Incident` is one property-accountability case, keyed as `incident:{id}`.
- An `OverdueCase` is one late-return case, keyed as `overdue:{id}`.
- The two remain distinct even when their `custody_transaction_id` matches.
- A linked billing statement, restriction, payment, or document is a detail of its parent case and never creates a second case.
- A billing or restriction can be a standalone case only when it has no link to any visible Incident or OverdueCase.

The dashboard accountability count, Accountability summary card, case-table chip, visible current rows, and borrower obligations will use this definition. No case will be deduplicated merely by custody id.

### KPI destinations

Card URLs will use a validated, server-consumed `kpi` query value. Direct visits without a `kpi` value retain the existing All view. The destination controller or page will apply the corresponding existing predicate before rendering, rather than relying on a decorative URL or client-side default filter.

Covered scopes are Borrower Active Borrowings, Upcoming Pickup, Returns Due; Head/Admin Active Custodies and Active Restrictions; and ICTU Active Accounts, Inactive Accounts, Failed Notifications, and Active Delegations.

### RSLDDP payment evidence

At `RSLDDP_PAYMENT_REQUIRED` and `RSLDDP_FOR_RESOLUTION`, the incident case will display confirmed payments belonging to the linked official billing: receipt/reference number, payment date, amount, recorder, timestamp, and the existing protected-file preview link. AO continues to create and confirm the existing payment record; no new evidence storage is introduced.

### Documents and historical compatibility

Current late-return billings will render as **Late Return Billing Statement** based on their linked overdue/penalty context. Generic legacy property billing retains its established historical title. Already-generated documents are not regenerated or modified.

`COMPLIANCE_REQUIRED`, `COMPLIANCE_RSLDDP_PENDING`, current repair/replacement flows, historical Compliance Notices, historical Written Reprimands, late-return AO-confirmation rows, `FOR_BILLING`, `BILLING_PENDING`, and historical generic-property download actions remain supported.

### Authorization

The Head/Admin Administration page will no longer expose the ICTU-only user-management link. User Administration authorization remains ICTU-only. Existing broad ICTU technical-support file/document authorization is not changed; it is recorded as a policy decision outside this implementation.

## Testing

Feature tests will cover canonical case counting for incident-only, overdue-only, linked billing/restriction details, concurrent incident plus overdue on one custody, and genuine standalone legacy records. They will also cover multi-linked historical records, KPI URL scope application, Head receipt visibility, document-title context, borrower overdue routing, and Head navigation authorization.

Verification uses the project Docker test environment, followed by application and scheduler rebuild, migration inspection/application if required, and an HTTP 200 check of `/up`.
