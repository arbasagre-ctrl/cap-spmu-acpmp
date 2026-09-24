# ICTU Deployment and Operations

## Production architecture

The supplied stack contains:

- `app`: PHP 8.4, Apache, Laravel 13.
- `database`: MariaDB 11.4 with a persistent volume.
- `scheduler`: Laravel scheduler for due-soon, approval expiry, overdue, tariff, sanction, and notice processing.
- shared protected application-storage volume.

The production image includes LibreOffice Writer/Calc for the approved DOCX/XLSX document runtime. Its entrypoint runs `package:discover`, `config:cache`, `route:cache`, and `view:cache` whenever a container starts. Do not repeat those cache commands during normal deployment; use `optimize:clear` only for directed troubleshooting.

Place an ICTU-managed HTTPS reverse proxy/load balancer in front of the app. Do not expose MariaDB publicly.

## Production environment register

Copy `.env.docker.example` to the protected, untracked `.env.docker` file. Do not place real values in source control, screenshots, tickets, or chat.

| Variable group | Status | Production requirement |
|---|---|---|
| `APP_KEY` | Required | Generate with `php artisan key:generate --show`; preserve it securely because replacing it invalidates encrypted application state. |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, timezone | Required/environment-specific | Use `production`, `false`, the approved HTTPS URL, and `Asia/Manila`. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `MARIADB_*` | Required | Use the internal MariaDB service and ICTU-managed credentials. Database access must not be public. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | Required only when enabling Google sign-in | Register the exact HTTPS callback `${APP_URL}/auth/google/callback` in Google Cloud Console. Missing values keep Google sign-in unavailable; local account authorization remains unchanged. |
| `GOOGLE_ALLOWED_DOMAINS` | Required deployment policy when Google sign-in is enabled | Set to `cspc.edu.ph` for CSPC production. The variable supports a comma-separated list only when ICTU has an approved institutional exception. |
| `MAIL_*` | Required for production email delivery | Use the approved institutional SMTP service and sender identity. |
| `SEED_DEMO_USERS` | Required | Set `false` in production. |
| `RUN_MIGRATIONS` | Environment-specific | `true` lets the entrypoint run migrations and idempotent master/reference seeders; set `false` only for a planned maintenance/recovery procedure. |

The Google OAuth domain allow-list is an additional CSPC production control. It does not create accounts or assign roles: Google proves identity, while the pre-existing active local user record still controls access.

## Initial deployment

1. Install Docker Engine/Desktop and Docker Compose on the approved server.
2. Copy `.env.docker.example` to `.env.docker`.
3. Generate an application key locally with `.\tools\artisan.cmd key:generate --show` and place the entire result in `APP_KEY`.
4. Replace every placeholder password, URL, SMTP value, and host. Set `APP_DEBUG=false`, `APP_ENV=production`, `SEED_DEMO_USERS=false`, and the real HTTPS `APP_URL`. If Google sign-in is enabled, set all four `GOOGLE_*` values and register the exact callback URI with Google; use `GOOGLE_ALLOWED_DOMAINS=cspc.edu.ph`.
5. Protect `.env.docker` with operating-system permissions; never commit or email it.
6. Start the stack:

```powershell
docker compose up -d --build
docker compose ps
```

With `RUN_MIGRATIONS=true`, the app container applies migrations and idempotent reference/master seeders, including the organizational master data. Seeders do not overwrite later inventory/configuration changes. The same entrypoint has already refreshed configuration, route, and view caches.

7. Create the first real ICTU account:

```powershell
docker compose exec app php artisan spmu:user ictu.admin@cspc.edu.ph --name="ICTU Administrator" --employee-no="REAL-EMPLOYEE-NO" --unit=ICTU --role=ICTU
```

The command securely prompts for a 12+ character password with uppercase, lowercase, number, and symbol.

8. Sign in and create only the approved Borrower, SPMU Admin / Head, SPMU Action Officer, and ICTU accounts. GSU/VPAF do not receive application accounts; their request-letter signatures are obtained physically outside the system.
9. Reconcile opening inventory and all values in the Configuration Register before production acceptance.

## Google OAuth and email

Google OAuth is optional. When enabled, use only the environment variables above; never hardcode a client secret in PHP or configuration files. The Google Cloud Console redirect must exactly match `GOOGLE_REDIRECT_URI`, and production CSPC accounts must be limited with `GOOGLE_ALLOWED_DOMAINS=cspc.edu.ph`.

Laravel uses the configured SMTP transport for email. Delivery attempts and responses appear in the notification report.

## Scheduled operations

Confirm the scheduler container stays healthy. Manual verification:

```powershell
docker compose exec app php artisan spmu:process-deadlines
```

Run `docker compose logs scheduler` when notices, expiry, or overdue processing appears delayed.

## Backup and recovery

The web **Download local database backup** button applies only to SQLite development. Production uses MariaDB-native backup managed by ICTU. Follow the [Backup and Restore Runbook](BACKUP-RESTORE-RUNBOOK.md) for the manual database plus protected/generated-storage procedure, integrity checks, and isolated restore rehearsal.

No automated backup service is included in this deployment architecture. `backup_schedule` remains `NOT_FINALIZED` until ICTU/client confirmation defines frequency, retention, destination, encryption/key ownership, off-site storage, RPO, RTO, responsible operator, and rehearsal schedule.

## Update procedure

1. Take and verify a recoverable backup.
2. Review code/migration changes and test in staging.
3. Run the supported test image and code-quality checks in staging before packaging.
4. Deploy with:

   ```powershell
   docker compose up -d --build --force-recreate app scheduler
   docker compose ps
   docker compose exec app php artisan migrate:status
   ```

   `--force-recreate` is mandatory, not optional. The `app` and `scheduler` containers run a baked image with no live source mount: after any code change, `docker compose up -d --build` alone can leave a stale container running against a newer image without visibly failing. A verified incident (a stale image left `spmu:process-deadlines` failing on every scheduled run for over a week with no symptom besides accumulating log errors) confirms this is a real, not theoretical, risk. Confirm `docker compose ps` shows both containers freshly recreated and healthy, and that `migrate:status` shows no pending migration, before continuing.
5. With `RUN_MIGRATIONS=true`, the entrypoint performs the required migration/seed and cache refresh steps automatically on container start; the `migrate:status` check above confirms this completed.
6. Check `/up`, sign-in, scheduler and queue logs, database health, protected-storage write access, LibreOffice document runtime, email, and one non-destructive role test.
7. Record the release and validation as an ICTU technical operation/change record.

## Security checklist

- HTTPS only; HSTS is emitted in production HTTPS requests.
- Keep APP_KEY, database, and SMTP secrets outside source control.
- Use named employee accounts; no shared production passwords.
- Disable departed/ineligible accounts immediately and review roles regularly.
- Preserve the login throttle, CSRF checks, secure session settings, security headers, protected storage, and audit trail.
- Grant database/application filesystem access only to the runtime and designated ICTU operators.
- Monitor failed logins at the edge, failed deliveries, application exceptions, database capacity, scheduler health, storage capacity, and backup outcomes.
- Do not manually update finalized business tables. Authorized corrections must use a documented, audited procedure.

## Health and troubleshooting

- Application health: `https://your-approved-host/up`.
- Container state: `docker compose ps`.
- App logs: `docker compose logs app`.
- Scheduler logs: `docker compose logs scheduler`.
- Queue logs: `docker compose logs queue`.
- Database logs: `docker compose logs database`.

Test local SQLite and production MariaDB separately before institutional acceptance; different database engines can expose constraint or date-query differences.
