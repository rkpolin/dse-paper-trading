<?php
declare(strict_types=1);

require_once __DIR__ . '/endpoints/intraday.php';

handle_intraday_endpoint('daily_intraday_extremes', 'save_daily_intraday_extremes');
