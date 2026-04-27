<?php
// custom.php — Combined Trend Indicator (ST+MACD+EMA+ADX) → super.json + super.log

// ─── CONFIG ───────────────────────────────────────────────────────────────────
$symbol        = 'XLMUSDT';
$interval      = '1m';
$limit         = 51;
$jsonFile      = __DIR__ . '/super.json';
$logFile       = __DIR__ . '/super.log';

// Pine inputs
$stAtrPeriod   = 10;
$stMultiplier  = 3.0;
$macdFast      = 12;
$macdSlow      = 26;
$macdSignal    = 9;
$emaLength     = 50;
$adxLength     = 14;
$adxThreshold  = 25.0;
$aggLen        = 1;

// ─── UTILITIES ─────────────────────────────────────────────────────────────────
function logMsg($msg) {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}
function wilderRMA(array $src, int $len): array {
    $rma = [];
    $sum = array_sum(array_slice($src, 0, $len));
    $rma[$len - 1] = $sum / $len;
    for ($i = $len; $i < count($src); $i++) {
        $rma[$i] = (($rma[$i - 1] * ($len - 1)) + $src[$i]) / $len;
    }
    return $rma;
}
function ema(array $src, int $len): array {
    $ema = [];
    $sum = array_sum(array_slice($src, 0, $len));
    $ema[$len - 1] = $sum / $len;
    $k = 2 / ($len + 1);
    for ($i = $len; $i < count($src); $i++) {
        $ema[$i] = ($src[$i] - $ema[$i - 1]) * $k + $ema[$i - 1];
    }
    return $ema;
}

// ─── FETCH & DEBUG INTERVAL ────────────────────────────────────────────────────
$url = "https://fapi.binance.com/fapi/v1/klines?symbol={$symbol}&interval={$interval}&limit={$limit}";
$data = @json_decode(file_get_contents($url), true);
if (!is_array($data)) {
    logMsg("ERROR fetching data or invalid JSON");
    exit;
}
logMsg("Fetched " . count($data) . " candles for {$symbol} @ {$interval}");
$t0 = $data[0][0]; $t1 = $data[1][0];
logMsg("First candle: " . date('H:i:s', $t0/1000)
     . ", second: " . date('H:i:s', $t1/1000)
     . ", diff(s): " . (($t1 - $t0)/1000));

// Build OHLC arrays
$high = $low = $close = [];
foreach ($data as $c) {
    $high[]  = (float)$c[2];
    $low[]   = (float)$c[3];
    $close[] = (float)$c[4];
}

// ─── 1) SUPERtrend ─────────────────────────────────────────────────────────────
$tr = [];
for ($i = 0; $i < count($close); $i++) {
    if ($i === 0) {
        $tr[] = $high[0] - $low[0];
    } else {
        $tr[] = max(
            $high[$i] - $low[$i],
            abs($high[$i] - $close[$i-1]),
            abs($low[$i]  - $close[$i-1])
        );
    }
}
$atr = wilderRMA($tr, $stAtrPeriod);
logMsg("ATR[last] = " . round(end($atr), 6));

// compute bands & trend
$upper = $lower = $st = [];
$trend = true;
for ($i = 0; $i < count($close); $i++) {
    if (!isset($atr[$i])) {
        $upper[$i] = $lower[$i] = $st[$i] = null;
        continue;
    }
    $hl2 = ($high[$i] + $low[$i]) / 2;
    $upper[$i] = $hl2 + $stMultiplier * $atr[$i];
    $lower[$i] = $hl2 - $stMultiplier * $atr[$i];

    if ($i === $stAtrPeriod) {
        $trend = $close[$i] > $upper[$i];
    } else {
        if ($st[$i-1] === $upper[$i-1] && $close[$i] <= $upper[$i]) $trend = false;
        if ($st[$i-1] === $lower[$i-1] && $close[$i] >= $lower[$i]) $trend = true;
    }
    $st[$i] = $trend ? $lower[$i] : $upper[$i];
}

// ─── 2) MACD ───────────────────────────────────────────────────────────────────
// build full-length arrays to avoid missing keys
$macdLine   = array_fill(0, count($close), 0.0);
$signalLine = array_fill(0, count($close), 0.0);
$emaFast    = ema($close, $macdFast);
$emaSlow    = ema($close, $macdSlow);
for ($i = 0; $i < count($close); $i++) {
    if (isset($emaFast[$i], $emaSlow[$i])) {
        $macdLine[$i] = $emaFast[$i] - $emaSlow[$i];
    }
}
$signalArr = ema($macdLine, $macdSignal);
foreach ($signalArr as $i => $v) {
    $signalLine[$i] = $v;
}

// ─── 3) EMA50 ──────────────────────────────────────────────────────────────────
$ema50 = ema($close, $emaLength);

// ─── 4) FIXED ADX ──────────────────────────────────────────────────────────────
$upMove   = array_fill(0, count($close), 0.0);
$downMove = array_fill(0, count($close), 0.0);
for ($i = 1; $i < count($close); $i++) {
    $up  = $high[$i] - $high[$i-1];
    $dn  = $low[$i-1] - $low[$i];
    $upMove[$i]   = ($up  > $dn && $up  > 0) ? $up  : 0;
    $downMove[$i] = ($dn > $up && $dn > 0) ? $dn  : 0;
}
$smPlusDM  = wilderRMA($upMove, $adxLength);
$smMinusDM = wilderRMA($downMove, $adxLength);
// reuse $atr
$plusDI = $minusDI = $dx = [];
for ($i = 0; $i < count($close); $i++) {
    if (isset($atr[$i]) && $atr[$i] > 0) {
        $plusDI[$i]  = 100 * ($smPlusDM[$i]  / $atr[$i]);
        $minusDI[$i] = 100 * ($smMinusDM[$i] / $atr[$i]);
        $sumDI       = $plusDI[$i] + $minusDI[$i];
        $dx[$i]      = $sumDI > 0
            ? 100 * abs($plusDI[$i] - $minusDI[$i]) / $sumDI
            : 0;
    } else {
        $plusDI[$i] = $minusDI[$i] = $dx[$i] = 0;
    }
}
$adx = wilderRMA($dx, $adxLength);
logMsg("FIXED ADX[last] = " 
     . round(end($adx), 2)
     . ", +DI=" . round(end($plusDI), 2)
     . ", -DI=" . round(end($minusDI), 2));

// ─── 5) VOTE ON LAST CLOSED BAR ────────────────────────────────────────────────
$idx = count($close) - 2;  // last *closed* candle

// Supertrend vote
$stVote = ($close[$idx] >= $st[$idx]) ? 1 : -1;
logMsg("Supertrend vote: {$stVote} (close={$close[$idx]}, band={$st[$idx]})");

// MACD vote
$macdVal    = $macdLine[$idx];
$signalVal  = $signalLine[$idx];
$macdVote   = ($macdVal > $signalVal) ? 1 : -1;
logMsg("MACD vote: {$macdVote} (macd={$macdVal}, signal={$signalVal})");

// EMA vote
$emaVote = ($close[$idx] > $ema50[$idx]) ? 1 : -1;
logMsg("EMA vote: {$emaVote} (close={$close[$idx]}, ema50={$ema50[$idx]})");

// ADX vote
$lastAdx   = $adx[$idx];
$lastPlus  = $plusDI[$idx];
$lastMinus = $minusDI[$idx];
$adxVote   = ($lastAdx >= $adxThreshold)
    ? ($lastPlus > $lastMinus ? 1 : -1)
    : 0;
logMsg("ADX vote: {$adxVote} (ADX={$lastAdx}, +DI={$lastPlus}, -DI={$lastMinus})");

// ─── 6) COMBINE & OUTPUT ───────────────────────────────────────────────────────
$score     = $stVote + $macdVote + $emaVote + $adxVote;
$final     = ($score >= 0 ? 1 : -1);
$direction = ($final === 1 ? 'long' : 'short');
logMsg("Score={$score} → final direction: {$direction}");

// write results
file_put_contents($jsonFile, json_encode(['trend' => $direction]));
logMsg("Wrote super.json and done.\n");

// output to browser
echo "<pre>Trend: {$direction}</pre>";
