<?php
declare(strict_types=1);

function signal_number(array $row, string $key, float $default = 0.0): float
{
    if (!isset($row[$key]) || $row[$key] === '' || $row[$key] === null || !is_numeric($row[$key])) {
        return $default;
    }
    return (float)$row[$key];
}

function clamp_number(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function signal_recent_prices(array $pricesByStock, int $stockId, string $signalDate, int $limit = 20): array
{
    $rows = $pricesByStock[$stockId] ?? [];
    $selected = [];
    foreach ($rows as $row) {
        if ((string)$row['trade_date'] <= $signalDate) {
            $selected[] = $row;
        }
    }
    return array_slice($selected, -$limit);
}

function average_intraday_range_pct(array $prices): float
{
    $values = [];
    foreach ($prices as $row) {
        $close = signal_number($row, 'close_price');
        if ($close <= 0) {
            continue;
        }
        $values[] = max(0.0, (signal_number($row, 'high_price') - signal_number($row, 'low_price')) / $close);
    }
    if (!$values) {
        return 0.03;
    }
    return array_sum($values) / count($values);
}

function calculate_pump_risk_display(array $signal, array $recentPrices): array
{
    $score = 0;
    $reasons = [];
    $rsi = signal_number($signal, 'rsi');
    $volumeRatio = signal_number($signal, 'volume_ratio');
    $close = signal_number($signal, 'close_price');
    $sma20 = signal_number($signal, 'sma20');

    if ($rsi >= 80) {
        $score += 30;
        $reasons[] = 'RSI is extremely overbought.';
    } elseif ($rsi >= 70) {
        $score += 22;
        $reasons[] = 'RSI is above 70, so the move may be stretched.';
    } elseif ($rsi >= 65) {
        $score += 10;
        $reasons[] = 'RSI is elevated.';
    }

    if ($volumeRatio >= 5) {
        $score += 25;
        $reasons[] = 'Volume is more than 5x normal.';
    } elseif ($volumeRatio >= 3) {
        $score += 18;
        $reasons[] = 'Volume is more than 3x normal.';
    } elseif ($volumeRatio >= 2) {
        $score += 8;
        $reasons[] = 'Volume is much higher than usual.';
    }

    $priceFiveDaysAgo = null;
    if (count($recentPrices) >= 6) {
        $priceFiveDaysAgo = signal_number($recentPrices[count($recentPrices) - 6], 'close_price');
    }
    if ($close > 0 && $priceFiveDaysAgo !== null && $priceFiveDaysAgo > 0) {
        $movePct = ($close - $priceFiveDaysAgo) / $priceFiveDaysAgo;
        if ($movePct >= 0.20) {
            $score += 25;
            $reasons[] = 'Price moved more than 20% in the last 5 trading days.';
        } elseif ($movePct >= 0.12) {
            $score += 15;
            $reasons[] = 'Price moved more than 12% in the last 5 trading days.';
        } elseif ($movePct >= 0.08) {
            $score += 8;
            $reasons[] = 'Price moved quickly in the last 5 trading days.';
        }
    }

    if ($close > 0 && $sma20 > 0) {
        $distanceFromSma20 = ($close - $sma20) / $sma20;
        if ($distanceFromSma20 >= 0.15) {
            $score += 20;
            $reasons[] = 'Price is more than 15% above SMA20.';
        } elseif ($distanceFromSma20 >= 0.08) {
            $score += 10;
            $reasons[] = 'Price is far above SMA20.';
        }
    }

    $volumes = [];
    foreach ($recentPrices as $row) {
        if (isset($row['volume']) && is_numeric($row['volume'])) {
            $volumes[] = (float)$row['volume'];
        }
    }
    if ($volumes) {
        $avgVolume = array_sum($volumes) / count($volumes);
        if ($avgVolume > 0 && $avgVolume < 50000) {
            $score += 12;
            $reasons[] = 'Average volume is low, so liquidity risk is higher.';
        } elseif ($avgVolume > 0 && $avgVolume < 100000) {
            $score += 6;
            $reasons[] = 'Average volume is modest.';
        }
    }

    if ((int)($signal['pump_risk'] ?? 0) === 1) {
        $score += 10;
        $reasons[] = 'The engine already flagged pump risk.';
    }

    $score = min(100, $score);
    if ($score >= 75) {
        $level = 'EXTREME';
    } elseif ($score >= 55) {
        $level = 'HIGH';
    } elseif ($score >= 30) {
        $level = 'MEDIUM';
    } else {
        $level = 'LOW';
    }

    if (!$reasons) {
        $reasons[] = 'No major pump-risk trigger found from available indicators.';
    }

    return [
        'score' => $score,
        'level' => $level,
        'reasons' => array_values(array_unique($reasons)),
    ];
}

function calculate_entry_zone_display(array $signal, array $recentPrices, array $pumpRisk): array
{
    $signalType = (string)($signal['signal_type'] ?? '');
    $current = signal_number($signal, 'close_price');
    if ($signalType !== 'BUY' || $current <= 0) {
        return [
            'available' => false,
            'action' => 'N/A',
            'reason' => 'Entry zone is shown only for BUY paper signals.',
        ];
    }

    $sma20 = signal_number($signal, 'sma20', $current);
    $base = $sma20 > 0 ? $sma20 : $current;
    $recent = array_slice($recentPrices, -10);
    $lows = [];
    foreach ($recent as $row) {
        $low = signal_number($row, 'low_price');
        if ($low > 0) {
            $lows[] = $low;
        }
    }
    $supportLow = $lows ? min($lows) : min($current, $base);
    $volatility = clamp_number(average_intraday_range_pct($recent), 0.02, 0.08);

    $technicalLow = max($supportLow, $base * (1 - $volatility));
    $technicalHigh = $base * (1 + min($volatility * 0.5, 0.03));
    $preferredLow = min($technicalLow, $technicalHigh);
    $preferredHigh = max($technicalLow, $technicalHigh);
    $avoidAbove = max($preferredHigh * 1.01, $base * (1 + clamp_number($volatility * 1.75, 0.05, 0.12)));
    $aggressiveLow = $preferredHigh;
    $aggressiveHigh = $avoidAbove;
    $stopLoss = $current * 0.95;
    $target1 = $current * 1.04;
    $target2 = $current * 1.08;

    if (in_array($pumpRisk['level'], ['HIGH', 'EXTREME'], true)) {
        $action = $pumpRisk['level'] === 'EXTREME' ? 'AVOID' : 'WATCH';
        $reason = 'Pump risk is too high for BUY NOW.';
    } elseif ($current >= $preferredLow && $current <= $preferredHigh) {
        $action = 'BUY NOW';
        $reason = 'Current price is inside the preferred buy zone.';
    } elseif ($current > $aggressiveLow && $current <= $aggressiveHigh) {
        $action = 'WATCH / SMALL ENTRY';
        $reason = 'Current price is above the preferred zone but still below the avoid-above level.';
    } elseif ($current > $avoidAbove) {
        $action = 'AVOID / WAIT FOR PULLBACK';
        $reason = 'Current price is above the avoid-above level.';
    } else {
        $action = 'WAIT';
        $reason = 'Current price is below the preferred zone; wait for confirmation.';
    }

    return [
        'available' => true,
        'current_price' => $current,
        'preferred_low' => $preferredLow,
        'preferred_high' => $preferredHigh,
        'aggressive_low' => $aggressiveLow,
        'aggressive_high' => $aggressiveHigh,
        'avoid_above' => $avoidAbove,
        'stop_loss' => $stopLoss,
        'target1' => $target1,
        'target2' => $target2,
        'action' => $action,
        'reason' => $reason,
    ];
}

function build_signal_explanation(array $signal, array $pumpRisk): array
{
    $type = (string)($signal['signal_type'] ?? 'HOLD');
    $close = signal_number($signal, 'close_price');
    $rsi = signal_number($signal, 'rsi');
    $sma20 = signal_number($signal, 'sma20');
    $sma50 = signal_number($signal, 'sma50');
    $volumeRatio = signal_number($signal, 'volume_ratio');
    $momentum = signal_number($signal, 'momentum');
    $reason = trim((string)($signal['reason'] ?? ''));

    $why = match ($type) {
        'BUY' => 'BUY means the paper system sees bullish conditions, but entry still depends on zone and risk.',
        'SELL' => 'SELL means the paper system sees weakness or exit pressure.',
        'WATCH' => 'WATCH means conditions are interesting but not clean enough for a paper entry.',
        'AVOID' => 'AVOID means risk is too high or the setup is too weak.',
        default => 'HOLD means there is no strong new directional edge.',
    };
    if ($reason !== '') {
        $why .= ' Engine reason: ' . $reason;
    }

    if ($rsi >= 70) {
        $rsiText = 'RSI is above 70, which can show strong demand but also overbought risk.';
    } elseif ($rsi >= 55) {
        $rsiText = 'RSI is above 55, showing positive momentum.';
    } elseif ($rsi >= 45) {
        $rsiText = 'RSI is neutral, so momentum is not strongly one-sided.';
    } elseif ($rsi >= 30) {
        $rsiText = 'RSI is weak, showing soft demand.';
    } else {
        $rsiText = 'RSI is below 30, which can mean oversold but still risky.';
    }

    if ($close > 0 && $sma20 > 0 && $sma50 > 0 && $close > $sma20 && $sma20 > $sma50) {
        $trendText = 'Price is above SMA20 and SMA20 is above SMA50, so the short trend is aligned upward.';
    } elseif ($close > 0 && $sma20 > 0 && $close < $sma20) {
        $trendText = 'Price is below SMA20, so short-term trend confirmation is weak.';
    } elseif ($sma20 > 0 && $sma50 > 0 && $sma20 < $sma50) {
        $trendText = 'SMA20 is below SMA50, so the broader short trend is still weak.';
    } else {
        $trendText = 'SMA trend is mixed or not fully available.';
    }

    if ($volumeRatio >= 3) {
        $volumeText = 'Volume ratio is very high, so confirm it is real accumulation and not a short spike.';
    } elseif ($volumeRatio >= 1.5) {
        $volumeText = 'Volume ratio is healthy and supports the signal.';
    } elseif ($volumeRatio >= 0.8) {
        $volumeText = 'Volume ratio is normal.';
    } else {
        $volumeText = 'Volume ratio is weak, so the signal has less confirmation.';
    }

    if ($momentum >= 0.05) {
        $momentumText = 'Momentum is strong; avoid chasing if price is already extended.';
    } elseif ($momentum > 0.01) {
        $momentumText = 'Momentum is positive.';
    } elseif ($momentum >= -0.01) {
        $momentumText = 'Momentum is flat.';
    } else {
        $momentumText = 'Momentum is negative.';
    }

    $riskFactors = $pumpRisk['reasons'];
    if ($type !== 'BUY') {
        $riskFactors[] = 'No BUY entry action is suggested for this signal type.';
    }

    $invalid = [
        'Price breaks below the stop-loss or recent support area.',
        'RSI stays overbought while price stops rising.',
        'Volume fades and price falls back under SMA20.',
        'Pump risk rises to HIGH or EXTREME.',
    ];
    if ($type === 'SELL') {
        $invalid = [
            'Price reclaims SMA20 with improving volume.',
            'Momentum turns positive again.',
            'The sell signal fails to follow through within the evaluation window.',
        ];
    } elseif ($type === 'HOLD' || $type === 'WATCH') {
        $invalid[] = 'A new BUY or SELL signal replaces the neutral setup.';
    }

    return [
        'why' => $why,
        'rsi' => $rsiText,
        'trend' => $trendText,
        'volume' => $volumeText,
        'momentum' => $momentumText,
        'risk_factors' => array_values(array_unique($riskFactors)),
        'invalid' => $invalid,
    ];
}

function build_signal_explanation_bn(array $signal, array $pumpRisk, array $entryZone): array
{
    $type = (string)($signal['signal_type'] ?? 'HOLD');
    $rsi = signal_number($signal, 'rsi');
    $close = signal_number($signal, 'close_price');
    $sma20 = signal_number($signal, 'sma20');
    $sma50 = signal_number($signal, 'sma50');
    $volumeRatio = signal_number($signal, 'volume_ratio');
    $momentum = signal_number($signal, 'momentum');

    $why = match ($type) {
        'BUY' => 'সিস্টেম BUY দেখাচ্ছে, কারণ price action আর indicator একসাথে bullish দিক দেখাচ্ছে।',
        'SELL' => 'সিস্টেম SELL দেখাচ্ছে, কারণ momentum দুর্বল বা trend নিচের দিকে।',
        'WATCH' => 'সেটআপ আছে, কিন্তু এখনই entry নেওয়ার মতো পুরো confirmation নেই।',
        'AVOID' => 'ঝুঁকি বেশি, তাই এই সেটআপ এড়িয়ে চলা ভালো।',
        default => 'এখন শক্তিশালী নতুন direction পাওয়া যাচ্ছে না, তাই HOLD.',
    };

    if ($rsi >= 70) {
        $rsiText = 'RSI অনেক বেশি, তাই demand strong হলেও entry risky হতে পারে।';
    } elseif ($rsi >= 55) {
        $rsiText = 'RSI মাঝারি থেকে bullish zone-এ আছে, তাই momentum মোটামুটি ভালো।';
    } elseif ($rsi >= 45) {
        $rsiText = 'RSI neutral, তাই market এখনো পরিষ্কার direction দেয়নি।';
    } else {
        $rsiText = 'RSI দুর্বল, তাই upside confidence কম।';
    }

    if ($close > $sma20 && $sma20 > $sma50) {
        $trendText = 'দাম SMA20 এবং SMA50 এর উপরে, তাই trend bullish।';
    } elseif ($close < $sma20) {
        $trendText = 'দাম SMA20 এর নিচে, তাই short-term trend দুর্বল।';
    } elseif ($sma20 < $sma50) {
        $trendText = 'SMA20 এখনো SMA50 এর নিচে, তাই trend পুরোপুরি শক্ত নয়।';
    } else {
        $trendText = 'SMA20/SMA50 mix অবস্থায় আছে, তাই trend পরিষ্কার না।';
    }

    if ($volumeRatio >= 3) {
        $volumeText = 'Volume ratio অনেক বেশি, তাই breakout সত্যি হতে পারে, তবে pump risk-ও বাড়ে।';
    } elseif ($volumeRatio >= 1.5) {
        $volumeText = 'Volume ratio healthy, তাই signal-এর পেছনে অংশগ্রহণ আছে।';
    } elseif ($volumeRatio >= 0.8) {
        $volumeText = 'Volume মোটামুটি normal, তাই extra confirmation নেই।';
    } else {
        $volumeText = 'Volume দুর্বল, তাই signal follow-through নাও করতে পারে।';
    }

    if ($momentum >= 0.05) {
        $momentumText = 'Momentum strong, কিন্তু খুব দ্রুত rise হলে chasing risky।';
    } elseif ($momentum > 0.01) {
        $momentumText = 'Momentum positive, তাই price ধীরে ধীরে উপরে যেতে পারে।';
    } elseif ($momentum >= -0.01) {
        $momentumText = 'Momentum flat, তাই দ্রুত move আশা করা ঠিক না।';
    } else {
        $momentumText = 'Momentum negative, তাই downside চাপ আছে।';
    }

    $pumpRiskText = match ($pumpRisk['level']) {
        'EXTREME' => 'Pump risk খুব বেশি। এখন entry না নেওয়াই ভালো।',
        'HIGH' => 'Pump risk বেশি। ছোট observation mode-এ থাকাই নিরাপদ।',
        'MEDIUM' => 'Pump risk মাঝারি। price extension আর volume দেখে entry নিতে হবে।',
        default => 'Pump risk কম, তবে rule ভেঙে entry নেওয়া ঠিক হবে না।',
    };

    if (($entryZone['available'] ?? false) === true) {
        $entryText = 'Preferred buy zone ' . number_format((float)$entryZone['preferred_low'], 2) . '-' . number_format((float)$entryZone['preferred_high'], 2)
            . ' BDT. Final action: ' . (string)$entryZone['action'] . '.';
    } else {
        $entryText = 'এই signal-এর জন্য buy entry zone প্রযোজ্য নয়।';
    }

    return [
        'why' => $why,
        'rsi' => $rsiText,
        'trend' => $trendText,
        'volume' => $volumeText,
        'momentum' => $momentumText,
        'pump_risk' => $pumpRiskText,
        'entry' => $entryText,
    ];
}

function format_price_range(float $low, float $high): string
{
    return number_format($low, 2) . ' - ' . number_format($high, 2) . ' BDT';
}
