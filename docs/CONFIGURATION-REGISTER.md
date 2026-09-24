# Configuration Register

SPMU/ICTU manage these values from **Administration → Configuration**. Every change requires a reason and records before/after values.

| Key | Initial value | Owner | Operational effect |
|---|---:|---|---|
| `overdue_grace_hours` | `24` | SPMU/client policy | Grace period before Overdue, restriction, sanction, and tariff |
| `daily_overdue_tariff` | unset | Client/SPMU | Daily amount; billing remains blocked until finalized |
| `due_soon_hours` | `24` | SPMU | Due-soon reminder window |
| `rslddp_template_status` | `PROVISIONAL` | Client/SPMU | Set to `APPROVED` only after official content/layout approval; then incident output is generated |
| `max_upload_mb` | `5` | ICTU/SPMU | Maximum protected supporting-document/evidence upload size |
| `backup_schedule` | `NOT_FINALIZED` | ICTU | Documentary record of the approved backup schedule |

## Deployment environment register

Deployment values are not Administration settings and must remain in the protected, untracked `.env.docker` file. See [Deployment and Operations](DEPLOYMENT-ICTU.md) for the required/optional variable register and [Backup and Restore Runbook](BACKUP-RESTORE-RUNBOOK.md) for the manual MariaDB and protected-storage procedure.

`backup_schedule` remains `NOT_FINALIZED` until ICTU/client confirmation records the approved frequency, retention, storage destination, encryption/key ownership, off-site copy, RPO, RTO, and accountable operator. The application does not provide an automated production backup service.

## Values requiring client confirmation

- Opening inventory reconciliation, including the provisional Barricade quantity of six.
- Penalty/tariff amounts and category-specific rules.
- Official RSLDDP content, acronym wording, appraisal fields, signatories, and layout.
- Production backup frequency, retention, encryption/key ownership, destination, off-site copy, RPO, RTO, responsible operator, and restore-test schedule.
- Final institutional report layouts and SLDDRP/RSLDDP naming/content.

The system intentionally does not invent these values. Unset dependencies produce a visible pending/failed state without rolling back valid business transactions.
