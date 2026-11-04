<?php
// Jalankan sekali di browser, lalu HAPUS file ini dari hosting.
if (is_file(__DIR__ . '/install.lock')) exit('Sudah terpasang. Hapus install.php dari server.');
require __DIR__ . '/lib/core.php';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $s) db()->exec($s);
    if (!empty($_POST['seed'])) seed_samples();
    file_put_contents(__DIR__ . '/install.lock', date('c'));
    $msg = '<b>Selesai.</b> Hapus <code>install.php</code> dari hosting, lalu <a href="index.php">masuk</a>.';
  } catch (Throwable $e) { $msg = 'Gagal: ' . h($e->getMessage()); }
}
?><!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Install</title>
<link rel="stylesheet" href="assets/style.css"></head><body><main>
<h1>install</h1><p class="sub">Membuat tabel MySQL</p>
<div class="p on"><?php if ($msg) echo '<p class="flash">' . $msg . '</p>'; ?>
<form method="post"><?= csrf_field() ?>
<label><input type="checkbox" name="seed" value="1" checked style="width:auto"> Isi dataset contoh (5 dataset dari soal praktikum)</label>
<button class="b">Pasang</button></form></div></main></body></html>
