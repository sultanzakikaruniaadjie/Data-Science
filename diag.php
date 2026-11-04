<?php
// Diagnosa 500. Buka di browser, lalu HAPUS file ini.
ini_set('display_errors', '1'); error_reporting(E_ALL);
echo "<pre>PHP " . PHP_VERSION . "\n";
foreach (['pdo_mysql', 'mbstring'] as $e) echo "ekstensi $e: " . (extension_loaded($e) ? 'ada' : 'TIDAK ADA') . "\n";
foreach (['config.php', 'index.php', 'install.php', 'schema.sql', 'lib/core.php', 'lib/modules.php', 'assets/style.css'] as $f) echo "$f: " . (is_file(__DIR__ . '/' . $f) ? 'ada' : 'TIDAK ADA') . "\n";
try {
  require __DIR__ . '/lib/core.php'; db(); echo "Koneksi DB: OK\nTabel: ";
  $t = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM); echo $t ? implode(', ', array_column($t, 0)) : '(belum ada, jalankan install.php)';
} catch (Throwable $e) { echo 'ERROR: ' . $e->getMessage(); }
