# Run SPMU-ACPMP in Visual Studio Code

The project is designed to run with Docker and MariaDB. XAMPP, WAMP and Laragon are not required.

## 1. Open the project and branch

In Visual Studio Code open the `SPMU-ACPMP` folder. In PowerShell:

```powershell
git branch --show-current
git status
```

The finalized client workflow is on:

```text
client-workflow-update
```

## 2. Start Docker

Start Docker Desktop, then from the project root:

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

## 3. Local demo accounts

All demo accounts use `SPMU-Demo-2026!`.

| Portal | Email |
|---|---|
| Borrower | `borrower@spmu.test` |
| SPMU Action Officer | `spmu@spmu.test` |
| SPMU Admin / Head | `spmu-head@spmu.test` |
| ICTU Maintainer | `ictu@spmu.test` |

GSU and VPAF intentionally have no system login/portal. Their required signatures are handwritten/wet signatures on the physical Borrowing Request Letter before the borrower uploads the accomplished scan to SPMU.

## 4. Run tests

```powershell
docker compose -p spmu-acpmp-test -f .\docker-compose.test.yml build --no-cache test

docker compose -p spmu-acpmp-test -f .\docker-compose.test.yml run --rm test php vendor/bin/phpunit --testdox
```

## 5. Team Git workflow

Review the working tree, then push the finalized branch:

```powershell
git status
git log -1 --oneline
git push -u origin client-workflow-update
```

Never commit `.env`, `.env.docker`, credentials, uploaded evidence, generated runtime files, or database volumes.

## 6. Important source folders

- `app/Http/Controllers` — role pages and actions.
- `app/Services` — request, inventory, custody, document, notification and audit rules.
- `resources/views` — role-specific UI.
- `routes/web.php` — protected routes.
- `database/migrations` — MariaDB/MySQL-compatible database changes.
- `database/seeders` — reference data and optional demo identities.
- `tests/Feature` — access/workflow regression tests.
- `docker-compose.yml` — application/database/scheduler runtime.
- `docker-compose.test.yml` — isolated PHPUnit runtime.
