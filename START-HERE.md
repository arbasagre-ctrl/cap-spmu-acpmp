# Start SPMU-ACPMP on Windows

Open the project folder in Visual Studio Code and run the application with Docker Desktop. XAMPP, WAMP and Laragon are not required.

## 1. Confirm the branch

```powershell
git branch --show-current
git status
```

For this client workflow package the branch must be:

```text
client-workflow-update
```

## 2. Start the application

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

## 3. Demo accounts

Local demo password: `SPMU-Demo-2026!`

| Access | Email | Main work |
|---|---|---|
| Borrower | `borrower@spmu.test` | Available items, calendar, requests, custody and accountability |
| SPMU Action Officer | `spmu@spmu.test` | Pickup scheduling, release/return, laundry operations and evidence |
| SPMU Admin / Head | `spmu-head@spmu.test` | SPMU verification decisions, oversight and authorized controls |
| ICTU Maintainer | `ictu@spmu.test` | Accounts, settings, audit and technical operations |

GSU and VPAF do not have system logins. They are physical signatories on the printed Borrowing Request Letter when required by the institutional form.

## 4. Test the final workflow

1. **Borrower:** check Available Items and Calendar, then create/save a Draft request.
2. **Borrower:** open/print the generated Borrowing Request Letter.
3. **Borrower:** obtain the required handwritten/wet signatures from the physical GSU/VPAF signatories.
4. **Borrower:** scan and upload the fully signed request letter. Upload Permission to Conduct too when the request is for a student activity/organization.
5. **Borrower:** submit to SPMU.
6. **SPMU Admin / Head:** review the uploaded scan beside the checklist and Verify & Approve, Return for Revision, or Reject.
7. **SPMU Action Officer:** schedule pickup and confirm the exact approved quantities. The system generates the Borrower Slip and only the applicable Gate Pass/Laundry Form.
8. Complete required signatures physically with pen; upload/verify accomplished evidence where required.
9. **SPMU Action Officer:** record physical release and later the physical return/condition.
10. If linen was released, the **SPMU Action Officer** records the Laundry Job turnover and processing details, continues the cleaned linen through the existing final return inspection, and archives the accomplished Laundry Form. **SPMU Admin / Head** retains final acceptance and oversight where required.

There is no electronic-signature workflow and no GSU/VPAF in-system approval stage.

## 5. Run tests

```powershell
docker compose -p spmu-acpmp-test -f .\docker-compose.test.yml build --no-cache test

docker compose -p spmu-acpmp-test -f .\docker-compose.test.yml run --rm test php vendor/bin/phpunit --testdox
```

## 6. Push the client branch

After tests pass:

```powershell
git status
git log -1 --oneline
git push -u origin client-workflow-update
```

Do not use `git reset --hard` or `git clean -fd` on the working project.
