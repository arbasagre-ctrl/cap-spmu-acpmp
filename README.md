# SPMU-ACPMP

SPMU Asset Custody and Performance Monitoring Program is a Laravel 13 system for CSPC property borrowing, custody, return, laundry handling, accountability, reporting, and ICTU administration.

## Final active access model

The application has one requester portal and three active staff classifications:

| Access | Purpose |
|---|---|
| Borrower | Creates borrowing requests and tracks personal requests/custody. Only Borrower accounts may borrow. |
| SPMU Admin / Head | SPMU verification/decision authority, oversight, reports, and authorized controls. |
| SPMU Action Officer | Pickup scheduling, exact-quantity preparation, physical release/return, laundry operations, evidence and operational processing. May make an SPMU decision only under a valid formal delegation. |
| ICTU Maintainer | User accounts, classifications, system settings, audit trail, delivery records, deployment and technical operations. |

**GSU and VPAF are not application roles, portals, approval queues, or system approvers.** When required by the institutional Borrowing Request Letter, they are **physical signatories only**. The borrower prints the letter, obtains the required handwritten/wet signatures, scans the accomplished document, and uploads it to SPMU for verification.

## Final borrowing workflow

1. Borrower checks available/serviceable inventory and creates a Draft request.
2. The system generates a printable Draft Borrowing Request Letter.
3. Borrower prints the letter and obtains required handwritten/wet signatures, including the required GSU/VPAF institutional signatories on the physical letter.
4. Borrower scans and uploads the fully signed Borrowing Request Letter. A Permission to Conduct document is also required when the request represents a student activity/organization.
5. SPMU opens the submitted scan beside the verification checklist and chooses **Verify & Approve**, **Return for Revision**, or **Reject**.
6. Approval performs the final availability check and reserves the exact approved quantity. No GSU/VPAF in-system stage follows SPMU.
7. SPMU Action Officer schedules pickup, confirms the exact approved quantity, and generates the Borrower Slip plus only the applicable physical Gate Pass and/or Laundry Form.
8. Required signatures on operational forms are handwritten/wet signatures. The system records process confirmation/evidence; it does not create electronic-signature snapshots.
9. SPMU records physical release and return. Off-campus Gate Pass and linen Laundry Form evidence are uploaded/verified as required.
10. When released custody includes linen, a Laundry Job is handled by the SPMU Action Officer, who records turnover and processing, continues the linen through final SPMU return inspection, and archives the accomplished signed form. Linen becomes available only through that existing return flow.

## Other implemented controls

- Single-portal role isolation and cross-portal 403 protection.
- Formal, time-bound SPMU delegation using the delegate's own account.
- Date-aware inventory availability and borrowing calendar.
- Exact reservation/allocation with database locking and no silent quantity reduction.
- Cancellation/Early Return controls and item-level physical return inspection.
- Damage, destruction, missing, lost, stolen, evidence, billing, sanctions and restrictions.
- In-system notifications, SMTP email, configurable SMS webhook and delivery records.
- Reports, CSV exports, audit history, security headers, login throttling and protected file storage.
- Docker/MariaDB application, database and scheduler services.

## Docker local start

Use Docker rather than XAMPP for the project runtime. From PowerShell in the project root:

```powershell
docker compose up -d --build
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --force
docker compose exec -T app php artisan optimize:clear
```

Open:

```text
http://127.0.0.1:8080
```

Local demo password: `SPMU-Demo-2026!`

| Demo access | Email |
|---|---|
| Borrower | `borrower@spmu.test` |
| SPMU Action Officer | `spmu@spmu.test` |
| SPMU Admin / Head | `spmu-head@spmu.test` |
| ICTU Maintainer | `ictu@spmu.test` |

There are intentionally **no GSU or VPAF login accounts** in the current demo seeder.

## Test suite

```powershell
docker compose -p spmu-acpmp-test -f .\docker-compose.test.yml build --no-cache test

docker compose -p spmu-acpmp-test -f .\docker-compose.test.yml run --rm test php vendor/bin/phpunit --testdox
```

## Source map

- `app/Http/Controllers` — role portals and form actions.
- `app/Services` — request workflow, inventory, custody, documents, notifications, files and audit logic.
- `app/Models` — business records and relationships.
- `database/migrations` — relational schema and workflow revisions.
- `database/seeders` — reference data and optional demo accounts.
- `resources/views` / `public/css/app.css` — responsive interface.
- `routes/web.php` — protected application routes.
- `tests` — executable regression/acceptance coverage.
- `docker-compose.yml` / `docker-compose.test.yml` — application and test runtimes.

Legacy GSU/VPAF enum/database values may remain only so historical records do not break. They are disabled from assignment and hidden from active user administration.

For the explicit finalized client baseline, see [docs/CLIENT-WORKFLOW-FINAL.md](docs/CLIENT-WORKFLOW-FINAL.md).
