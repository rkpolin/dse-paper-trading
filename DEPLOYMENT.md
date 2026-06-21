# Deployment Guide

## 1. Create the MySQL Database on Hostinger

1. Open Hostinger hPanel.
2. Go to **Databases -> MySQL Databases**.
3. Create a database, user, and strong password.
4. Open phpMyAdmin.
5. Select the new database.
6. Import `database/schema.sql`.

For an existing installation that already imported `schema.sql`, import this migration too:

```text
database/migrations/2026_06_21_intraday_time_patterns.sql
```

## 2. Upload PHP Files

Suggested public paths:

```text
public_html/api/        contents of hostinger_api/
public_html/dashboard/  contents of dashboard/
```

For the intraday feature, make sure these new API files are uploaded:

```text
hostinger_api/save_intraday_snapshots.php
hostinger_api/save_intraday_extremes.php
hostinger_api/save_intraday_stats.php
hostinger_api/endpoints/intraday.php
```

And upload the new dashboard page:

```text
dashboard/intraday.php
```

After upload, create:

```text
public_html/api/config.local.php
public_html/dashboard/config.local.php
```

Use the sample config files as templates. The API token and HMAC secret must be long random strings and must match the GitHub Secrets.

## 3. Dashboard Password

Generate a password hash:

```bash
php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Put only the generated hash into `dashboard/config.local.php`.

## 4. GitHub Secrets

In your GitHub repository:

1. Go to **Settings -> Secrets and variables -> Actions**.
2. Add these repository secrets:

```text
HOSTINGER_API_BASE_URL=https://yourdomain.com/api
HOSTINGER_API_TOKEN=the_same_token_from_api_config.local.php
HOSTINGER_HMAC_SECRET=the_same_secret_from_api_config.local.php
TELEGRAM_BOT_TOKEN=optional
TELEGRAM_CHAT_ID=optional
```

## 5. Run Manually

1. Go to **Actions** in GitHub.
2. Select **Run DSE Paper Trader**.
3. Click **Run workflow**.
4. Open the completed run and confirm tests passed.
5. Open `https://yourdomain.com/dashboard/login.php`.

For intraday collection:

1. Go to **Actions** in GitHub.
2. Select **Intraday DSE Snapshots**.
3. Click **Run workflow**.
4. If the market is closed or outside a target bucket, the job exits safely without posting data.
5. Use `force_run=true` only for manual testing.

## 6. Scheduled Runs

The workflow runs Sunday through Thursday using UTC cron times that map to Bangladesh time:

- `04:15`, `05:15`, `06:15`, `07:15` UTC = `10:15`, `11:15`, `12:15`, `13:15` Bangladesh time
- `08:05` UTC = `14:05` Bangladesh time for a post-close snapshot

The GitHub workflow uses `DATA_SOURCE=dse`, which fetches DSE day-end archive history plus the public latest share price page. If DSE cannot be reached, the run fails instead of posting stale demo data.

The intraday workflow runs Sunday through Thursday at the configured 15-minute target buckets from `10:05` to `14:05` Bangladesh time. Add market holidays to `DSE_HOLIDAYS` in the workflow if you want those dates skipped.

Intraday time-window stats need at least 20 completed trading days of snapshots. Before that, the dashboard shows `NOT_ENOUGH_DATA`.
