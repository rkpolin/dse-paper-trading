-- Bangladesh DSE paper trading MVP schema.
-- Import this in Hostinger phpMyAdmin before running the API.

CREATE TABLE IF NOT EXISTS stocks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol VARCHAR(30) NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stocks_symbol (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_runs (
    run_id VARCHAR(80) PRIMARY KEY,
    started_at VARCHAR(40) NULL,
    completed_at VARCHAR(40) NULL,
    status VARCHAR(30) NOT NULL,
    source VARCHAR(80) NOT NULL,
    latest_data_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_prices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_id INT UNSIGNED NOT NULL,
    trade_date DATE NOT NULL,
    open_price DECIMAL(14,4) NOT NULL,
    high_price DECIMAL(14,4) NOT NULL,
    low_price DECIMAL(14,4) NOT NULL,
    close_price DECIMAL(14,4) NOT NULL,
    volume BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_daily_prices_stock_date (stock_id, trade_date),
    KEY idx_daily_prices_date (trade_date),
    CONSTRAINT fk_daily_prices_stock FOREIGN KEY (stock_id) REFERENCES stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intraday_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    trade_date DATE NOT NULL,
    snapshot_time TIME NOT NULL,
    bucket_time TIME NOT NULL,
    snapshot_at DATETIME NOT NULL,
    last_price DECIMAL(14,4) NOT NULL,
    day_high DECIMAL(14,4) NOT NULL,
    day_low DECIMAL(14,4) NOT NULL,
    volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source VARCHAR(80) NOT NULL DEFAULT 'python_engine',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_intraday_snapshot_stock_bucket (stock_id, trade_date, bucket_time),
    KEY idx_intraday_snapshots_run (run_id),
    KEY idx_intraday_snapshots_date (trade_date),
    KEY idx_intraday_snapshots_stock_date (stock_id, trade_date),
    CONSTRAINT fk_intraday_snapshots_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id),
    CONSTRAINT fk_intraday_snapshots_stock FOREIGN KEY (stock_id) REFERENCES stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_intraday_extremes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    trade_date DATE NOT NULL,
    day_high DECIMAL(14,4) NOT NULL,
    day_high_time TIME NOT NULL,
    day_low DECIMAL(14,4) NOT NULL,
    day_low_time TIME NOT NULL,
    intraday_range_pct DECIMAL(12,6) NOT NULL,
    open_snapshot_price DECIMAL(14,4) NOT NULL,
    close_snapshot_price DECIMAL(14,4) NOT NULL,
    snapshot_count INT UNSIGNED NOT NULL,
    is_complete TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_daily_intraday_extreme_stock_date (stock_id, trade_date),
    KEY idx_daily_intraday_extremes_run (run_id),
    KEY idx_daily_intraday_extremes_date (trade_date),
    CONSTRAINT fk_daily_intraday_extremes_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id),
    CONSTRAINT fk_daily_intraday_extremes_stock FOREIGN KEY (stock_id) REFERENCES stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intraday_time_window_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    lookback_days INT UNSIGNED NOT NULL,
    bucket_time TIME NOT NULL,
    sample_days INT UNSIGNED NOT NULL,
    low_count INT UNSIGNED NOT NULL DEFAULT 0,
    high_count INT UNSIGNED NOT NULL DEFAULT 0,
    low_probability DECIMAL(12,6) NOT NULL DEFAULT 0,
    high_probability DECIMAL(12,6) NOT NULL DEFAULT 0,
    avg_return_to_close_pct DECIMAL(12,6) NULL,
    avg_return_next_bucket_pct DECIMAL(12,6) NULL,
    buy_window_score DECIMAL(12,6) NOT NULL DEFAULT 0,
    sell_window_score DECIMAL(12,6) NOT NULL DEFAULT 0,
    confidence_level VARCHAR(20) NOT NULL,
    computed_through_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_intraday_stats_stock_lookback_bucket_date (stock_id, lookback_days, bucket_time, computed_through_date),
    KEY idx_intraday_stats_run (run_id),
    KEY idx_intraday_stats_stock (stock_id),
    KEY idx_intraday_stats_computed_date (computed_through_date),
    CONSTRAINT fk_intraday_stats_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id),
    CONSTRAINT fk_intraday_stats_stock FOREIGN KEY (stock_id) REFERENCES stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicators (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    trade_date DATE NOT NULL,
    rsi DECIMAL(10,4) NULL,
    sma20 DECIMAL(14,4) NULL,
    sma50 DECIMAL(14,4) NULL,
    volume_ratio DECIMAL(12,6) NULL,
    momentum DECIMAL(12,6) NULL,
    breakout TINYINT(1) NOT NULL DEFAULT 0,
    pump_risk TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_indicators_run_stock_date (run_id, stock_id, trade_date),
    KEY idx_indicators_date (trade_date),
    CONSTRAINT fk_indicators_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id),
    CONSTRAINT fk_indicators_stock FOREIGN KEY (stock_id) REFERENCES stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signals (
    signal_id CHAR(64) PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    signal_date DATE NOT NULL,
    signal_type VARCHAR(10) NOT NULL,
    close_price DECIMAL(14,4) NOT NULL,
    confidence DECIMAL(8,6) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    rsi DECIMAL(10,4) NULL,
    sma20 DECIMAL(14,4) NULL,
    sma50 DECIMAL(14,4) NULL,
    volume_ratio DECIMAL(12,6) NULL,
    momentum DECIMAL(12,6) NULL,
    breakout TINYINT(1) NOT NULL DEFAULT 0,
    pump_risk TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_signals_run_date (run_id, signal_date),
    KEY idx_signals_type_date (signal_type, signal_date),
    CONSTRAINT fk_signals_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id),
    CONSTRAINT fk_signals_stock FOREIGN KEY (stock_id) REFERENCES stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS paper_trades (
    trade_id CHAR(64) PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    trade_date DATE NOT NULL,
    side VARCHAR(4) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    price DECIMAL(14,4) NOT NULL,
    gross_value DECIMAL(16,4) NOT NULL,
    transaction_cost DECIMAL(14,4) NOT NULL,
    net_value DECIMAL(16,4) NOT NULL,
    realized_pl DECIMAL(16,4) NOT NULL DEFAULT 0,
    source VARCHAR(20) NOT NULL DEFAULT 'SYSTEM',
    entry_trade_id CHAR(64) NULL,
    reason VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_paper_trades_run_date (run_id, trade_date),
    KEY idx_paper_trades_side (side),
    KEY idx_paper_trades_source (source),
    KEY idx_paper_trades_entry_trade (entry_trade_id),
    CONSTRAINT fk_paper_trades_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id),
    CONSTRAINT fk_paper_trades_stock FOREIGN KEY (stock_id) REFERENCES stocks(id),
    CONSTRAINT fk_paper_trades_entry_trade FOREIGN KEY (entry_trade_id) REFERENCES paper_trades(trade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    avg_price DECIMAL(14,4) NOT NULL,
    current_price DECIMAL(14,4) NOT NULL,
    market_value DECIMAL(16,4) NOT NULL,
    cost_basis DECIMAL(16,4) NOT NULL,
    unrealized_pl DECIMAL(16,4) NOT NULL,
    entry_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_positions_run_stock (run_id, stock_id),
    KEY idx_positions_status (status),
    CONSTRAINT fk_positions_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id),
    CONSTRAINT fk_positions_stock FOREIGN KEY (stock_id) REFERENCES stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portfolio_snapshots (
    snapshot_id CHAR(64) PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    snapshot_date DATE NOT NULL,
    cash_balance DECIMAL(16,4) NOT NULL,
    positions_value DECIMAL(16,4) NOT NULL,
    portfolio_value DECIMAL(16,4) NOT NULL,
    realized_pl DECIMAL(16,4) NOT NULL,
    unrealized_pl DECIMAL(16,4) NOT NULL,
    open_positions INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portfolio_snapshots_run_date (run_id, snapshot_date),
    KEY idx_portfolio_snapshots_date (snapshot_date),
    CONSTRAINT fk_portfolio_snapshots_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accuracy_evaluations (
    evaluation_id CHAR(64) PRIMARY KEY,
    signal_id CHAR(64) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    signal_date DATE NOT NULL,
    signal_type VARCHAR(10) NOT NULL,
    entry_price DECIMAL(14,4) NOT NULL,
    evaluation_days INT UNSIGNED NOT NULL,
    days_checked INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    max_gain_pct DECIMAL(12,6) NULL,
    max_drawdown_pct DECIMAL(12,6) NULL,
    result_note VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accuracy_signal (signal_id),
    KEY idx_accuracy_status (status),
    KEY idx_accuracy_run_date (run_id, signal_date),
    CONSTRAINT fk_accuracy_signal FOREIGN KEY (signal_id) REFERENCES signals(signal_id),
    CONSTRAINT fk_accuracy_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id),
    CONSTRAINT fk_accuracy_stock FOREIGN KEY (stock_id) REFERENCES stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS strategy_performance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    strategy_name VARCHAR(80) NOT NULL,
    initial_balance DECIMAL(16,4) NOT NULL,
    ending_value DECIMAL(16,4) NOT NULL,
    total_pl DECIMAL(16,4) NOT NULL,
    total_return_pct DECIMAL(12,6) NOT NULL,
    trade_count INT UNSIGNED NOT NULL,
    win_rate DECIMAL(12,6) NOT NULL,
    signal_accuracy DECIMAL(12,6) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_strategy_run_name (run_id, strategy_name),
    CONSTRAINT fk_strategy_run FOREIGN KEY (run_id) REFERENCES system_runs(run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(80) NULL,
    status VARCHAR(20) NOT NULL,
    message VARCHAR(255) NOT NULL,
    remote_addr VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_api_logs_run (run_id),
    KEY idx_api_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
