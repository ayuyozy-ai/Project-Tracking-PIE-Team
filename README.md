# Project Tracker PHP 8 + MySQL

Versi PHP 8 baru yang menggantikan backend Node.js lama dengan database MySQL.

## Struktur utama

- `config.php` – konfigurasi aplikasi dan database
- `db.php` – koneksi PDO dan helper utilitas
- `api.php` – endpoint REST API
- `index.php` – menyajikan kembali antarmuka aplikasi asli dari `public/index.html`
- `schema.sql` – skema MySQL

## Persiapan database

1. Buat database MySQL.
2. Jalankan SQL dari `schema.sql`.
3. Sesuaikan kredensial database di `config.php` jika diperlukan.

Contoh:

```sql
CREATE DATABASE project_tracker;
USE project_tracker;
SOURCE schema.sql;
```

Atau import file `schema.sql` dari phpMyAdmin / MySQL CLI.

## Menjalankan aplikasi

Jalankan Apache dan MySQL dari XAMPP Control Panel. Database `project_tracker` harus sudah dibuat dan `schema.sql` telah diimpor.

Jalankan `start.bat` untuk membuka XAMPP Control Panel dan melihat alamat aplikasi.

Akses dari server:

- `http://localhost/ProjectTracker/`

Akses LAN memakai IPv4 PC server, misalnya `http://10.10.162.47/ProjectTracker/`, jika routing antar-subnet dan firewall TCP port 80 mengizinkan.

## Endpoint utama

- `GET /api/health`
- `GET /api/public`
- `POST /api/setup`
- `POST /api/login`
- `GET /api/me`
- `GET /api/projects`
- `POST /api/projects`
- `PUT /api/projects/{id}`
- `DELETE /api/projects/{id}`
- `GET /api/users`
- `POST /api/users`

## Catatan

Versi ini menjalankan backend PHP 8 + MySQL dengan antarmuka aplikasi, termasuk dashboard, Gantt, daftar project, quotation/PO, template, pengaturan, dan bahasa Indonesia, English, serta Korea. Dashboard memiliki prioritas harian, mingguan, dan bulanan dari target project, stage, serta milestone. Menu Improvement menerima issue lintas departemen, lampiran gambar/dokumen, link web atau path jaringan, review/feedback PI, dan meneruskan improvement yang disetujui menjadi project. Setiap PO dapat memakai mata uang berbeda; nilai vendor diringkas per mata uang. Admin dapat membuat akun Pengguna (akses penuh), Kontributor (hanya issue improvement miliknya), atau Viewer (baca saja). Data improvement tersimpan di JSON `tracker_state` yang sudah ada, tanpa migrasi tabel. File frontend bersama tetap berada di folder `public/`.

`server.js`, `config.json`, dan skrip Node lama hanya untuk backend terdahulu; jangan gunakan untuk menjalankan versi PHP ini. Untuk penggunaan jaringan, batasi akses ke LAN/VPN tepercaya dan gunakan HTTPS sebelum membuka aplikasi melalui jaringan tidak tepercaya.
