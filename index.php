<?php
/**
 * Agenda — Horários Livres
 * Junta vários calendários (.ics) no servidor, calcula a disponibilidade e mostra
 * uma grade semanal com os horários LIVRES. Só expõe livre/ocupado — nunca títulos.
 */

date_default_timezone_set('UTC');
$CFG = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : require __DIR__ . '/config.sample.php';
$TZ  = new DateTimeZone($CFG['tz'] ?? 'America/Sao_Paulo');

/* ===================== fuso Windows (Microsoft) → IANA ===================== */
function ig_win_tz($name) {
    $map = [
        'E. South America Standard Time' => 'America/Sao_Paulo',
        'SA Eastern Standard Time'       => 'America/Fortaleza',
        'Central Brazilian Standard Time'=> 'America/Cuiaba',
        'SA Pacific Standard Time'       => 'America/Bogota',
        'Pacific SA Standard Time'       => 'America/Santiago',
        'Argentina Standard Time'        => 'America/Argentina/Buenos_Aires',
        'UTC'                            => 'UTC',
        'GMT Standard Time'              => 'Europe/London',
        'W. Europe Standard Time'        => 'Europe/Berlin',
        'Romance Standard Time'          => 'Europe/Paris',
        'Central Europe Standard Time'   => 'Europe/Budapest',
        'Eastern Standard Time'          => 'America/New_York',
        'Central Standard Time'          => 'America/Chicago',
        'Mountain Standard Time'         => 'America/Denver',
        'Pacific Standard Time'          => 'America/Los_Angeles',
    ];
    return $map[$name] ?? null;
}
function ig_tz($tzid, DateTimeZone $fallback) {
    if ($tzid === null || $tzid === '') return $fallback;
    $tzid = trim($tzid, "\"'");
    try { return new DateTimeZone($tzid); } catch (Exception $e) {}
    $iana = ig_win_tz($tzid);
    if ($iana) { try { return new DateTimeZone($iana); } catch (Exception $e) {} }
    return $fallback;
}

/* ===================== fetch com cache ===================== */
function ig_fetch($url, $ttl) {
    if (strpos($url, 'http') !== 0) { return @file_get_contents($url) ?: ''; } // caminho local (testes)
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $cf = $dir . '/' . md5($url) . '.ics';
    if (is_file($cf) && (time() - filemtime($cf)) < $ttl) {
        $c = @file_get_contents($cf); if ($c !== false && $c !== '') return $c;
    }
    $data = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'Mozilla/5.0 (AgendaLivre)',
            CURLOPT_SSL_VERIFYPEER => true]);
        $data = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code < 200 || $code >= 300) { $data = ''; }
    }
    if ($data === '') {
        $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: Mozilla/5.0 (AgendaLivre)\r\n"]]);
        $data = (string) @file_get_contents($url, false, $ctx);
    }
    if ($data !== '' && strpos($data, 'BEGIN:VCALENDAR') !== false) { @file_put_contents($cf, $data); return $data; }
    if (is_file($cf)) { return (string) @file_get_contents($cf); } // stale fallback
    return $data;
}

/* ===================== parsing ICS ===================== */
function ig_unfold($txt) {
    $txt = str_replace(["\r\n", "\r"], "\n", $txt);
    return preg_replace("/\n[ \t]/", '', $txt); // desdobra linhas continuadas
}
/** Extrai blocos VEVENT (ignora VTIMEZONE etc.). */
function ig_vevents($ics) {
    $out = []; $n = preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $ics, $m);
    if ($n) { foreach ($m[1] as $b) { $out[] = $b; } }
    return $out;
}
/** Linhas → [ [name, paramsAssoc, value], ... ] */
function ig_lines($block) {
    $res = [];
    foreach (explode("\n", $block) as $ln) {
        if ($ln === '') continue;
        $cpos = strpos($ln, ':'); if ($cpos === false) continue;
        $left = substr($ln, 0, $cpos); $val = substr($ln, $cpos + 1);
        $parts = explode(';', $left); $name = strtoupper(array_shift($parts));
        $params = [];
        foreach ($parts as $p) { $kv = explode('=', $p, 2); if (count($kv) === 2) $params[strtoupper($kv[0])] = $kv[1]; }
        $res[] = [$name, $params, $val];
    }
    return $res;
}
/** Converte DATE/DATE-TIME do ICS em timestamp (UTC) + flag all-day. */
function ig_parse_dt($val, $params, DateTimeZone $disp) {
    $val = trim($val);
    if ((isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $val)) {
        $dt = DateTime::createFromFormat('Ymd', substr($val, 0, 8), $disp);
        if (!$dt) return null; $dt->setTime(0, 0, 0);
        return ['ts' => $dt->getTimestamp(), 'allday' => true];
    }
    if (preg_match('/^(\d{8}T\d{6})Z$/', $val, $mm)) {
        $dt = DateTime::createFromFormat('Ymd\THis', $mm[1], new DateTimeZone('UTC'));
    } elseif (preg_match('/^(\d{8}T\d{6})$/', $val, $mm)) {
        $tz = ig_tz($params['TZID'] ?? null, $disp);
        $dt = DateTime::createFromFormat('Ymd\THis', $mm[1], $tz);
    } else { return null; }
    if (!$dt) return null;
    return ['ts' => $dt->getTimestamp(), 'allday' => false];
}
function ig_parse_duration($s) { // PnDTnHnMnS → segundos
    if (!preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($s), $m)) return 0;
    return ((int)($m[1]??0))*604800 + ((int)($m[2]??0))*86400 + ((int)($m[3]??0))*3600 + ((int)($m[4]??0))*60 + (int)($m[5]??0);
}

/**
 * Lê um .ics e devolve intervalos OCUPADOS [ [start_ts, end_ts], ... ] que tocam a janela.
 * Expande recorrências (RRULE) dentro da janela. Pula TRANSPARENT e CANCELLED.
 */
function ig_busy_from_ics($ics, DateTimeZone $disp, $winStart, $winEnd) {
    $busy = [];
    foreach (ig_vevents(ig_unfold($ics)) as $block) {
        $lines = ig_lines($block);
        $dtstart = null; $dtend = null; $durVal = null; $rrule = null; $exdates = []; $transp = false; $cancelled = false;
        foreach ($lines as $l) {
            list($name, $params, $val) = $l;
            if ($name === 'DTSTART')      $dtstart = ig_parse_dt($val, $params, $disp);
            elseif ($name === 'DTEND')    $dtend   = ig_parse_dt($val, $params, $disp);
            elseif ($name === 'DURATION') $durVal  = ig_parse_duration($val);
            elseif ($name === 'RRULE')    $rrule   = $val;
            elseif ($name === 'TRANSP')   $transp  = (strtoupper(trim($val)) === 'TRANSPARENT');
            elseif ($name === 'STATUS')   $cancelled = (strtoupper(trim($val)) === 'CANCELLED');
            elseif ($name === 'EXDATE')   { foreach (explode(',', $val) as $ev) { $d = ig_parse_dt($ev, $params, $disp); if ($d) $exdates[$d['ts']] = true; } }
        }
        if (!$dtstart || $transp || $cancelled) continue;
        $start = $dtstart['ts'];
        if ($dtend)            $dur = max(0, $dtend['ts'] - $start);
        elseif ($durVal !== null) $dur = $durVal;
        elseif ($dtstart['allday']) $dur = 86400;
        else                   $dur = 0;
        if ($dur <= 0 && !$dtstart['allday']) continue; // instante sem duração não ocupa

        $starts = $rrule ? ig_expand_rrule($start, $rrule, $exdates, $winStart, $winEnd, $disp)
                         : [$start];
        foreach ($starts as $s) {
            $e = $s + $dur;
            if ($e > $winStart && $s < $winEnd) { $busy[] = [$s, $e]; }
        }
    }
    return $busy;
}

/** Expande RRULE em timestamps de início dentro da janela (robusto e limitado). */
function ig_expand_rrule($start, $rrule, $exdates, $winStart, $winEnd, DateTimeZone $disp) {
    $r = [];
    foreach (explode(';', $rrule) as $p) { $kv = explode('=', $p, 2); if (count($kv) === 2) $r[strtoupper($kv[0])] = $kv[1]; }
    $freq = strtoupper($r['FREQ'] ?? ''); if ($freq === '') return [$start];
    $interval = max(1, (int)($r['INTERVAL'] ?? 1));
    $count = isset($r['COUNT']) ? (int)$r['COUNT'] : null;
    $until = null;
    if (isset($r['UNTIL'])) { $d = ig_parse_dt($r['UNTIL'], [], new DateTimeZone('UTC')); $until = $d ? $d['ts'] : null; }
    $byday = isset($r['BYDAY']) ? array_map('strtoupper', explode(',', $r['BYDAY'])) : [];
    $wmap = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];

    $out = []; $emitted = 0; $iter = 0; $CAP = 4000;
    $base = (new DateTime('@' . $start))->setTimezone($disp);
    $timeH = (int)$base->format('H'); $timeM = (int)$base->format('i'); $timeS = (int)$base->format('s');

    $emit = function($ts) use (&$out, &$emitted, $exdates, $winStart, $winEnd) {
        if (isset($exdates[$ts])) return;
        if ($ts < $winEnd && $ts >= $winStart - 86400*2) { $out[] = $ts; }
        $emitted++;
    };
    $stop = function($ts) use ($until, $count, &$emitted, $winEnd) {
        if ($until !== null && $ts > $until) return true;
        if ($count !== null && $emitted >= $count) return true;
        if ($ts > $winEnd + 86400) return true;
        return false;
    };

    if ($freq === 'WEEKLY') {
        if (!$byday) { $byday = [array_search((int)$base->format('N'), $wmap)]; }
        // início da semana (segunda) da 1ª ocorrência
        $weekStart = clone $base; $weekStart->modify('-' . (((int)$base->format('N')) - 1) . ' days');
        $weekStart->setTime(0,0,0);
        for ($w = 0; $iter < $CAP; $w += $interval) {
            $anyFuture = false;
            foreach ($byday as $bd) { $wd = $wmap[substr($bd, -2)] ?? null; if (!$wd) continue;
                $occ = clone $weekStart; $occ->modify('+' . ($w*7 + ($wd-1)) . ' days'); $occ->setTime($timeH,$timeM,$timeS);
                $ts = $occ->getTimestamp(); $iter++;
                if ($ts < $start) continue;
                if ($stop($ts)) { return $out; }
                $anyFuture = true; $emit($ts);
                if ($count !== null && $emitted >= $count) return $out;
            }
            if (!$anyFuture && ($weekStart->getTimestamp() + $w*7*86400) > $winEnd + 86400) break;
        }
        return $out;
    }

    // DAILY / MONTHLY / YEARLY (passo simples a partir do início)
    $stepSpec = ['DAILY' => 'days', 'MONTHLY' => 'months', 'YEARLY' => 'years'][$freq] ?? 'days';
    // fast-forward (só quando não há COUNT) p/ não iterar desde datas muito antigas
    $cur = clone $base;
    if ($count === null && $freq === 'DAILY' && $start < $winStart) {
        $skip = intdiv(($winStart - $start), 86400 * $interval); $cur->modify('+' . ($skip*$interval) . ' days');
    }
    for (; $iter < $CAP; $iter++) {
        $ts = $cur->getTimestamp();
        if ($stop($ts)) break;
        if ($ts >= $start) { $emit($ts); if ($count !== null && $emitted >= $count) break; }
        $cur->modify('+' . $interval . ' ' . $stepSpec);
    }
    return $out;
}

/* ===================== merge + janela útil + livres ===================== */
function ig_merge($ints) {
    if (!$ints) return [];
    usort($ints, fn($a, $b) => $a[0] <=> $b[0]);
    $out = [$ints[0]];
    for ($i = 1; $i < count($ints); $i++) {
        $last = &$out[count($out) - 1];
        if ($ints[$i][0] <= $last[1]) { if ($ints[$i][1] > $last[1]) $last[1] = $ints[$i][1]; }
        else { $out[] = $ints[$i]; }
    }
    return $out;
}
/** Segmentos (livre/ocupado) que preenchem [ws,we]. */
function ig_segments($ws, $we, $busyMerged) {
    $segs = []; $cur = $ws;
    foreach ($busyMerged as $b) {
        $s = max($ws, $b[0]); $e = min($we, $b[1]); if ($e <= $s) continue;
        if ($s > $cur) $segs[] = ['free', $cur, $s];
        $segs[] = ['busy', $s, $e]; $cur = $e;
    }
    if ($cur < $we) $segs[] = ['free', $cur, $we];
    if (!$segs) $segs[] = ['free', $ws, $we];
    return $segs;
}

/* ===================== coleta dados ===================== */
$now = new DateTime('now', $TZ);
$today = (clone $now)->setTime(0, 0, 0);
$defWeeks   = max(1, (int) ceil(((int)($CFG['horizon_days'] ?? 14)) / 7));
$weeks      = max(1, min(12, (int)($_GET['weeks'] ?? $defWeeks)));
// Janela = da semana atual (a partir de hoje) até o FIM da N-ésima semana, alinhada
// ao fim da semana (domingo) para que cada semana apareça completa, sem corte no meio.
$lastAnchor = (clone $today)->modify('+' . (($weeks - 1) * 7) . ' days');
$toMon      = 8 - (int) $lastAnchor->format('N');  // dias até a próxima segunda (fim exclusivo)
$winEnd     = (clone $lastAnchor)->modify('+' . $toMon . ' days')->setTime(0, 0, 0);
$winStartTs = $today->getTimestamp();
$winEndTs   = $winEnd->getTimestamp();

$allBusy = []; $errors = [];
foreach (($CFG['sources'] ?? []) as $key => $url) {
    if (!$url) continue;
    $ics = ig_fetch($url, (int)($CFG['cache_ttl'] ?? 900));
    if ($ics === '' || strpos($ics, 'BEGIN:VCALENDAR') === false) { $errors[] = $key; continue; }
    $allBusy = array_merge($allBusy, ig_busy_from_ics($ics, $TZ, $winStartTs, $winEndTs));
}

list($wh1, $wm1) = array_map('intval', explode(':', $CFG['work_start'] ?? '08:00'));
list($wh2, $wm2) = array_map('intval', explode(':', $CFG['work_end'] ?? '19:00'));
$workDays = $CFG['work_days'] ?? [1,2,3,4,5];
$minSlot  = (int)($CFG['min_slot_min'] ?? 30) * 60;

// monta os dias úteis da janela, agrupados por semana (ISO)
$days = []; $cursor = clone $today;
while ($cursor->getTimestamp() < $winEndTs) {
    $wd = (int)$cursor->format('N');
    if (in_array($wd, $workDays, true)) {
        $ws = (clone $cursor)->setTime($wh1, $wm1, 0)->getTimestamp();
        $we = (clone $cursor)->setTime($wh2, $wm2, 0)->getTimestamp();
        $dayBusy = ig_merge(array_filter($allBusy, fn($b) => $b[1] > $ws && $b[0] < $we));
        $segs = ig_segments($ws, $we, $dayBusy);
        $days[(int)$cursor->format('oW')][] = [
            'dt' => clone $cursor, 'ws' => $ws, 'we' => $we, 'segs' => $segs,
        ];
    }
    $cursor->modify('+1 day');
}

$accent = $CFG['accent'] ?? '#6D4AFF';
$dowPt = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
$dowFull = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];
$fmtH = function($ts) use ($TZ) { $d = (new DateTime('@' . $ts))->setTimezone($TZ); return $d->format('H:i'); };

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noarchive'); // não indexar (reforça a meta tag)
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($CFG['title'] ?? 'Horários livres') ?></title>
<style>
  :root { --accent: <?= htmlspecialchars($accent) ?>; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #f4f3f8; color: #2b2140;
         font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
  .wrap { max-width: 1180px; margin: 0 auto; padding: 24px 16px 48px; }
  header.pg { text-align: center; margin-bottom: 20px; }
  header.pg h1 { margin: 0 0 4px; font-size: 24px; letter-spacing: -.01em; color: #1b1140; }
  header.pg p { margin: 0; color: #6a6485; font-size: 14px; }
  .legend { display: flex; gap: 16px; justify-content: center; align-items: center; margin: 14px 0 6px; flex-wrap: wrap; font-size: 13px; color: #6a6485; }
  .legend b { display: inline-block; width: 14px; height: 14px; border-radius: 4px; vertical-align: -2px; margin-right: 5px; }
  .lg-free { background: #d9f7e6; border: 1px solid #7fd6a6; }
  .lg-busy { background: #e7e3ef; border: 1px solid #c9c2da; }
  .upd { text-align: center; font-size: 12px; color: #a99fc0; margin-bottom: 20px; }
  .week { margin-bottom: 26px; }
  .week h2 { font-size: 14px; color: var(--accent); margin: 0 0 10px; font-weight: 800; letter-spacing: .02em; }
  .cols { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 6px; }
  .day { flex: 1 1 0; min-width: 132px; background: #fff; border: 1px solid #ececf1; border-radius: 12px;
         overflow: hidden; box-shadow: 0 2px 10px rgba(27,17,64,.04); }
  .day-h { padding: 9px 10px; text-align: center; border-bottom: 1px solid #f0eef6; }
  .day-h .dow { font-weight: 800; font-size: 13px; color: #1b1140; }
  .day-h .dte { font-size: 12px; color: #9a95ad; }
  .day-h.today { background: var(--accent); }
  .day-h.today .dow, .day-h.today .dte { color: #fff; }
  .track { position: relative; height: 520px; display: flex; flex-direction: column; }
  .seg { display: flex; align-items: center; justify-content: center; font-size: 11.5px; overflow: hidden; padding: 0 4px; text-align: center; }
  .seg.free { background: #e9faf1; color: #0e7a4a; font-weight: 700; border-top: 1px solid #c7eed8; }
  .seg.free:first-child { border-top: 0; }
  .seg.busy { background: repeating-linear-gradient(45deg,#edeaf4,#edeaf4 6px,#e4e0ee 6px,#e4e0ee 12px); color: #9a92ad; }
  .seg small { font-weight: 400; opacity: .8; }
  .seg.free.click { cursor: pointer; flex-direction: column; gap: 1px; transition: background .12s; }
  .seg.free.click:hover, .seg.free.click:focus { background: #cdf2de; outline: none; }
  .seg.free .seg-cta { font-size: 10px; font-weight: 800; color: #0e7a4a; opacity: .7; }
  .seg.free.click:hover .seg-cta { opacity: 1; text-decoration: underline; }
  .loadmore { text-align: center; margin: 4px 0 26px; }
  .loadmore a { display: inline-block; text-decoration: none; border: 1.5px solid var(--accent); color: var(--accent);
                font-weight: 700; font-size: 14px; padding: 10px 20px; border-radius: 10px; background: #fff; }
  .loadmore a:hover { background: var(--accent); color: #fff; }
  .empty { text-align: center; color: #a99fc0; padding: 40px 0; }
  /* modal de reserva */
  .bk { position: fixed; inset: 0; background: rgba(27,17,64,.5); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
  .bk[hidden] { display: none; }
  .bk-card { background: #fff; border-radius: 16px; width: 100%; max-width: 380px; padding: 22px 20px; position: relative; max-height: 88vh; overflow: auto; box-shadow: 0 20px 60px rgba(27,17,64,.32); }
  .bk-x { position: absolute; top: 8px; right: 12px; border: 0; background: none; font-size: 26px; line-height: 1; color: #9a95ad; cursor: pointer; }
  .bk-day { font-weight: 800; font-size: 18px; color: #1b1140; text-transform: capitalize; }
  .bk-win { color: #6a6485; font-size: 13px; margin: 2px 0 6px; }
  .bk-lbl { font-size: 11px; font-weight: 800; color: #8a84a3; margin: 14px 0 7px; text-transform: uppercase; letter-spacing: .04em; }
  .bk-starts, .bk-durs { display: flex; flex-wrap: wrap; gap: 7px; }
  .chip { border: 1px solid #d9d4e6; background: #faf9fc; color: #2b2140; border-radius: 9px; padding: 7px 11px; font-size: 13px; font-weight: 600; cursor: pointer; }
  .chip:hover { border-color: var(--accent); }
  .chip.sel { background: var(--accent); color: #fff; border-color: var(--accent); }
  .bk-sum { margin: 16px 0 10px; font-size: 14px; color: #1b1140; background: #f3f0fb; border-radius: 10px; padding: 10px 12px; text-align: center; font-weight: 700; }
  .bk-send { display: block; text-align: center; text-decoration: none; background: #25d366; color: #fff; font-weight: 800; padding: 13px; border-radius: 12px; font-size: 15px; }
  .bk-send[hidden], .bk-durlbl[hidden], .bk-sum[hidden] { display: none; }
  footer.pg { text-align: center; font-size: 11.5px; color: #a99fc0; margin-top: 10px; line-height: 1.6; }
  @media (max-width: 640px) { .day { min-width: 120px; } .track { height: 440px; } }
</style>
</head>
<body>
<div class="wrap">
  <header class="pg">
    <h1><?= htmlspecialchars($CFG['title'] ?? 'Horários livres') ?></h1>
    <p><?= htmlspecialchars($CFG['subtitle'] ?? '') ?></p>
    <div class="legend">
      <span><b class="lg-free"></b>Livre</span>
      <span><b class="lg-busy"></b>Ocupado</span>
      <span>🕗 <?= $wh1 ?>h–<?= $wh2 ?>h · Seg–Sex</span>
    </div>
  </header>
  <div class="upd">Fuso <?= htmlspecialchars($CFG['tz']) ?> · atualizado <?= $now->format('d/m/Y H:i') ?><?= $errors ? ' · ⚠ falha ao ler: ' . htmlspecialchars(implode(', ', $errors)) : '' ?></div>

  <?php if (!$days): ?>
    <div class="empty">Sem dias úteis na janela.</div>
  <?php else: foreach ($days as $week): ?>
    <?php $first = $week[0]['dt']; $last = end($week)['dt']; reset($week); ?>
    <div class="week">
      <h2>Semana de <?= $first->format('d/m') ?> a <?= $last->format('d/m') ?></h2>
      <div class="cols">
        <?php foreach ($week as $d):
          $isToday = $d['dt']->format('Y-m-d') === $now->format('Y-m-d');
          $total = $d['we'] - $d['ws']; ?>
          <div class="day">
            <div class="day-h<?= $isToday ? ' today' : '' ?>">
              <div class="dow"><?= $dowPt[(int)$d['dt']->format('N')] ?></div>
              <div class="dte"><?= $d['dt']->format('d/m') ?></div>
            </div>
            <div class="track">
              <?php foreach ($d['segs'] as $sg):
                list($type, $s, $e) = $sg; $pct = $total > 0 ? (($e - $s) / $total * 100) : 0;
                $durm = (int) round(($e - $s) / 60); $long = ($e - $s) >= 1500;
                if ($type === 'free' && $durm >= 15):
                  $sdt = (new DateTime('@' . $s))->setTimezone($TZ);
                  $sm = (int) $sdt->format('H') * 60 + (int) $sdt->format('i'); $em = $sm + $durm; ?>
                  <div class="seg free click" style="height: <?= round($pct, 3) ?>%" role="button" tabindex="0"
                       data-dow="<?= $dowFull[(int) $d['dt']->format('N')] ?>" data-date="<?= $d['dt']->format('d/m') ?>"
                       data-s="<?= $sm ?>" data-e="<?= $em ?>">
                    <?php if ($long): ?><span class="seg-t"><?= $fmtH($s) ?>–<?= $fmtH($e) ?></span><span class="seg-cta">reservar ›</span><?php endif; ?>
                  </div>
                <?php elseif ($type === 'free'): ?>
                  <div class="seg free" style="height: <?= round($pct, 3) ?>%"></div>
                <?php else: ?>
                  <div class="seg busy" style="height: <?= round($pct, 3) ?>%"><?php if (($e - $s) >= 2400): ?><small>ocupado</small><?php endif; ?></div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <?php if ($days && $weeks < 12): $more = min(12, $weeks + 2); $add = $more - $weeks; ?>
    <div class="loadmore" id="fim">
      <a href="?weeks=<?= $more ?>#fim">+ carregar mais <?= $add ?> semana<?= $add > 1 ? 's' : '' ?></a>
    </div>
  <?php endif; ?>

  <footer class="pg">
    Toque em um horário <strong>livre</strong> para pedir a reserva.<br>
    Mostra apenas livre/ocupado — os detalhes dos compromissos são privados.
  </footer>
</div>

<div id="bk" class="bk" hidden>
  <div class="bk-card">
    <button class="bk-x" aria-label="Fechar">&times;</button>
    <div class="bk-day"></div>
    <div class="bk-win"></div>
    <div class="bk-lbl">Horário de início</div>
    <div class="bk-starts"></div>
    <div class="bk-lbl bk-durlbl" hidden>Duração</div>
    <div class="bk-durs"></div>
    <div class="bk-sum" hidden></div>
    <a class="bk-send" hidden target="_blank" rel="noopener">📲 Pedir reserva no WhatsApp</a>
  </div>
</div>

<script>
(function () {
  var WA = <?= json_encode((string)($CFG['whatsapp'] ?? '')) ?>;
  var modal = document.getElementById('bk'); if (!modal) return;
  var elDay = modal.querySelector('.bk-day'), elWin = modal.querySelector('.bk-win'),
      elStarts = modal.querySelector('.bk-starts'), elDurs = modal.querySelector('.bk-durs'),
      elDurLbl = modal.querySelector('.bk-durlbl'), elSum = modal.querySelector('.bk-sum'),
      elSend = modal.querySelector('.bk-send');
  var cur = null, selStart = null;
  function f(m) { var h = Math.floor(m / 60), mm = m % 60; return (h < 10 ? '0' : '') + h + ':' + (mm < 10 ? '0' : '') + mm; }
  function durLabel(d) { return d < 60 ? d + ' min' : (Math.floor(d / 60) + 'h' + (d % 60 ? d % 60 : '')); }
  function open(el) {
    cur = { dow: el.getAttribute('data-dow'), date: el.getAttribute('data-date'), s: +el.getAttribute('data-s'), e: +el.getAttribute('data-e') };
    selStart = null;
    elDay.textContent = cur.dow + ', ' + cur.date;
    elWin.textContent = 'Livre das ' + f(cur.s) + ' às ' + f(cur.e);
    elStarts.innerHTML = ''; elDurs.innerHTML = ''; elDurLbl.hidden = true; elSum.hidden = true; elSend.hidden = true;
    for (var m = cur.s; m + 15 <= cur.e; m += 15) (function (mm) {
      var c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.textContent = f(mm);
      c.onclick = function () {
        selStart = mm;
        [].forEach.call(elStarts.children, function (x) { x.classList.remove('sel'); }); c.classList.add('sel');
        buildDurs();
      };
      elStarts.appendChild(c);
    })(m);
    modal.hidden = false;
  }
  function buildDurs() {
    elDurs.innerHTML = ''; elDurLbl.hidden = false; elSum.hidden = true; elSend.hidden = true;
    var avail = cur.e - selStart;
    [15, 30, 45, 60, 90, 120, 180].forEach(function (d) {
      if (d > avail) return;
      var c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.textContent = durLabel(d);
      c.onclick = function () {
        [].forEach.call(elDurs.children, function (x) { x.classList.remove('sel'); }); c.classList.add('sel');
        showSum(d);
      };
      elDurs.appendChild(c);
    });
  }
  function showSum(d) {
    var end = selStart + d;
    elSum.hidden = false; elSum.textContent = f(selStart) + ' às ' + f(end) + ' (' + durLabel(d) + ')';
    var msg = 'Olá Rafa! Gostaria de reservar um horário na sua agenda:\n📅 ' + cur.dow + ', ' + cur.date +
              '\n🕐 ' + f(selStart) + ' às ' + f(end) + ' (' + durLabel(d) + ')\nPode confirmar?';
    elSend.href = 'https://wa.me/' + WA + '?text=' + encodeURIComponent(msg);
    elSend.hidden = false;
  }
  function close() { modal.hidden = true; }
  modal.querySelector('.bk-x').onclick = close;
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  [].forEach.call(document.querySelectorAll('.seg.free.click'), function (el) {
    el.addEventListener('click', function () { open(el); });
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(el); } });
  });
})();
</script>
</body>
</html>
