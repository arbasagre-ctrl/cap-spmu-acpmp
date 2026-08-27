# ICTU Deployment and Operations

## Production architecture

The supplied stack contains:

- `app`: PHP 8.4, Apache, Laravel 13.
- `database`: MariaDB 11.4 with a persistent volume.
- `scheduler`: Laravel scheduler for due-soon, approval expiry, overdue, tariff, sanction, and notice processing.
- shared protected application-storage volume.

Place an ICTU-managed HTTPS reverse proxy/load balancer in front of the app. Do not expose MariaDB publicly.

## Initial deployment

1. Install Docker Engine/Desktop and Docker Compose on the approved server.
2. Copy `.env.docker.example` to `.env.docker`.
3. Generate an application key locally with `.\tools\artisan.cmd key:generate --show` and place the entire result in `APP_KEY`.
4. Replace every placeholder password, URL, SMTP value, and host. Set `APP_DEBUG=false`, `APP_ENV=production`, `SEED_DEMO_USERS=false`, and the real HTTPS `APP_URL`.
5. Protect `.env.docker` with operating-system permissions; never commit or email it.
6. Start the stack:

```powershell
docker compose up -d --build
docker compose ps
```

The app container applies migrations and idempotent reference seeders. Seeders do not overwrite later inventory/configuration changes.

7. Create the first real ICTU account:

```powershell
docker compose exec app php artisan spmu:user ictu.admin@cspc.edu.ph --name="ICTU Administrator" --employee-no="REAL-EMPLOYEE-NO" --unit=ICTU --role=ICTU
```

The command securely prompts for a 12+ character password with uppercase, lowercase, number, and symbol.

8. Sign in and create only the approved Borrower, SPMU Admin / Head, SPMU Action Officer, and ICTU accounts. GSU/VPAF do not receive application accounts; their request-letter signatures are obtained physically outside the system.
9. Reconcile opening inventory and all values in the Configuration Register before production acceptance.

## Email and SMS

Laravel uses the configured SMTP transport for email. For SMS, configure `SMS_PROVIDER`, `SMS_WEBHOOK_URL`, and `SMS_API_TOKEN`. The webhook receives JSON fields `to`, `message`, and `event_code`, with an optional bearer token. ICTU must adapt or proxy this generic contract to the approved SMS provider. Delivery attempts and responses appear in the notification report.

## Scheduled operations

Confirm the scheduler container stays healthy. Manual verification:

```powershell
docker compose exec app php artisan spmu:process-deadlines
```

Run `docker compose logs scheduler` when notices, expiry, or overdue processing appears delayed.

## Backup and recovery

The web **Download local database backup** button applies only to SQLite development. Production uses MariaDB-native backup managed by ICTU.

At minimum, the approved plan must define:

- encrypted daily database dumps and regular full volume snapshots;
- protected-file storage backup in the same recovery point;
- separate off-site copy and retention periods;
- restricted backup credentials and access logs;
- quarterly restore rehearsal into an isolated environment;
- recorded recovery point objective, recovery time objective, result, checksum, and responsible ICTU user.

Never claim a backup is valid until a restore test has succeeded. Database and protected-file storage must be restored to the same recovery point so document hashes and evidence references remain consistent.

## Update procedure

1. Take and verify a recoverable backup.
2. Review code/migration changes and test in staging.
3. Run `.\tools\artisan.cmd test` and Pint before packaging.
4. Deploy with `docker compose up -d --build`.
5. Check `/up`, sign-in, scheduler logs, database health, storage write access, email, SMS, and one non-destructive role test.
6. Record the release and validation as an ICTU technical operation/change record.

## Security checklist

- HTTPS only; HSTS is emitted in production HTTPS requests.
- Keep APP_KEY, database, SMTP, and SMS secrets outside source control.
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
- Database logs: `docker compose logs database`.

Test local SQLite and production MariaDB separately before institutional acceptance; different database engines can expose constraint or date-query differences.
