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
- Collects intraday snapshots during DSE market hours and estimates historical high/low time windows for paper-trading research.
- Sends signed JSON data from GitHub Actions to a PHP 8 API on Hostinger.
- Displays results in a PHP + MySQL dashboard with Chart.js.

## Project Layout

```text
python_engine/       Python 3.11 signal and paper-trading engine
hostinger_api/       PHP 8 signed JSON ingest API
dashboard/           PHP dashboard pages
database/schema.sql  MySQL/MariaDB schema
database/migrations/ Extra migrations for existing installations
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
DATA_SOURCE=dse
```

That means scheduled runs must fetch DSE day-end archive data plus the public DSE latest share price page. If DSE cannot be reached, the workflow fails instead of posting stale demo data to the dashboard.

For local testing only, use:

```text
DATA_SOURCE=auto
```

`auto` fetches DSE first and falls back to `python_engine/sample_data/dse_demo_prices.csv` if DSE is unavailable.

The archive lookback defaults to:

```text
DSE_ARCHIVE_LOOKBACK_DAYS=120
```

Use `DSE_SYMBOLS=GP,BRACBANK,SQURPHARMA` if you want to limit the run to a watchlist. Leave it blank to fetch all symbols shown on DSE's latest share price page.

## Intraday Time Pattern Analyzer

The intraday workflow is paper-trading research only. It does not connect to any broker and does not place real trades.

It runs during Bangladesh market hours at these target buckets:

```text
10:05, 10:20, 10:35, 10:50,
11:05, 11:20, 11:35, 11:50,
12:05, 12:20, 12:35, 12:50,
13:05, 13:20, 13:35, 13:50,
14:05
```

Each run saves current intraday snapshots to Hostinger. The dashboard uses accumulated snapshots to show high/low so far. Historical time-window stats require at least 20 completed trading days of intraday snapshots. Today is excluded from recommendation stats to avoid look-ahead bias.

Optional intraday settings:

```text
INTRADAY_HISTORY_CSV_PATH=python_engine/sample_data/dse_intraday_demo.csv
INTRADAY_BUCKET_TOLERANCE_MINUTES=8
INTRADAY_STAT_LOOKBACKS=20,30,60
DSE_HOLIDAYS=2026-02-21,2026-03-26
```

## Required GitHub Secrets

- `HOSTINGER_API_BASE_URL`
- `HOSTINGER_API_TOKEN`
- `HOSTINGER_HMAC_SECRET`
- `TELEGRAM_BOT_TOKEN` optional
- `TELEGRAM_CHAT_ID` optional

See [DEPLOYMENT.md](DEPLOYMENT.md) and [docs/SETUP_FOR_BEGINNERS.md](docs/SETUP_FOR_BEGINNERS.md).
