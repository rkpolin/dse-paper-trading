# Bangladesh DSE Paper Trading Dashboard MVP

Secure MVP for DSE-style paper trading research. It does not connect to a broker, does not place real orders, and should never be used as real-money trading automation.

## What It Does

- Imports DSE-style CSV price data.
- Automatically fetches the latest public DSE share price table during scheduled runs, with CSV fallback.
- Calculates RSI, SMA20, SMA50, volume ratio, momentum, breakout, and pump-risk flags.
- Generates `BUY`, `SELL`, `HOLD`, `WATCH`, and `AVOID` signals.
- Simulates paper trades with a 100,000 BDT starting balance.
- Tracks realized/unrealized P/L, portfolio value, open positions, win rate, and signal accuracy.
- Scores signals after 5 trading days.
- Sends signed JSON data from GitHub Actions to a PHP 8 API on Hostinger.
- Displays results in a PHP + MySQL dashboard with Chart.js.

## Project Layout

```text
python_engine/       Python 3.11 signal and paper-trading engine
hostinger_api/       PHP 8 signed JSON ingest API
dashboard/           PHP dashboard pages
database/schema.sql  MySQL/MariaDB schema
docs/                Beginner setup guide
.github/workflows/   Scheduled/manual GitHub Actions runner
```

## Paper Trading Rules

- Initial balance: 100,000 BDT
- Max position size: 10% per stock
- Max open positions: 5
- Transaction cost: 0.5% per buy and sell side
- Stop loss: -5%
- Take profit: +8%
- No duplicate `BUY` if already holding the same stock
- No same-day re-buy after a stock is sold by stop loss, take profit, or sell signal
- Every run has a unique `run_id`
- Database upserts prevent duplicate inserts from retries

## Signal Evaluation

- `BUY` is correct if price reaches +3% within the next 5 trading days before -3%.
- `SELL` is correct if price falls -3% within the next 5 trading days.
- `HOLD` is correct if price stays between -2% and +2%.
- Incomplete windows stay `PENDING`.
- `WATCH` and `AVOID` are stored for review and marked `NOT_APPLICABLE` by the default scoring rules.

## Local Python Test

```bash
cd python_engine
python -m pip install -r requirements.txt
pytest tests
python run_engine.py
```

Without API secrets, the engine writes `python_engine/output/latest_payload.json`.

## Data Source

Default GitHub Actions runs use:

```text
DATA_SOURCE=auto
```

That means the engine fetches DSE day-end archive data for recent history, then fetches the public DSE latest share price page and merges both with CSV fallback data for indicator context. If DSE is temporarily unavailable, it falls back to `python_engine/sample_data/dse_demo_prices.csv`.

The archive lookback defaults to:

```text
DSE_ARCHIVE_LOOKBACK_DAYS=120
```

Use `DSE_SYMBOLS=GP,BRACBANK,SQURPHARMA` if you want to limit the run to a watchlist. Leave it blank to fetch all symbols shown on DSE's latest share price page.

## Required GitHub Secrets

- `HOSTINGER_API_BASE_URL`
- `HOSTINGER_API_TOKEN`
- `HOSTINGER_HMAC_SECRET`
- `TELEGRAM_BOT_TOKEN` optional
- `TELEGRAM_CHAT_ID` optional

See [DEPLOYMENT.md](DEPLOYMENT.md) and [docs/SETUP_FOR_BEGINNERS.md](docs/SETUP_FOR_BEGINNERS.md).
