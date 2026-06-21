# Beginner Setup Guide

Follow these steps in order.

## Part A: Hostinger Database

1. Log in to Hostinger hPanel.
2. Open **Databases -> MySQL Databases**.
3. Create a new database.
4. Create a database user with a strong password.
5. Copy the database name, username, password, and host.
6. Open phpMyAdmin from Hostinger.
7. Select your new database.
8. Click **Import**.
9. Upload `database/schema.sql`.
10. Click **Go**.
11. If this is an existing dashboard and `schema.sql` was already imported before, also import `database/migrations/2026_06_21_intraday_time_patterns.sql`.

## Part B: Upload the PHP API

1. Open Hostinger File Manager.
2. Go to `public_html`.
3. Create a folder named `api`.
4. Upload everything inside `hostinger_api/` into `public_html/api/`.
5. In `public_html/api/`, copy `config.sample.php` to `config.local.php`.
6. Edit `config.local.php`.
7. Put your MySQL DSN, database username, and database password.
8. Create a long random API token.
9. Create a different long random HMAC secret.
10. Save the file.

For the intraday feature, confirm these files exist in `public_html/api/`:

```text
save_intraday_snapshots.php
save_intraday_extremes.php
save_intraday_stats.php
endpoints/intraday.php
```

Example DSN:

```php
'dsn' => 'mysql:host=localhost;dbname=u123456789_dse;charset=utf8mb4',
```

## Part C: Upload the Dashboard

1. In `public_html`, create a folder named `dashboard`.
2. Upload everything inside `dashboard/` into `public_html/dashboard/`.
3. In `public_html/dashboard/`, copy `config.sample.php` to `config.local.php`.
4. Edit the database settings.
5. Generate a dashboard password hash:

```bash
php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

6. Put the generated hash into `password_hash`.
7. Save the file.

Dashboard URL:

```text
https://yourdomain.com/dashboard/login.php
```

The dashboard should also have:

```text
https://yourdomain.com/dashboard/intraday.php
```

## Part D: GitHub Repository

1. Create a new GitHub repository.
2. Upload or push this project to the repository.
3. Open the repository on GitHub.
4. Go to **Settings -> Secrets and variables -> Actions**.
5. Add these secrets:

```text
HOSTINGER_API_BASE_URL=https://yourdomain.com/api
HOSTINGER_API_TOKEN=your_api_token_from_hostinger_api_config
HOSTINGER_HMAC_SECRET=your_hmac_secret_from_hostinger_api_config
```

Optional Telegram secrets:

```text
TELEGRAM_BOT_TOKEN
TELEGRAM_CHAT_ID
```

## Part E: Manual Test Run

1. Open the GitHub repository.
2. Click **Actions**.
3. Click **Run DSE Paper Trader**.
4. Click **Run workflow**.
5. Wait for it to finish.
6. Open `https://yourdomain.com/dashboard/login.php`.
7. Log in.
8. Check Overview, Signals, Trades, Accuracy, and Portfolio.

For intraday:

1. Open **Actions**.
2. Click **Intraday DSE Snapshots**.
3. Click **Run workflow**.
4. If the market is open and near a target time bucket, it saves intraday snapshots.
5. For manual testing only, you may set `force_run` to `true`.
6. Open the dashboard and click **Intraday**.

## Automatic DSE Data

The GitHub workflow is configured with:

```text
DATA_SOURCE=dse
```

It fetches DSE day-end archive history and the public latest share price table during each run. If DSE cannot be reached, the run fails so old demo data is not posted again.

## Intraday Time Patterns

The intraday workflow collects snapshots around these Bangladesh-time buckets:

```text
10:05, 10:20, 10:35, 10:50,
11:05, 11:20, 11:35, 11:50,
12:05, 12:20, 12:35, 12:50,
13:05, 13:20, 13:35, 13:50,
14:05
```

At least 20 completed trading days are needed before the system can show useful historical buy/sell time-window confidence. Before that, `NOT_ENOUGH_DATA` is normal.

The feature is paper trading only. It is not financial advice and does not guarantee profit.

## Part F: If Something Breaks

Check in this order:

1. GitHub Action logs.
2. Hostinger `api_logs` table in phpMyAdmin.
3. Hostinger PHP error logs.
4. `hostinger_api/config.local.php` database settings.
5. GitHub Secrets spelling.
6. API token and HMAC secret match exactly.

This system is only for paper trading research. It does not place real trades.
