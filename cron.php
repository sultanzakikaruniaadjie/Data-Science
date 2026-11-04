<?php
// cPanel > Cron Jobs, contoh: php /home/USER/public_html/praktikum/cron.php
// Memproses otomatis semua dataset bertanda "auto" yang belum diproses.
require __DIR__ . '/lib/core.php';
if (PHP_SAPI !== 'cli' && (cfg('cron_token') === '' || !hash_equals(cfg('cron_token'), (string)($_GET['token'] ?? '')))) { http_response_code(403); exit('Forbidden'); }
$n = 0;
foreach (q('SELECT * FROM datasets WHERE auto_run=1 AND processed_at IS NULL')->fetchAll() as $d) { auto_run($d); $n++; }
echo "Selesai: $n dataset diproses\n";
