# SMS Provider Setup

SMS is an additional delivery channel; email and in-system notifications remain active. It is disabled by default, so the application is safe to demonstrate without provider credentials.

## Environment

Set these protected environment values, then clear and rebuild Laravel's configuration cache:

```dotenv
SMS_ENABLED=true
SMS_PROVIDER=approved-provider-name
SMS_WEBHOOK_URL=https://approved-sms-gateway.example/send
SMS_API_TOKEN=provider-or-gateway-secret
SMS_SENDER_NAME=CSPC SPMU
QUEUE_CONNECTION=database
```

The HTTPS endpoint must accept a bearer token and this JSON payload:

```json
{
  "to": "+639171234567",
  "message": "CSPC SPMU: ...",
  "event_code": "REQUEST_APPROVED",
  "sender": "CSPC SPMU"
}
```

`sender` is omitted when `SMS_SENDER_NAME` is blank. The endpoint must return a 2xx response after accepting the message. Use an ICTU-managed adapter or provider endpoint that implements this small contract; credentials never belong in Administration settings or source control.

## Queue and validation

Run the database migration, then keep a worker running in every deployed environment:

```powershell
php artisan migrate --force
php artisan queue:work database --tries=1
```

SMS jobs dispatch after the workflow transaction commits. Disabled SMS, missing credentials, invalid/missing mobile numbers, and borrower opt-outs are stored as `SKIPPED`; provider errors are stored as `FAILED` and never undo the workflow. ICTU should register the approved sender, complete any provider compliance steps, configure the endpoint or adapter, and send a controlled test to an opted-in test borrower before enabling production SMS.
