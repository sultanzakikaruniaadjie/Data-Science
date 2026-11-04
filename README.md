# Simulasi Praktikum Sains Data (PHP Native + MySQL)

Kebutuhan: PHP 7.4+ (ekstensi pdo_mysql), MySQL/MariaDB.

## Pasang di hosting
1. Buat database + user di cPanel (MySQL Databases), catat nama/user/password.
2. Edit `config.php` dengan data tersebut.
3. Upload semua isi folder ini ke hosting (mis. public_html/praktikum).
4. Buka `https://domain-anda/praktikum/install.php`, klik Pasang. Tabel dibuat otomatis (atau impor `schema.sql` lewat phpMyAdmin).
5. HAPUS `install.php` dari hosting.

## Cron (otomatis)
Dataset bertanda "auto" diproses cron.php: `php /home/USER/public_html/praktikum/cron.php`
Atau lewat URL: isi `cron_token` di config.php, panggil `cron.php?token=...`.
