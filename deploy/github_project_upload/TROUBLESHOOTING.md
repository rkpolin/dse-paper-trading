# Troubleshooting

## GitHub Action Says API Credentials Not Configured

Check that these GitHub Secrets exist and are spelled exactly:

```text
HOSTINGER_API_BASE_URL
HOSTINGER_API_TOKEN
HOSTINGER_HMAC_SECRET
```

## API Returns 401

Likely causes:

- `HOSTINGER_API_TOKEN` does not match `hostinger_api/config.local.php`.
- `HOSTINGER_HMAC_SECRET` does not match `hostinger_api/config.local.php`.
- Hostinger server time is far from GitHub runner time.
- A proxy changed the raw request body.

## API Returns 415

The request did not use `Content-Type: application/json`. The Python engine already sends this header.

## API Returns 500

Check:

- `hostinger_api/config.local.php` exists.
- Database DSN, username, and password are correct.
- `database/schema.sql` was imported.
- PHP version is 8.0 or newer.

The API intentionally does not expose SQL errors to the browser.

## Dashboard Login Fails

Check:

- `dashboard/config.local.php` exists.
- The username matches the config.
- `password_hash` contains a real hash from `password_hash(...)`, not the plain password.

## Dashboard Is Blank

Enable PHP error logging in Hostinger hPanel, then check:

- PHP 8 is selected.
- Database credentials are correct.
- Tables exist in phpMyAdmin.
- Files were uploaded to the expected folders.

## No Data Appears

Run the GitHub workflow manually and confirm:

- Tests pass.
- The final engine step posts to Hostinger.
- `api_logs` has a `SUCCESS` row.
- `system_runs`, `signals`, and `portfolio_snapshots` have rows.

## Duplicate Data

The schema uses unique keys and upserts. If you see duplicate-looking rows, check whether they are from different `run_id` values. Each run is stored separately by design.
