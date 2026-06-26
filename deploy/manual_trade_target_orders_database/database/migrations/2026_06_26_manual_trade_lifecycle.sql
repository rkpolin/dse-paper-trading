ALTER TABLE paper_trades
    ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'SYSTEM' AFTER realized_pl,
    ADD COLUMN entry_trade_id CHAR(64) NULL AFTER source,
    ADD KEY idx_paper_trades_source (source),
    ADD KEY idx_paper_trades_entry_trade (entry_trade_id),
    ADD CONSTRAINT fk_paper_trades_entry_trade FOREIGN KEY (entry_trade_id) REFERENCES paper_trades(trade_id);
