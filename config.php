<?php
// Isi sesuai data MySQL di cPanel / phpMyAdmin hosting Anda.
return [
  'db_host' => 'localhost',
  'db_name' => 'NAMA_DATABASE',
  'db_user' => 'USER_DATABASE',
  'db_pass' => 'PASSWORD_DATABASE',
  'timezone' => 'Asia/Jakarta',
  'max_upload_mb' => 5,
  // Token untuk cron via URL (kosong = hanya boleh lewat CLI). Contoh: 'rahasia123'
  'cron_token' => '',
  // Parameter default untuk Auto Pipeline & cron (bebas diubah)
  'auto' => ['impute' => 'median', 'scale' => 'minmax', 'outlier' => 'none',
             'test_size' => 0.2, 'seed' => 42, 'shuffle' => 1, 'head' => 5],
];
