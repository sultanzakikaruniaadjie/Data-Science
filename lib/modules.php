<?php
// ---- statistik dasar ----
function st_mean($a) { return array_sum($a) / count($a); }
function st_median($a) { sort($a); $n = count($a); $m = intdiv($n, 2); return $n % 2 ? $a[$m] : ($a[$m - 1] + $a[$m]) / 2; }
function st_q($a, $p) { sort($a); $n = count($a); if ($n == 1) return $a[0]; $pos = ($n - 1) * $p; $lo = (int)floor($pos); $hi = (int)ceil($pos); return $a[$lo] + ($a[$hi] - $a[$lo]) * ($pos - $lo); }
function st_sd($a, $samp) { $n = count($a); $m = st_mean($a); $s = 0; foreach ($a as $x) $s += ($x - $m) * ($x - $m); return sqrt($s / ($samp ? $n - 1 : $n)); }
function st_mode($a) {
  $f = []; foreach ($a as $x) { $k = (string)$x; $f[$k] = ($f[$k] ?? 0) + 1; }
  $mx = max($f); if ($mx == 1) return null;
  $m = []; foreach ($f as $k => $c) if ($c == $mx) $m[] = (float)$k;
  sort($m); return [$m, $mx];
}

// ---- 1. Pipeline ----
function mod_pipeline($d, $o) {
  list($h, $rows) = split_table($d['raw_content'], (bool)$d['has_header']);
  $rules = [];
  foreach (preg_split('/\R/', trim((string)($o['rules'] ?? ''))) as $ln) {
    $ln = trim($ln); if ($ln === '') continue;
    $p = array_map('trim', explode(',', $ln)); $nt = array_map('trim', explode(':', array_shift($p), 2));
    $r = ['name' => $nt[0], 'type' => strtolower($nt[1] ?? 'str'), 'req' => false, 'gt' => null, 'min' => null, 'max' => null];
    foreach ($p as $x) { if ($x === 'required') $r['req'] = true; elseif (preg_match('/^(min|max|gt)=(-?[\d.]+)$/', $x, $m)) $r[$m[1]] = (float)$m[2]; }
    $rules[] = $r;
  }
  if (!$rules) return [false, 'Aturan validasi kosong.', []];
  $names = array_column($rules, 'name'); $f = null;
  if (preg_match('/^(\w+)\s*=\s*(\w+)\s*([*+\/-])\s*(\w+)$/', trim((string)($o['formula'] ?? '')), $m)) {
    $a = array_search($m[2], $names); $b = array_search($m[4], $names);
    if ($a === false || $b === false) return [false, 'Kolom pada rumus tidak ada di aturan validasi.', []];
    $f = [$m[1], $a, $m[3], $b];
  }
  $ok = []; $bad = []; $off = $d['has_header'] ? 2 : 1;
  foreach ($rows as $i => $r) {
    $e = null;
    if (count($r) !== count($rules)) $e = 'jumlah kolom ' . count($r) . ' (harus ' . count($rules) . ')';
    else foreach ($rules as $k => $ru) {
      $v = $r[$k]; $nm = $ru['name'];
      if ($v === '') { if ($ru['req'] || $ru['type'] !== 'str') { $e = "$nm kosong"; break; } continue; }
      if ($ru['type'] === 'int' && !preg_match('/^-?\d+$/', $v)) { $e = "$nm bukan integer"; break; }
      if ($ru['type'] === 'num' && !is_numeric($v)) { $e = "$nm bukan angka"; break; }
      if ($ru['type'] === 'int' || $ru['type'] === 'num') {
        $x = (float)$v;
        if ($ru['gt'] !== null && !($x > $ru['gt'])) { $e = "$nm harus > " . fnum($ru['gt']); break; }
        if ($ru['min'] !== null && $x < $ru['min']) { $e = "$nm harus >= " . fnum($ru['min']); break; }
        if ($ru['max'] !== null && $x > $ru['max']) { $e = "$nm harus <= " . fnum($ru['max']); break; }
      }
    }
    if ($e) $bad[] = 'baris ' . ($i + $off) . ": $e";
    else {
      if ($f) { $x = (float)$r[$f[1]]; $y = (float)$r[$f[3]]; $r[] = $f[2] === '*' ? $x * $y : ($f[2] === '+' ? $x + $y : ($f[2] === '-' ? $x - $y : ($y != 0 ? $x / $y : 0))); }
      $ok[] = $r;
    }
  }
  $s = "TAHAP 1  VALIDASI\n  baris masuk : " . count($rows) . "\n  lolos       : " . count($ok) . "\n  ditolak     : " . count($bad) . "\n";
  foreach ($bad as $b) $s .= "    - $b\n";
  $s .= "\nTAHAP 2  DIPROSES\n  " . ($f ? "kolom {$f[0]} = {$names[$f[1]]} {$f[2]} {$names[$f[3]]} dihitung untuk " . count($ok) . ' baris' : 'tidak ada kolom turunan') . "\n\nTAHAP 3  SIAP ANALISIS\n  " . count($ok) . " baris\n\n";
  $hh = $names; if ($f) $hh[] = $f[0];
  $sum = ['masuk' => count($rows), 'lolos' => count($ok), 'ditolak' => count($bad)];
  if ($ok) {
    $s .= table_text($hh, $ok, 14);
    if ($f) { $t = 0; foreach ($ok as $r) $t += end($r); $s .= "\nTotal {$f[0]}: " . fnum($t, 2); $sum['total'] = $t; }
    if (!empty($o['save'])) {
      $id = save_dataset($d['name'] . ' (valid)', 'derived', to_csv($hh, $ok), 1, (int)$d['id']); $sum['derived_id'] = $id; $s .= "\nDisimpan sebagai dataset #$id";
    }
  }
  return [true, $s, $sum];
}

// ---- 2. Eksplorasi ----
function mod_explore($d, $o) {
  list($h, $rows) = split_table($d['raw_content'], (bool)$d['has_header']);
  $n = count($rows); $head = max(1, (int)($o['head'] ?? 5)); $p = profile($h, $rows);
  $dup = $n - count(array_unique(array_map(function ($r) { return implode("\x1f", $r); }, $rows)));
  $s = "Jumlah total rekaman : $n\nJumlah kolom         : " . count($h) . "\nNama kolom           : " . implode(', ', $h) . "\nBaris duplikat       : $dup\nData hilang          : {$p['missing_pct']}%\n\nHead ($head baris pertama):\n";
  $s .= table_text($h, array_slice($rows, 0, $head)) . "\nTipe data per kolom:\n" . str_pad('kolom', 18) . str_pad('tipe', 9) . str_pad('kosong', 8) . str_pad('unik', 7) . "min / mean / max\n";
  foreach ($p['cols'] as $c)
    $s .= str_pad($c['name'], 18) . str_pad($c['type'], 9) . str_pad($c['missing'], 8) . str_pad($c['unique'], 7) . (isset($c['mean']) ? fnum($c['min']) . ' / ' . fnum($c['mean'], 2) . ' / ' . fnum($c['max']) : '-') . "\n";
  return [true, $s, ['rows' => $n, 'cols' => count($h), 'missing_pct' => $p['missing_pct'], 'dups' => $dup]];
}

// ---- 3. Pembersihan & transformasi ----
function mod_clean($d, $o) {
  list($h, $rows) = split_table($d['raw_content'], (bool)$d['has_header']);
  $idx = pick_cols($h, $rows, (string)($o['kolom'] ?? ''));
  if (!$idx) return [false, 'Tidak ada kolom numerik untuk dibersihkan.', []];
  $imp = $o['impute'] ?? 'mean'; $sc = $o['scale'] ?? 'minmax'; $ol = $o['outlier'] ?? 'none';
  $out = []; $dropped = 0;
  foreach ($rows as $r) {
    $r = array_pad($r, count($h), '');
    if ($imp === 'drop') { $skip = false; foreach ($idx as $i) if (is_missing($r[$i])) { $skip = true; break; } if ($skip) { $dropped++; continue; } }
    $out[] = $r;
  }
  $newH = $h; $s = "Imputasi: $imp | Outlier: $ol | Skala: $sc\n" . ($dropped ? "Baris dibuang (missing): $dropped\n" : '') . "\n";
  $s .= str_pad('kolom', 18) . str_pad('valid', 7) . str_pad('missing', 9) . str_pad('isi', 10) . str_pad('outlier', 9) . "min..max (setelah)\n";
  $sum = ['kolom' => []];
  foreach ($idx as $i) {
    $valid = []; foreach ($out as $r) if (!is_missing($r[$i])) $valid[] = (float)$r[$i];
    if (!$valid) continue;
    if ($imp === 'median') $fill = st_median($valid); elseif ($imp === 'zero') $fill = 0.0;
    elseif ($imp === 'mode') { $md = st_mode($valid); $fill = $md ? $md[0][0] : st_mean($valid); } else $fill = st_mean($valid);
    $nm = 0;
    foreach ($out as &$r) { if (is_missing($r[$i])) { $r[$i] = (float)$fill; $nm++; } else $r[$i] = (float)$r[$i]; } unset($r);
    $all = array_column($out, $i); $q1 = st_q($all, .25); $q3 = st_q($all, .75); $iq = $q3 - $q1; $lo = $q1 - 1.5 * $iq; $hi = $q3 + 1.5 * $iq; $no = 0;
    foreach ($out as &$r) { if ($r[$i] < $lo || $r[$i] > $hi) { $no++; if ($ol === 'clip') $r[$i] = max($lo, min($hi, $r[$i])); } } unset($r);
    $all = array_column($out, $i); $mn = min($all); $mx = max($all);
    if ($sc !== 'none') {
      $mean = st_mean($all); $sd = st_sd($all, false); $newH[] = $h[$i] . '_' . $sc; $ci = count($newH) - 1;
      foreach ($out as &$r) { $r[$ci] = $sc === 'minmax' ? ($mx == $mn ? 0.0 : ($r[$i] - $mn) / ($mx - $mn)) : ($sd == 0 ? 0.0 : ($r[$i] - $mean) / $sd); } unset($r);
    }
    $s .= str_pad($h[$i], 18) . str_pad(count($valid), 7) . str_pad($nm, 9) . str_pad($nm ? fnum($fill, 2) : '-', 10) . str_pad($no, 9) . fnum($mn, 2) . ' .. ' . fnum($mx, 2) . "\n";
    $sum['kolom'][] = ['nama' => $h[$i], 'missing' => $nm, 'isi' => $fill, 'outlier' => $no];
  }
  $s .= "\nPratinjau hasil (10 baris pertama):\n" . table_text($newH, array_slice($out, 0, 10), 15);
  $sum['rows'] = count($out);
  if (!empty($o['save'])) { $id = save_dataset($d['name'] . ' (bersih)', 'derived', to_csv($newH, $out), 1, (int)$d['id']); $sum['derived_id'] = $id; $s .= "\nDisimpan sebagai dataset #$id"; }
  return [true, $s, $sum];
}

// ---- 4. Statistik deskriptif + ASCII chart ----
function mod_stats($d, $o) {
  list($h, $rows) = split_table($d['raw_content'], (bool)$d['has_header']);
  $idx = pick_cols($h, $rows, (string)($o['kolom'] ?? ''));
  if (!$idx) return [false, 'Tidak ada kolom numerik.', []];
  $samp = !empty($o['sample']); $ch = ($o['char'] ?? '') !== '' ? substr($o['char'], 0, 1) : '#'; $s = ''; $sum = [];
  foreach ($idx as $i) {
    $v = []; $miss = 0;
    foreach ($rows as $r) { $x = $r[$i] ?? ''; if (is_missing($x) || !is_numeric($x)) $miss++; else $v[] = (float)$x; }
    $n = count($v);
    if ($n < 1 || ($samp && $n < 2)) { $s .= "== {$h[$i]} ==\n  data tidak cukup\n\n"; continue; }
    $mean = st_mean($v); $sd = st_sd($v, $samp); $md = st_mode($v);
    $modus = $md ? implode(', ', array_map('fnum', $md[0])) . " (muncul {$md[1]}x)" : 'tidak ada (semua nilai muncul 1x)';
    $s .= "== {$h[$i]} ==\n  Jumlah data  : $n" . ($miss ? " ($miss missing diabaikan)" : '') . "\n  Mean         : " . number_format($mean, 2, '.', '') . "\n  Median       : " . number_format(st_median($v), 2, '.', '') .
      "\n  Modus        : $modus\n  Std deviasi  : " . number_format($sd, 2, '.', '') . ($samp ? ' (sampel)' : ' (populasi)') .
      "\n  Min/Q1/Q3/Max: " . fnum(min($v), 2) . ' / ' . fnum(st_q($v, .25), 2) . ' / ' . fnum(st_q($v, .75), 2) . ' / ' . fnum(max($v), 2) . "\n  Frekuensi per rentang:\n";
    $bins = [];
    if (preg_match_all('/(-?\d+(?:\.\d+)?)\s*-\s*(-?\d+(?:\.\d+)?)/', (string)($o['bins'] ?? ''), $mm, PREG_SET_ORDER))
      foreach ($mm as $m) $bins[] = [str_replace(' ', '', $m[0]), (float)$m[1], (float)$m[2], true];
    else {
      $mn = min($v); $mx = max($v); $k = max(3, min(12, (int)ceil(log($n, 2)) + 1));
      if ($mx == $mn) $bins[] = [fnum($mn), $mn, $mx, true];
      else { $w = ($mx - $mn) / $k; for ($b = 0; $b < $k; $b++) { $lo = $mn + $b * $w; $hi = $b === $k - 1 ? $mx : $lo + $w; $bins[] = [fnum($lo, 2) . '-' . fnum($hi, 2), $lo, $hi, $b === $k - 1]; } }
    }
    $cnt = []; foreach ($bins as $b) { $c = 0; foreach ($v as $x) if ($x >= $b[1] && ($b[3] ? $x <= $b[2] : $x < $b[2])) $c++; $cnt[] = $c; }
    $mc = max($cnt);
    foreach ($bins as $k2 => $b) { $len = $mc > 40 ? (int)round($cnt[$k2] / $mc * 40) : $cnt[$k2]; $s .= '    ' . str_pad($b[0], 14) . '| ' . str_repeat($ch, $len) . ' ' . $cnt[$k2] . "\n"; }
    if (array_sum($cnt) !== $n) $s .= '  ' . ($n - array_sum($cnt)) . " data berada di luar semua rentang.\n";
    $s .= "\n";
    $sum[$h[$i]] = ['n' => $n, 'mean' => round($mean, 4), 'median' => st_median($v), 'sd' => round($sd, 4)];
  }
  return [true, rtrim($s), $sum];
}

// ---- 5. Train-test split ----
function mod_split($d, $o) {
  list($h, $rows) = split_table($d['raw_content'], (bool)$d['has_header']);
  $n = count($rows); $ts = (float)($o['test_size'] ?? 0.2); $sh = !empty($o['shuffle']);
  $seed = ($o['seed'] ?? '') !== '' ? (int)$o['seed'] : random_int(1, 2147483647);
  if ($n < 2) return [false, 'Dataset terlalu kecil.', []];
  if (!($ts > 0 && $ts < 1)) return [false, 'test_size harus di antara 0 dan 1.', []];
  mt_srand($seed); $groups = [range(0, $n - 1)]; $st = trim((string)($o['stratify'] ?? ''));
  if ($st !== '') {
    $ci = array_search(strtolower($st), array_map('strtolower', $h));
    if ($ci === false) return [false, "Kolom stratify '$st' tidak ditemukan.", []];
    $groups = []; foreach ($rows as $i => $r) $groups[$r[$ci] ?? ''][] = $i;
  }
  $train = []; $test = [];
  foreach ($groups as $g) {
    if ($sh) for ($i = count($g) - 1; $i > 0; $i--) { $j = mt_rand(0, $i); $t = $g[$i]; $g[$i] = $g[$j]; $g[$j] = $t; }
    $c = count($g); $nt = (int)round($c * $ts);
    if ($sh) { $te = array_slice($g, 0, $nt); $tr = array_slice($g, $nt); } else { $tr = array_slice($g, 0, $c - $nt); $te = array_slice($g, $c - $nt); }
    $train = array_merge($train, $tr); $test = array_merge($test, $te);
  }
  if (!$train || !$test) return [false, "test_size terlalu ekstrem untuk $n data.", []];
  $inter = count(array_intersect($train, $test));
  $show = function ($a) use ($rows) { $s = ''; foreach (array_slice($a, 0, 5) as $i) $s .= '  baris ' . str_pad($i + 1, 5) . '[' . implode(', ', $rows[$i]) . "]\n"; return $s . (count($a) > 5 ? "  ...\n" : ''); };
  $s = "Total data : $n\nData latih : " . count($train) . ' baris (' . number_format(count($train) / $n * 100, 1) . "%)\nData uji   : " . count($test) . ' baris (' . number_format(count($test) / $n * 100, 1) . "%)\nShuffle    : " . ($sh ? 'true' : 'false') . "\nSeed       : $seed (pakai seed yang sama untuk hasil identik)\nIrisan train & test : $inter (harus 0)\n";
  if ($st !== '') { $s .= "\nDistribusi '$st' (train / test):\n"; foreach (array_keys($groups) as $lb) { $a = 0; $b = 0; foreach ($train as $i) if (($rows[$i][$ci] ?? '') == $lb) $a++; foreach ($test as $i) if (($rows[$i][$ci] ?? '') == $lb) $b++; $s .= "  " . str_pad($lb, 10) . "$a / $b\n"; } }
  $s .= "\nContoh data latih:\n" . $show($train) . "\nContoh data uji:\n" . $show($test) . "\nNomor baris asli data uji:\n  " . implode(', ', array_map(function ($i) { return $i + 1; }, array_slice($test, 0, 200))) . (count($test) > 200 ? ', ...' : '');
  return [true, $s, ['seed' => $seed, 'train' => $train, 'test' => $test, 'intersect' => $inter]];
}

// ---- runner: setiap eksekusi tercatat di tabel runs ----
function run_module($m, $d, $o, $batch = null) {
  if (!in_array($m, ['pipeline', 'explore', 'clean', 'stats', 'split'], true)) throw new RuntimeException('Modul tidak dikenal.');
  $t = microtime(true);
  try { list($ok, $out, $sum) = ('mod_' . $m)($d, $o); } catch (Throwable $e) { $ok = false; $out = 'Error: ' . $e->getMessage(); $sum = []; }
  q('INSERT INTO runs(batch_id,module,dataset_id,params_json,output,summary_json,status,duration_ms) VALUES(?,?,?,?,?,?,?,?)',
    [$batch, $m, $d['id'], json_encode($o), $out, json_encode($sum), $ok ? 'ok' : 'error', (int)((microtime(true) - $t) * 1000)]);
  return [(int)db()->lastInsertId(), $ok, $out, $sum];
}

// ---- Automation: eksplorasi -> bersih -> statistik -> split dalam satu klik / cron ----
function auto_run($d, $o = []) {
  $o = array_merge(cfg('auto'), $o); $b = bin2hex(random_bytes(6)); $steps = [];
  list($h, $rows) = split_table($d['raw_content'], (bool)$d['has_header']);
  $cols = implode(',', array_map(function ($i) use ($h) { return $h[$i]; }, pick_cols($h, $rows, '')));
  $r = run_module('explore', $d, ['head' => $o['head']], $b); $steps[] = array_merge(['explore'], $r);
  $work = $d;
  if ($cols !== '') {
    $r = run_module('clean', $d, ['impute' => $o['impute'], 'scale' => $o['scale'], 'outlier' => $o['outlier'], 'save' => 1], $b); $steps[] = array_merge(['clean'], $r);
    if ($r[1] && !empty($r[3]['derived_id'])) $work = get_dataset($r[3]['derived_id']);
    $r = run_module('stats', $work, ['kolom' => $cols], $b); $steps[] = array_merge(['stats'], $r);
  }
  $r = run_module('split', $work, ['test_size' => $o['test_size'], 'shuffle' => $o['shuffle'], 'seed' => $o['seed']], $b); $steps[] = array_merge(['split'], $r);
  q('UPDATE datasets SET processed_at=NOW() WHERE id=?', [$d['id']]);
  return ['batch' => $b, 'steps' => $steps];
}
