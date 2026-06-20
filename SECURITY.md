# Security Notes

This project is designed for paper trading only. It must not be connected to a broker API or real-money trading account.

## Secrets

Never commit real values for:

- Database name, username, or password
- API token
- HMAC secret
- Dashboard password hash
- Telegram bot token or chat ID

Use:

- `python_engine/.env.example` as a template only
- `hostinger_api/config.sample.php` as a template only
- `dashboard/config.sample.php` as a template only
- GitHub Secrets for production GitHub Actions values
- `config.local.php` on Hostinger, which is ignored by git

## API Authentication

The PHP API requires these headers on every request:

- `X-API-Token`
- `X-Timestamp`
- `X-Signature`

Signature format:

```text
HMAC-SHA256(timestamp + "." + raw_body, HOSTINGER_HMAC_SECRET)
```

Requests older than 5 minutes are rejected. The PHP API uses `hash_equals` for token and signature checks.

## Database Safety

- PDO prepared statements are used for inserts and updates.
- Duplicate protection is handled with primary keys and unique indexes.
- SQL errors are not returned to clients.
- API logs store run status and remote IP only, not secrets or request bodies.

## Hosting Notes

The included `.htaccess` files deny direct access to `config.local.php` and `config.sample.php`. If your Hostinger plan uses a non-Apache stack or ignores `.htaccess`, place local config files outside the public web root and adjust the include paths.

## Operational Limits

This MVP uses CSV input and simple rule-based signals. It does not provide financial advice, guarantee accuracy, or model real order-book liquidity, slippage, taxes, or circuit breaker behavior.
