# Deploy ke IDwebhost (shared hosting / cPanel)

Hosting hanya menyajikan API (baca saja). Scrape tetap dijalankan di PC lokal, lalu datanya dikirim ke MySQL hosting.

## 0. Syarat

- PHP 8.3 tersedia di hosting (cPanel: **Select PHP Version** atau **MultiPHP Manager**).
- Ekstensi PHP aktif: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `json`.
- Disarankan punya subdomain, misalnya `api.domainkamu.com`.

## 1. Siapkan database di cPanel

1. **MySQL Databases**: buat database dan user, lalu hubungkan user ke database dengan **All Privileges**.
2. Catat nama database, user, password. Di cPanel nama biasanya berawalan akun (`akun_wuwa`).

## 2. Siapkan file aplikasi (di PC)

```bash
cd wuwa-api
composer install --no-dev --optimize-autoloader
php artisan key:generate --show      # salin hasilnya untuk APP_KEY
```

Zip folder `wuwa-api` **tanpa**: `.env`, `.git`, `node_modules`, `storage/logs/*`. Folder `vendor` ikut (karena shared hosting sering tanpa Composer).

Setelah zip dibuat, kembalikan dev dependency di PC bila perlu: `composer install`.

## 3. Upload dan atur document root

1. Di cPanel **File Manager**, upload zip ke `/home/akun/wuwa-api` (di luar `public_html`) lalu extract.
2. **Domains / Subdomains**: buat `api.domainkamu.com` dengan **Document Root** `/home/akun/wuwa-api/public`.
3. Jika document root tidak bisa diubah, upload isi `public/` ke folder subdomain, lalu ubah dua path di `index.php` agar menunjuk ke `../wuwa-api/vendor/autoload.php` dan `../wuwa-api/bootstrap/app.php`.

## 4. Buat `.env` di server

Buat file `/home/akun/wuwa-api/.env`:

```env
APP_NAME=WuwaApi
APP_ENV=production
APP_KEY=base64:ISI_DARI_KEY_GENERATE
APP_DEBUG=false
APP_URL=https://api.domainkamu.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=akun_wuwa
DB_USERNAME=akun_user
DB_PASSWORD=PASSWORD_DB

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=daily
```

`SESSION_DRIVER`, `CACHE_STORE`, dan `QUEUE_CONNECTION` memakai `file`/`sync` supaya tidak butuh tabel tambahan. Jangan pernah upload `.env` lokal.

## 5. Izin folder

Set `775` untuk `storage/` dan `bootstrap/cache/` (File Manager, klik kanan, Change Permissions, centang recursive).

## 6. Import data

Di PC, buat dump tabel `wiki_entries` (sudah termasuk `DROP TABLE` dan `CREATE TABLE`, jadi `migrate` tidak diperlukan):

```bash
mysqldump -uroot --single-transaction wuwa-api wiki_entries | gzip -9 > wiki_entries.sql.gz
```

Di cPanel buka **phpMyAdmin**, pilih database, tab **Import**, pilih `wiki_entries.sql.gz`, lalu **Go**. Ukuran sekitar 17 MB terkompres. Jika ditolak karena batas upload, minta IDwebhost menaikkan `upload_max_filesize`, atau import lewat **Terminal/SSH**:

```bash
gunzip -c wiki_entries.sql.gz | mysql -u akun_user -p akun_wuwa
```

## 7. Tes

```bash
curl https://api.domainkamu.com/api/resonators
curl https://api.domainkamu.com/api/resonators/Jinhsi
```

Jika muncul error 500, cek `storage/logs/` dan pastikan `APP_KEY` terisi serta izin folder benar. Sementara debugging boleh set `APP_DEBUG=true`, lalu kembalikan ke `false`.

Jika memakai SSH, opsional untuk percepatan:

```bash
php artisan config:cache && php artisan route:cache
```

## 8. Update data berikutnya

Di PC:

```bash
php scraper/wuwa-scraps.php 0 6 --update
php artisan wuwa:import --prune
mysqldump -uroot --single-transaction wuwa-api wiki_entries | gzip -9 > wiki_entries.sql.gz
```

Lalu import `wiki_entries.sql.gz` lagi lewat phpMyAdmin. Dump menimpa tabel lama, jadi hasilnya sama persis dengan data lokal. Jika `config:cache` pernah dijalankan, jalankan `php artisan config:clear` setelah mengubah `.env`.

## Catatan keamanan

- API ini tanpa autentikasi dan hanya membaca data, jadi aman dipublikasikan. Pertimbangkan rate limit atau Cloudflare jika trafik tinggi.
- `.env` harus berada di luar folder yang bisa diakses web (document root ke `public/` sudah memastikan hal ini).
