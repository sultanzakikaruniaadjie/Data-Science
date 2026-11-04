<?php
require __DIR__ . '/lib/core.php';
if (!is_file(__DIR__ . '/install.lock') && is_file(__DIR__ . '/install.php')) { header('Location: install.php'); exit; }

$MODS = [
  'pipeline' => ['1. Pipeline penjualan', 'Validasi baris dengan aturan kolom yang bisa diubah, hitung kolom turunan, tampilkan tahap pipeline.'],
  'explore'  => ['2. Eksplorasi dataset', 'Jumlah rekaman, kolom, head, tipe data, data hilang, dan duplikat.'],
  'clean'    => ['3. Pembersihan', 'Imputasi nilai hilang, deteksi outlier (IQR), normalisasi Min-Max atau Z-score.'],
  'stats'    => ['4. Statistik', 'Mean, median, modus, std deviasi, kuartil, histogram ASCII per kolom numerik.'],
  'split'    => ['5. Train-test split', 'Pembagian data latih/uji dengan seed (hasil bisa diulang) dan opsi stratifikasi.'],
];
$p = $_GET['p'] ?? 'home';

function layout($title, $body, $nav = true) {
  global $MODS; $cur = $_GET['p'] ?? 'home'; $m = $_GET['m'] ?? '';
  echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . ' | Praktikum Sains Data</title><link rel="stylesheet" href="assets/style.css"></head><body><main>';
  echo '<h1>simulasi_praktikum</h1><p class="sub">Sains Data / PHP Native + MySQL</p>';
  if ($nav) {
    $items = ['home' => 'Dashboard', 'datasets' => 'Dataset'];
    echo '<nav>'; foreach ($items as $k => $v) echo '<a href="?p=' . $k . '"' . ($cur === $k || ($k === 'datasets' && $cur === 'dataset') ? ' class="on"' : '') . '>' . $v . '</a>';
    foreach ($MODS as $k => $v) echo '<a href="?p=mod&m=' . $k . '"' . ($cur === 'mod' && $m === $k ? ' class="on"' : '') . '>' . h($v[0]) . '</a>';
    foreach (['auto' => 'Auto', 'runs' => 'Riwayat'] as $k => $v) echo '<a href="?p=' . $k . '"' . ($cur === $k || ($k === 'runs' && $cur === 'run') ? ' class="on"' : '') . '>' . $v . '</a>';
    echo '</nav>';
  }
  echo '<div class="p">';
  foreach ($_SESSION['flash'] ?? [] as $f) echo '<p class="flash ' . ($f[0] === 'err' ? 'err' : '') . '">' . h($f[1]) . '</p>';
  unset($_SESSION['flash']);
  echo $body . '</div></main></body></html>';
}
function html_table($h, $rows) {
  $s = '<div class="tw"><table><tr>'; foreach ($h as $x) $s .= '<th>' . h($x) . '</th>'; $s .= '</tr>';
  foreach ($rows as $r) { $s .= '<tr>'; foreach (array_keys($h) as $i) $s .= '<td>' . h($r[$i] ?? '') . '</td>'; $s .= '</tr>'; }
  return $s . '</table></div>';
}
function form_post($action, $fields, $label, $cls = 'b s') {
  $s = '<form class="in" method="post" action="' . $action . '">' . csrf_field();
  foreach ($fields as $k => $v) $s .= '<input type="hidden" name="' . $k . '" value="' . h($v) . '">';
  return $s . '<button class="' . $cls . '">' . $label . '</button></form> ';
}
function send_csv($text, $name) {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '_', $name) . '.csv"'); echo "\xEF\xBB\xBF" . $text; exit;
}
function v($k, $def = '') { return h($_POST[$k] ?? $def); }
function sel($k, $opts, $def) {
  $s = '<select name="' . $k . '">'; $cur = $_POST[$k] ?? $def;
  foreach ($opts as $val => $lb) $s .= '<option value="' . h($val) . '"' . ((string)$cur === (string)$val ? ' selected' : '') . '>' . h($lb) . '</option>';
  return $s . '</select>';
}
function ds_options() {
  $o = '<option value="">- pilih dataset tersimpan -</option>';
  foreach (q('SELECT id,name,n_rows,n_cols FROM datasets ORDER BY id DESC')->fetchAll() as $r)
    $o .= '<option value="' . $r['id'] . '"' . ((string)($_REQUEST['dataset_id'] ?? '') === (string)$r['id'] ? ' selected' : '') . '>#' . $r['id'] . ' ' . h($r['name']) . ' (' . $r['n_rows'] . 'x' . $r['n_cols'] . ')</option>';
  return $o;
}
function source_widget($m) {
  $s = '<label>Sumber data</label><select name="dataset_id">' . ds_options() . '</select>' .
    '<label>Atau unggah CSV</label><input type="file" name="file" accept=".csv,.txt,.tsv">' .
    '<label>Atau tempel data</label><textarea name="text" placeholder="' . ($m === 'pipeline' ? 'id,produk,jumlah,harga' : 'CSV dengan header, daftar angka, atau [80, null, 75]') . '">' . v('text') . '</textarea>' .
    '<div class="r"><div><label>Nama dataset (opsional)</label><input name="name" value="' . v('name') . '"></div>';
  if ($m === 'pipeline') $s .= '<div><label>Header</label>' . sel('has_header', ['' => 'Tanpa header', '1' => 'Baris 1 = header'], '') . '</div>';
  return $s . '</div><div class="h">Prioritas: berkas &gt; teks tempel &gt; dataset tersimpan. Data baru otomatis tersimpan ke database.</div>';
}
function mod_fields($m) {
  switch ($m) {
    case 'pipeline': return '<label>Aturan validasi (satu kolom per baris, urut sesuai kolom data)</label><textarea name="rules" style="min-height:100px">' . v('rules', "id:int\nproduk:str, required\njumlah:int, gt=0\nharga:num, gt=0") . '</textarea>' .
      '<div class="h">Tipe: int, num, str. Opsi: required, gt=, min=, max=</div><div class="r"><div><label>Kolom turunan (opsional)</label><input name="formula" value="' . v('formula', 'total=jumlah*harga') . '"></div>' .
      '<div><label>Simpan baris valid</label>' . sel('save', ['' => 'Tidak', '1' => 'Ya, jadi dataset baru'], '') . '</div></div>';
    case 'explore': return '<div class="r"><div><label>Jumlah baris head</label><input type="number" name="head" min="1" max="100" value="' . v('head', 5) . '"></div></div>';
    case 'clean': return '<label>Kolom (pisah koma, kosong = semua numerik, kolom ID dilewati)</label><input name="kolom" value="' . v('kolom') . '"><div class="r">' .
      '<div><label>Imputasi</label>' . sel('impute', ['mean' => 'Mean', 'median' => 'Median', 'mode' => 'Modus', 'zero' => 'Nol', 'drop' => 'Buang baris'], 'mean') . '</div>' .
      '<div><label>Skala</label>' . sel('scale', ['minmax' => 'Min-Max (0-1)', 'zscore' => 'Z-score', 'none' => 'Tanpa'], 'minmax') . '</div>' .
      '<div><label>Outlier (IQR)</label>' . sel('outlier', ['none' => 'Hanya hitung', 'clip' => 'Potong ke batas'], 'none') . '</div>' .
      '<div><label>Simpan hasil</label>' . sel('save', ['' => 'Tidak', '1' => 'Ya, jadi dataset baru'], '1') . '</div></div>';
    case 'stats': return '<label>Kolom (pisah koma, kosong = semua numerik)</label><input name="kolom" value="' . v('kolom') . '"><div class="r">' .
      '<div><label>Rentang (kosong = otomatis)</label><input name="bins" value="' . v('bins') . '" placeholder="60-69, 70-79, 80-89, 90-100"></div>' .
      '<div><label>Std deviasi</label>' . sel('sample', ['' => 'Populasi (/N)', '1' => 'Sampel (/N-1)'], '') . '</div>' .
      '<div><label>Karakter bar</label><input name="char" maxlength="1" value="' . v('char', '#') . '"></div></div>';
    case 'split': return '<div class="r"><div><label>test_size</label><input type="number" name="test_size" step="0.05" min="0.01" max="0.99" value="' . v('test_size', 0.2) . '"></div>' .
      '<div><label>shuffle</label>' . sel('shuffle', ['1' => 'true', '' => 'false'], '1') . '</div>' .
      '<div><label>seed (kosong = acak)</label><input type="number" name="seed" value="' . v('seed', 42) . '"></div>' .
      '<div><label>stratify kolom (opsional)</label><input name="stratify" value="' . v('stratify') . '" placeholder="label"></div></div>';
  }
  return '';
}
function result_box($id, $ok, $out, $sum, $m) {
  $s = '<pre class="' . ($ok ? '' : 'er') . '">' . h($out) . '</pre><p class="h">Tercatat di <a href="?p=run&id=' . $id . '">riwayat #' . $id . '</a>';
  if (!empty($sum['derived_id'])) $s .= ' | <a href="?p=dataset&id=' . $sum['derived_id'] . '">dataset hasil #' . $sum['derived_id'] . '</a> | <a href="?p=dl&ds=' . $sum['derived_id'] . '">unduh CSV</a>';
  if ($m === 'split' && $ok) $s .= ' | <a href="?p=dl&run=' . $id . '&part=train">unduh train.csv</a> | <a href="?p=dl&run=' . $id . '&part=test">unduh test.csv</a>';
  return $s . '</p>';
}

try {
  $post = $_SERVER['REQUEST_METHOD'] === 'POST'; if ($post) csrf_check();

  // ---------- dashboard ----------
  if ($p === 'home') {
    $c = q('SELECT (SELECT COUNT(*) FROM datasets) d,(SELECT COUNT(*) FROM runs) r,(SELECT COUNT(*) FROM runs WHERE status=\'error\') e,(SELECT COALESCE(AVG(missing_pct),0) FROM datasets) m')->fetch();
    $b = '<h2>Dashboard</h2><p class="t">Ringkasan dataset dan semua proses yang pernah dijalankan.</p><div class="cards">' .
      '<div class="card"><b>' . $c['d'] . '</b><span>dataset tersimpan</span></div><div class="card"><b>' . $c['r'] . '</b><span>proses dijalankan</span></div>' .
      '<div class="card"><b>' . $c['e'] . '</b><span>proses gagal</span></div><div class="card"><b>' . number_format($c['m'], 1) . '%</b><span>rata-rata data hilang</span></div></div>';
    $rr = q('SELECT r.id,r.module,r.status,r.duration_ms,r.created_at,d.name FROM runs r LEFT JOIN datasets d ON d.id=r.dataset_id ORDER BY r.id DESC LIMIT 8')->fetchAll();
    $b .= '<h3>Proses terakhir</h3>' . html_table(['#', 'modul', 'dataset', 'status', 'ms', 'waktu'], array_map(function ($r) { return ['#' . $r['id'], $r['module'], $r['name'], $r['status'], $r['duration_ms'], $r['created_at']]; }, $rr));
    $b .= '<h3>Dataset dengan data hilang terbanyak</h3>' . html_table(['#', 'nama', 'baris', 'missing %'], array_map(function ($r) { return [$r['id'], $r['name'], $r['n_rows'], $r['missing_pct']]; }, q('SELECT id,name,n_rows,missing_pct FROM datasets WHERE missing_pct>0 ORDER BY missing_pct DESC LIMIT 5')->fetchAll()));
    layout('Dashboard', $b); exit;
  }

  // ---------- dataset ----------
  if ($p === 'datasets') {
    if ($post) {
      $a = $_POST['a'] ?? '';
      if ($a === 'add') {
        $in = read_input(); if (!$in) throw new RuntimeException('Unggah berkas atau tempel data terlebih dulu.');
        $hh = empty($_POST['has_header']) ? 0 : 1; $id = save_dataset($in[0], $in[1], $hh ? normalize_input($in[2]) : $in[2], $hh, null, (string)($_POST['desc'] ?? ''));
        if (!empty($_POST['auto_run'])) q('UPDATE datasets SET auto_run=1 WHERE id=?', [$id]);
        flash('ok', "Dataset #$id tersimpan."); header('Location: ?p=dataset&id=' . $id); exit;
      }
      if ($a === 'gen') { $id = gen_dataset(max(2, min(1000, (int)($_POST['n'] ?? 50)))); flash('ok', "Dataset acak #$id dibuat."); header('Location: ?p=dataset&id=' . $id); exit; }
      if ($a === 'del') { q('DELETE FROM datasets WHERE id=?', [(int)$_POST['id']]); flash('ok', 'Dataset dihapus beserta riwayatnya.'); header('Location: ?p=datasets'); exit; }
      if ($a === 'auto') { q('UPDATE datasets SET auto_run=1-auto_run, processed_at=NULL WHERE id=?', [(int)$_POST['id']]); header('Location: ?p=datasets'); exit; }
    }
    $rows = q('SELECT * FROM datasets ORDER BY id DESC')->fetchAll(); $t = '<div class="tw"><table><tr><th>#</th><th>nama</th><th>sumber</th><th>baris x kolom</th><th>missing</th><th>auto</th><th>dibuat</th><th></th></tr>';
    foreach ($rows as $r) $t .= '<tr><td>' . $r['id'] . '</td><td><a href="?p=dataset&id=' . $r['id'] . '">' . h($r['name']) . '</a>' . ($r['parent_id'] ? ' <span class="h">(dari #' . $r['parent_id'] . ')</span>' : '') . '</td><td>' . $r['source'] . '</td><td>' . $r['n_rows'] . ' x ' . $r['n_cols'] . '</td><td>' . $r['missing_pct'] . '%</td><td>' .
      form_post('?p=datasets', ['a' => 'auto', 'id' => $r['id']], $r['auto_run'] ? ($r['processed_at'] ? 'selesai' : 'antre') : 'off') . '</td><td>' . $r['created_at'] . '</td><td>' .
      form_post('?p=datasets', ['a' => 'del', 'id' => $r['id']], 'hapus') . '</td></tr>';
    $t .= '</table></div>';
    $b = '<h2>Dataset</h2><p class="t">Semua data yang pernah dimasukkan atau dihasilkan tersimpan di sini dan bisa dipakai ulang di setiap modul.</p>' . $t .
      '<h3>Tambah dataset</h3><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="a" value="add"><label>Unggah CSV</label><input type="file" name="file" accept=".csv,.txt,.tsv"><label>Atau tempel data</label><textarea name="text"></textarea>' .
      '<div class="r"><div><label>Nama</label><input name="name"></div><div><label>Header</label>' . sel('has_header', ['1' => 'Baris 1 = header', '' => 'Tanpa header'], '1') . '</div><div><label>Cron otomatis</label>' . sel('auto_run', ['' => 'Tidak', '1' => 'Ya'], '') . '</div></div><label>Keterangan</label><input name="desc"><button class="b">Simpan</button></form>' .
      '<h3>Generator data acak</h3><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="gen"><div class="r"><div><label>Jumlah baris</label><input type="number" name="n" value="50" min="2" max="1000"></div></div><button class="b o">Buat data acak</button></form>';
    layout('Dataset', $b); exit;
  }
  if ($p === 'dataset') {
    $d = get_dataset((int)($_GET['id'] ?? 0)); list($h, $rows) = split_table($d['raw_content'], (bool)$d['has_header']); $pr = json_decode($d['profile_json'], true);
    $b = '<h2>' . h($d['name']) . '</h2><p class="t">' . $d['n_rows'] . ' baris, ' . $d['n_cols'] . ' kolom, data hilang ' . $d['missing_pct'] . '%' . ($d['parent_id'] ? ' | turunan dari <a href="?p=dataset&id=' . $d['parent_id'] . '">#' . $d['parent_id'] . '</a>' : '') . '</p>';
    $b .= '<a class="b o" href="?p=dl&ds=' . $d['id'] . '">Unduh CSV</a>';
    foreach ($MODS as $k => $mv) $b .= '<a class="b o" href="?p=mod&m=' . $k . '&dataset_id=' . $d['id'] . '">' . h($mv[0]) . '</a>';
    $b .= '<a class="b" href="?p=auto&dataset_id=' . $d['id'] . '">Auto pipeline</a>';
    $b .= '<h3>Profil kolom</h3>' . html_table(['kolom', 'tipe', 'kosong', 'unik', 'min', 'mean', 'max'], array_map(function ($c) { return [$c['name'], $c['type'], $c['missing'], $c['unique'], isset($c['min']) ? fnum($c['min']) : '-', isset($c['mean']) ? fnum($c['mean'], 2) : '-', isset($c['max']) ? fnum($c['max']) : '-']; }, $pr['cols']));
    $b .= '<h3>Pratinjau</h3>' . html_table($h, array_slice($rows, 0, 10));
    $rr = q('SELECT id,module,status,created_at FROM runs WHERE dataset_id=? ORDER BY id DESC LIMIT 10', [$d['id']])->fetchAll();
    $b .= '<h3>Riwayat dataset ini</h3>' . ($rr ? html_table(['#', 'modul', 'status', 'waktu'], array_map(function ($r) { return ['#' . $r['id'], $r['module'], $r['status'], $r['created_at']]; }, $rr)) : '<p class="h">Belum pernah diproses.</p>');
    layout($d['name'], $b); exit;
  }

  // ---------- modul ----------
  if ($p === 'mod') {
    $m = $_GET['m'] ?? ''; if (!isset($MODS[$m])) throw new RuntimeException('Modul tidak ada.');
    $res = '';
    if ($post) {
      try { $d = resolve_source($m); $opts = array_diff_key($_POST, array_flip(['text', '_t', 'name', 'dataset_id', 'has_header']));
        list($id, $ok, $out, $sum) = run_module($m, $d, $opts); $res = '<p class="h">Dataset: #' . $d['id'] . ' ' . h($d['name']) . '</p>' . result_box($id, $ok, $out, $sum, $m);
      } catch (Throwable $e) { $res = '<pre class="er">' . h($e->getMessage()) . '</pre>'; }
    }
    layout($MODS[$m][0], '<h2>' . h($MODS[$m][0]) . '</h2><p class="t">' . h($MODS[$m][1]) . '</p><form method="post" enctype="multipart/form-data" action="?p=mod&m=' . $m . '">' . csrf_field() . source_widget($m) . mod_fields($m) . '<button class="b">Jalankan</button></form>' . $res); exit;
  }

  // ---------- auto ----------
  if ($p === 'auto') {
    $res = '';
    if ($post) {
      try { $d = resolve_source('auto'); $A = auto_run($d, ['impute' => $_POST['impute'] ?? 'median', 'scale' => $_POST['scale'] ?? 'minmax', 'test_size' => (float)($_POST['test_size'] ?? 0.2), 'seed' => $_POST['seed'] !== '' ? (int)$_POST['seed'] : random_int(1, 999999)]);
        $res = '<h3>Batch ' . $A['batch'] . ' - ' . h($d['name']) . '</h3>';
        foreach ($A['steps'] as $s) $res .= '<p class="h">Langkah: ' . $s[0] . '</p>' . result_box($s[1], $s[2], $s[3], $s[4], $s[0]);
      } catch (Throwable $e) { $res = '<pre class="er">' . h($e->getMessage()) . '</pre>'; }
    }
    layout('Auto', '<h2>Auto pipeline</h2><p class="t">Satu klik: eksplorasi, pembersihan (hasil disimpan sebagai dataset baru), statistik, lalu train-test split. Dataset bertanda "auto" juga diproses cron.php.</p><form method="post" enctype="multipart/form-data">' . csrf_field() . source_widget('auto') .
      '<div class="r"><div><label>Imputasi</label>' . sel('impute', ['median' => 'Median', 'mean' => 'Mean', 'mode' => 'Modus', 'zero' => 'Nol', 'drop' => 'Buang baris'], 'median') . '</div><div><label>Skala</label>' . sel('scale', ['minmax' => 'Min-Max', 'zscore' => 'Z-score', 'none' => 'Tanpa'], 'minmax') . '</div>' .
      '<div><label>test_size</label><input type="number" name="test_size" step="0.05" min="0.01" max="0.99" value="' . v('test_size', 0.2) . '"></div><div><label>seed</label><input type="number" name="seed" value="' . v('seed', 42) . '"></div></div><button class="b">Jalankan semua</button></form>' . $res); exit;
  }

  // ---------- riwayat ----------
  if ($p === 'runs') {
    $f = $_GET['m'] ?? ''; $rr = q('SELECT r.*,d.name dname FROM runs r LEFT JOIN datasets d ON d.id=r.dataset_id WHERE (?=\'\' OR r.module=?) ORDER BY r.id DESC LIMIT 100', [$f, $f])->fetchAll();
    $b = '<h2>Riwayat proses</h2><p class="t">Filter: <a href="?p=runs">semua</a>'; foreach ($MODS as $k => $mv) $b .= ' | <a href="?p=runs&m=' . $k . '">' . $k . '</a>'; $b .= '</p><div class="tw"><table><tr><th>#</th><th>modul</th><th>dataset</th><th>batch</th><th>status</th><th>ms</th><th>waktu</th></tr>';
    foreach ($rr as $r) $b .= '<tr><td><a href="?p=run&id=' . $r['id'] . '">#' . $r['id'] . '</a></td><td>' . $r['module'] . '</td><td>' . h($r['dname']) . '</td><td>' . h($r['batch_id']) . '</td><td class="' . ($r['status'] === 'ok' ? 'ok' : 'bad') . '">' . $r['status'] . '</td><td>' . $r['duration_ms'] . '</td><td>' . $r['created_at'] . '</td></tr>';
    layout('Riwayat', $b . '</table></div>'); exit;
  }
  if ($p === 'run') {
    $r = q('SELECT r.*,d.name dname FROM runs r LEFT JOIN datasets d ON d.id=r.dataset_id WHERE r.id=?', [(int)($_GET['id'] ?? 0)])->fetch(); if (!$r) throw new RuntimeException('Riwayat tidak ditemukan.');
    $sum = json_decode($r['summary_json'], true) ?: [];
    $b = '<h2>Proses #' . $r['id'] . ' - ' . $r['module'] . '</h2><p class="t">Dataset <a href="?p=dataset&id=' . $r['dataset_id'] . '">' . h($r['dname']) . '</a> | ' . $r['created_at'] . ' | ' . $r['duration_ms'] . ' ms' . ($r['batch_id'] ? ' | batch ' . h($r['batch_id']) : '') . '</p><div class="h">Parameter: ' . h($r['params_json']) . '</div>' . result_box($r['id'], $r['status'] === 'ok', $r['output'], $sum, $r['module']);
    if ($r['batch_id']) { $sib = q('SELECT id,module FROM runs WHERE batch_id=? ORDER BY id', [$r['batch_id']])->fetchAll(); $b .= '<p class="h">Satu batch: '; foreach ($sib as $s) $b .= '<a href="?p=run&id=' . $s['id'] . '">' . $s['module'] . '</a> '; $b .= '</p>'; }
    layout('Proses #' . $r['id'], $b); exit;
  }

  // ---------- unduh ----------
  if ($p === 'dl') {
    if (isset($_GET['run'])) {
      $r = q('SELECT * FROM runs WHERE id=?', [(int)$_GET['run']])->fetch(); if (!$r) throw new RuntimeException('Run tidak ditemukan.');
      $d = get_dataset((int)$r['dataset_id']); $s = json_decode($r['summary_json'], true); $part = ($_GET['part'] ?? '') === 'test' ? 'test' : 'train';
      if (empty($s[$part])) throw new RuntimeException('Run ini bukan hasil split.');
      list($h, $rows) = split_table($d['raw_content'], (bool)$d['has_header']);
      send_csv(to_csv($h, array_map(function ($i) use ($rows) { return $rows[$i]; }, $s[$part])), $part . '_run' . $r['id']);
    }
    $d = get_dataset((int)($_GET['ds'] ?? 0)); send_csv($d['raw_content'], $d['name']);
  }
  throw new RuntimeException('Halaman tidak ditemukan.');
} catch (Throwable $e) {
  flash('err', $e->getMessage()); layout('Error', '<h2>Terjadi kesalahan</h2><p class="t"><a href="?">Kembali ke dashboard</a></p>');
}
