<?php
$CFG = require __DIR__ . '/../config.php';
date_default_timezone_set($CFG['timezone']);
if (PHP_SAPI === 'cli') { $_SESSION = []; }
elseif (session_status() === PHP_SESSION_NONE) { session_set_cookie_params(0, '/', '', false, true); session_start(); }

function cfg($k) { global $CFG; return $CFG[$k]; }
function db() {
  static $pdo = null;
  if (!$pdo) $pdo = new PDO('mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4', cfg('db_user'), cfg('db_pass'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
  return $pdo;
}
function q($sql, $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s; }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- keamanan dasar ----
function csrf() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_field() { return '<input type="hidden" name="_t" value="' . csrf() . '">'; }
function csrf_check() { if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_t'] ?? ''))) { http_response_code(419); exit('Token tidak valid, muat ulang halaman.'); } }
function flash($t, $m) { $_SESSION['flash'][] = [$t, $m]; }

// ---- CSV & input ----
function parse_csv($t) {
  $t = preg_replace('/^\xEF\xBB\xBF/', '', (string)$t);
  $first = (string)strtok($t, "\n"); $best = ','; $cnt = -1;
  foreach ([',', ';', "\t"] as $d) { $c = substr_count($first, $d); if ($c > $cnt) { $cnt = $c; $best = $d; } }
  $f = fopen('php://temp', 'r+'); fwrite($f, $t); rewind($f); $rows = [];
  while (($r = fgetcsv($f, 0, $best, '"', '\\')) !== false) {
    if (count($r) === 1 && trim((string)$r[0]) === '') continue;
    $rows[] = array_map(function ($x) { return trim((string)$x); }, $r);
  }
  fclose($f); return $rows;
}
function to_csv($h, $rows) {
  $f = fopen('php://temp', 'r+'); fputcsv($f, $h, ',', '"', '\\');
  foreach ($rows as $r) fputcsv($f, array_map(function ($x) { return is_float($x) ? round($x, 6) : $x; }, $r), ',', '"', '\\');
  rewind($f); $s = stream_get_contents($f); fclose($f); return $s;
}
// Mengubah "[80, null, 75]" atau "78 85 92" menjadi CSV satu kolom bernama "nilai"
function normalize_input($t) {
  $t = trim($t); if ($t === '') return '';
  if ($t[0] === '[') {
    $o = ['nilai'];
    foreach (preg_split('/\s*,\s*/', trim($t, "[] \n\r\t")) as $x) { $x = trim($x, " \"'"); $o[] = strtolower($x) === 'null' ? '' : $x; }
    return implode("\n", $o);
  }
  $tok = preg_split('/[\s,;]+/', $t, -1, PREG_SPLIT_NO_EMPTY);
  if (count($tok) > 1 && !preg_match('/[,;][^\n]*\n|\n[^\n]*[,;]/', $t) && !array_filter($tok, function ($x) { return !is_numeric($x); }))
    return "nilai\n" . implode("\n", $tok);
  return $t;
}
function split_table($text, $hasHeader) {
  $rows = parse_csv($text); if (!$rows) return [[], []];
  if ($hasHeader) $h = array_shift($rows);
  else { $h = []; for ($i = 1; $i <= count($rows[0]); $i++) $h[] = "c$i"; }
  return [$h, $rows];
}
function is_missing($x) { $s = strtolower(trim((string)$x)); return $s === '' || $s === 'null' || $s === 'na' || $s === 'nan'; }
function col_type($vals) {
  if (!$vals) return 'kosong';
  $int = true;
  foreach ($vals as $x) { if (!is_numeric($x)) return 'string'; if (!preg_match('/^-?\d+$/', $x)) $int = false; }
  return $int ? 'integer' : 'float';
}
function fnum($x, $d = 4) { return (is_int($x) || floor($x) == $x) ? (string)(int)$x : rtrim(rtrim(number_format($x, $d, '.', ''), '0'), '.'); }
function table_text($h, $rows, $w = 14) {
  $s = ''; foreach ($h as $x) $s .= str_pad(substr($x, 0, $w - 1), $w); $s .= "\n";
  foreach ($rows as $r) { foreach (array_keys($h) as $i) { $v = $r[$i] ?? ''; $s .= str_pad(substr(is_float($v) ? fnum($v) : (string)$v, 0, $w - 1), $w); } $s .= "\n"; }
  return $s;
}
function profile($h, $rows) {
  $cols = []; $cells = 0; $miss = 0;
  foreach ($h as $i => $name) {
    $vals = []; $m = 0;
    foreach ($rows as $r) { $x = $r[$i] ?? ''; if (is_missing($x)) $m++; else $vals[] = $x; }
    $t = col_type($vals);
    $c = ['name' => $name, 'type' => $t, 'missing' => $m, 'unique' => count(array_unique($vals))];
    if ($t === 'integer' || $t === 'float') { $n = array_map('floatval', $vals); $c['min'] = min($n); $c['max'] = max($n); $c['mean'] = array_sum($n) / count($n); }
    $cols[] = $c; $cells += count($rows); $miss += $m;
  }
  return ['cols' => $cols, 'missing_pct' => $cells ? round($miss / $cells * 100, 2) : 0];
}
function pick_cols($h, $rows, $spec) {
  $spec = trim($spec); $idx = [];
  if ($spec !== '') {
    $low = array_map('strtolower', $h);
    foreach (preg_split('/\s*,\s*/', $spec) as $n) { $i = array_search(strtolower($n), $low); if ($i !== false) $idx[] = $i; }
    return $idx;
  }
  foreach ($h as $i => $n) {
    $v = []; foreach ($rows as $r) { $x = $r[$i] ?? ''; if (!is_missing($x)) $v[] = $x; }
    $t = col_type($v);
    if ($t !== 'integer' && $t !== 'float') continue;
    if ($t === 'integer' && count(array_unique($v)) === count($rows) && preg_match('/^(id|no|nim|nip|kode|index)/i', $n)) continue; // kolom ID dilewati
    $idx[] = $i;
  }
  return $idx;
}

// ---- dataset ----
function save_dataset($name, $src, $text, $hh = 1, $parent = null, $desc = '') {
  $sum = hash('sha256', $text . '|' . $hh);
  $e = q('SELECT id FROM datasets WHERE checksum=?', [$sum])->fetch();
  if ($e) return (int)$e['id'];
  list($h, $rows) = split_table($text, (bool)$hh);
  if (!$rows) throw new RuntimeException('Data kosong atau format tidak terbaca.');
  $p = profile($h, $rows);
  q('INSERT INTO datasets(parent_id,name,source,description,raw_content,has_header,n_rows,n_cols,missing_pct,profile_json,checksum) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
    [$parent, $name !== '' ? substr($name, 0, 150) : 'Dataset ' . date('Y-m-d H:i'), $src, $desc, $text, $hh, count($rows), count($h), $p['missing_pct'], json_encode($p), $sum]);
  return (int)db()->lastInsertId();
}
function get_dataset($id) { $d = q('SELECT * FROM datasets WHERE id=?', [$id])->fetch(); if (!$d) throw new RuntimeException('Dataset tidak ditemukan.'); return $d; }
function gen_dataset($n, $name = '') {
  $l = ['id,fitur1,fitur2,label'];
  for ($i = 1; $i <= $n; $i++) $l[] = $i . ',' . mt_rand(50, 100) . ',' . mt_rand(50, 100) . ',' . mt_rand(0, 1);
  return save_dataset($name ?: "Data acak $n baris " . date('d/m H:i'), 'generated', implode("\n", $l), 1);
}
function read_input() {
  if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    if ($_FILES['file']['size'] > cfg('max_upload_mb') * 1048576) throw new RuntimeException('Berkas melebihi batas ukuran.');
    $t = (string)file_get_contents($_FILES['file']['tmp_name']); $n = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME); $s = 'upload';
  } else {
    $t = trim((string)($_POST['text'] ?? '')); if ($t === '') return null;
    $n = trim((string)($_POST['name'] ?? '')); $s = 'paste';
  }
  if (function_exists('mb_check_encoding') && !mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
  return [$n, $s, $t];
}
function resolve_source($m) {
  $in = read_input();
  if ($in) {
    $pipe = $m === 'pipeline';
    return get_dataset(save_dataset($in[0], $in[1], $pipe ? $in[2] : normalize_input($in[2]), $pipe ? (empty($_POST['has_header']) ? 0 : 1) : 1));
  }
  $id = (int)($_POST['dataset_id'] ?? 0);
  if ($id) return get_dataset($id);
  throw new RuntimeException('Pilih dataset tersimpan, unggah berkas, atau tempel data.');
}
function seed_samples() {
  save_dataset('Transaksi penjualan (contoh)', 'sample', "1,Buku,3,25000\n2,Pulpen,10,3500\n3,,2,10000\n4,Tas,-1,150000\n5,Mouse,80000\n6,Laptop,1,7500000\n7,Map,abc,5000", 0);
  save_dataset('Mahasiswa (contoh)', 'sample', "nim,nama,nilai_tugas,nilai_ujian\n2301001,Andi Pratama,85,78\n2301002,Budi Santoso,90,82\n2301003,Citra Dewi,78,88\n2301004,Dina Lestari,92,95\n2301005,Eko Wijaya,70,65\n2301006,Fitri Handayani,88,79\n2301007,Gilang Ramadhan,75,72");
  save_dataset('Nilai ujian dengan missing (contoh)', 'sample', normalize_input('[80, null, 75, "", 90, null, 65, 85, 100]'));
  save_dataset('Nilai 20 siswa (contoh)', 'sample', normalize_input('78 85 92 67 74 88 95 81 73 85 69 90 77 85 100 62 79 84 91 73'));
  mt_srand(1); $id = gen_dataset(50, 'Data acak 50 baris (contoh)'); q("UPDATE datasets SET source='sample' WHERE id=?", [$id]);
}

require __DIR__ . '/modules.php';
