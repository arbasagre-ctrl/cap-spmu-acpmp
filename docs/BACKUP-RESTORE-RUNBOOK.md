# Backup and Restore Runbook

## Scope and status

This runbook covers the production MariaDB database and `storage/app/private`. The latter includes protected uploads, evidence, and generated documents. They must be captured and restored from the same recovery point because the database stores their paths and hashes.

The web SQLite download action is for local development only. The supplied Docker stack has no automated backup service, cron job, or backup destination. `backup_schedule` remains `NOT_FINALIZED`; this manual procedure does not choose a schedule or retention policy.

Keep `.env.docker` and its `APP_KEY` under separate ICTU secret-management controls. Do not copy that secret file into a routine backup set unless the approved encryption/key-ownership policy explicitly permits it.

## ICTU/client decisions required before operations

| Decision | Status |
|---|---|
| Frequency and retention | TBD — ICTU/client approval required |
| Backup destination and off-site copy | TBD — ICTU/client approval required |
| Encryption method and key ownership | TBD — ICTU/client approval required |
| RPO, RTO, and responsible operator | TBD — ICTU/client approval required |
| Restore rehearsal cadence | TBD — ICTU/client approval required |

Until those decisions are recorded, use this only for an approved manual operation and record the result in the ICTU technical operation/change record.

## Manual backup procedure

Run from the production compose-project directory. Replace the sample local path with the ICTU-approved backup destination; it is deliberately not prescribed here.

To keep the database and protected storage at one recovery point, obtain ICTU approval for a brief maintenance/quiescence window first: block application writes at the reverse proxy, confirm no operator is processing a transaction, and stop the scheduler before capturing either component. Keep the `app` and `database` containers available so the protected storage can be copied. Resume the scheduler immediately after the copy and verification commands below.

```powershell
$backupRoot = 'C:\ICTU\REPLACE-WITH-APPROVED-BACKUP-ROOT'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupSet = Join-Path $backupRoot "spmu-acpmp-$stamp"
New-Item -ItemType Directory -Force -Path $backupSet | Out-Null
docker compose ps
docker compose stop scheduler
```

Create a consistent MariaDB logical dump inside the database container, then copy it out. The password remains in the container environment and is not placed on the host command line.

```powershell
docker compose exec -T database sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --single-transaction --routines --events --hex-blob -u "$MARIADB_USER" "$MARIADB_DATABASE" > /tmp/spmu-acpmp-backup.sql'
docker compose cp database:/tmp/spmu-acpmp-backup.sql "$backupSet\database.sql"
docker compose exec -T database rm -f /tmp/spmu-acpmp-backup.sql
```

Copy the protected/generated storage tree from the shared application volume.

```powershell
New-Item -ItemType Directory -Force -Path "$backupSet\protected-storage" | Out-Null
docker compose cp app:/var/www/html/storage/app/private "$backupSet\protected-storage"
$checksums = Get-ChildItem -File -Recurse $backupSet | Get-FileHash -Algorithm SHA256 |
    ForEach-Object { '{0} *{1}' -f $_.Hash, $_.Path.Substring($backupSet.Length).TrimStart('\') }
$checksums | Set-Content -Encoding utf8 "$backupSet\SHA256SUMS.txt"
docker compose start scheduler
docker compose ps
```

Record the backup-set path, timestamp, application release/revision, database name, storage scope, checksum manifest, operator, destination classification, and any encryption/off-site-copy result. Do not commit the backup directory, dump, copied storage, checksum manifest, or metadata to this repository.

## Backup verification

Before calling a backup usable, confirm all of the following:

1. `database.sql`, `protected-storage\private`, and `SHA256SUMS.txt` exist and have non-zero expected content.
2. The checksum manifest is stored with the backup set and is checked again before a restore rehearsal.
3. `docker compose ps` shows the production services healthy after the dump.
4. The backup timestamp and the protected-storage copy are recorded as one recovery point.
5. An isolated restore rehearsal has completed successfully. A dump alone is not a verified backup.

Recalculate the manifest before a rehearsal. `Compare-Object` must produce no output.

```powershell
$actualChecksums = Get-ChildItem -File -Recurse $backupSet |
    Where-Object { $_.Name -ne 'SHA256SUMS.txt' } |
    Get-FileHash -Algorithm SHA256 |
    ForEach-Object { '{0} *{1}' -f $_.Hash, $_.Path.Substring($backupSet.Length).TrimStart('\') }
Compare-Object (Get-Content "$backupSet\SHA256SUMS.txt") $actualChecksums
```

## Isolated restore rehearsal

Never rehearse against the production project name, production MariaDB volume, or live storage volume. Use an isolated working copy, a new Compose project name, fresh volumes, and an isolated `.env.docker` with `RUN_MIGRATIONS=false` while restoring the captured database. Before starting the scheduler, use a rehearsal-safe mail transport so no restored notification work reaches production recipients.

```powershell
$restoreProject = 'spmu-acpmp-restore-REPLACE-WITH-REHEARSAL-ID'
docker compose -p $restoreProject up -d --build database app
docker compose -p $restoreProject cp "$backupSet\database.sql" database:/tmp/spmu-acpmp-restore.sql
docker compose -p $restoreProject exec -T database sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" "$MARIADB_DATABASE" < /tmp/spmu-acpmp-restore.sql'
docker compose -p $restoreProject exec -T database rm -f /tmp/spmu-acpmp-restore.sql
docker compose -p $restoreProject cp "$backupSet\protected-storage\private\." app:/var/www/html/storage/app/private
```

Use an isolated `.env.docker` prepared by ICTU for the rehearsal. It needs the controlled application configuration appropriate to the restored data; do not copy or expose production secrets in this runbook.

Then validate, without changing business data:

```powershell
docker compose -p $restoreProject ps
docker compose -p $restoreProject exec -T app php artisan migrate:status
docker compose -p $restoreProject exec -T app php artisan about
docker compose -p $restoreProject up -d scheduler
docker compose -p $restoreProject logs --tail=100 scheduler
```

Check `/up`, authenticated access using a designated rehearsal account, a protected-file record and its checksum/path, generated-document availability, scheduler startup logs, and database row counts agreed for the rehearsal. Record the actual restore duration, observed recovery point, checksum result, failures, corrective actions, and operator.

## Production recovery warning

Production restoration is a controlled incident/change procedure, not a normal Docker restart. Obtain ICTU authority, preserve the failed-state evidence, identify the approved recovery point, and restore database plus `storage/app/private` together. Do not run destructive volume/database replacement steps until the approved RPO/RTO, destination, encryption/key access, and responsible operator have been confirmed.
