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

## Automatic DSE Data

The GitHub workflow is configured with:

```text
DATA_SOURCE=dse
```

It fetches DSE day-end archive history and the public latest share price table during each run. If DSE cannot be reached, the run fails so old demo data is not posted again.

## Part F: If Something Breaks

Check in this order:

1. GitHub Action logs.
2. Hostinger `api_logs` table in phpMyAdmin.
3. Hostinger PHP error logs.
4. `hostinger_api/config.local.php` database settings.
5. GitHub Secrets spelling.
6. API token and HMAC secret match exactly.

This system is only for paper trading research. It does not place real trades.
