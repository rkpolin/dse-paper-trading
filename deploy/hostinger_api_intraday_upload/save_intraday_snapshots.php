<?php
declare(strict_types=1);

require_once __DIR__ . '/endpoints/intraday.php';

handle_intraday_endpoint('intraday_snapshots', 'save_intraday_snapshots');
