# Requirements Traceability Summary

| Final requirement area | Primary implementation | Verification |
|---|---|---|
| Single-portal identity and only Borrower may borrow | AccessClassification, EnsureActiveWorkspace, role assignment service | AccessControlTest, RoleWorkspaceSmokeTest, SinglePortalRoleIsolationTest |
| Active roles limited to Borrower/SPMU/Laundry/ICTU | RoleSeeder, DemoUserSeeder, UserAdministrationController, retirement migrations | FoundationTest, SinglePortalRoleIsolationTest |
| GSU/VPAF physical-signatory-only model | printable request-letter template, retired legacy classifications/roles | BorrowingRequestLetterPdfTest, SinglePortalRoleIsolationTest |
| Printable request letter + signed scan upload | BorrowingRequestController, DocumentService, RequestSupportingDocument | BorrowingRequestLetterPdfTest, BatchOneReliabilityTest |
| Permission to Conduct for student activity | request supporting-document validation | SpmuDocumentVerificationTest |
| SPMU-only in-system verification | RequestWorkflowService, ApprovalController, SPMU checklist UI | SpmuDocumentVerificationTest, CompleteWorkflowTest |
| Exact reservation/availability conflict control | InventoryService transaction/locking | CompleteWorkflowTest |
| Pickup/custody creation and exact preparation | CustodyService, CustodyController | CompleteWorkflowTest, SpmuRoleSeparationTest |
| Physical signatures; no active e-signature | profile/routes cleanup, null legacy snapshot fields, physical form text | AccountSettingsRoleTest, workflow tests |
| Gate Pass for applicable off-campus property | DocumentService/CustodyService/evidence workflow | BorrowingRequestLetterPdfTest, BatchOneReliabilityTest |
| Linen-only Laundry portal/workflow | LaundryJob/LaundryJobLine, LaundryController, Laundry views | SimpleLaundryWorkflowTest |
| Cancellation and Early Return | RequestWorkflowService, EarlyReturnRequest, SPMU physical inspection | RevisionControlsTest |
| Returns/incidents/accountability | custody/return/incident/billing services and controllers | CompleteWorkflowTest |
| Notifications | inbox, SMTP, delivery evidence | workflow and role smoke tests |
| Reports/audit | ReportController and AuditService | role/page tests |
| Docker/security/deployment | Docker stack, scheduler, headers, throttling, protected storage | ProtectedFilePreviewTest and deployment checks |

Legacy GSU/VPAF database values are retained only for historical compatibility. They do not create active portals, queues or approval authority.
