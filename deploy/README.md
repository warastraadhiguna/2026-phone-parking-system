# Deploy ke VPS bersama (`parking.wan-client.com`)

Untuk server Ubuntu yang **sudah** punya nginx, php8.4-fpm, PostgreSQL (15+), Redis, composer,
Node 20+ dan certbot, dan dipakai bersama situs lain. Skrip hanya menambah: situs nginx baru,
database baru, satu unit systemd, satu file cron. Tidak ada layanan global yang di-restart.

Yang di-deploy hanya `backend/`. Folder `android/` ikut ter-clone tetapi tidak dipakai server.

## Pertama kali

1. Buat record DNS **A** `parking.wan-client.com` → IP server, tunggu aktif.
2. Di server, sebagai root:

```bash
git clone https://github.com/warastraadhiguna/2026-phone-parking-system.git /var/www/parkir/html
cd /var/www/parkir/html && bash deploy/install.sh
```

Skrip menanyakan email, username dan nama Super Admin, lalu password (diketik, tidak terlihat).
Sisanya otomatis:

| Langkah | Hasil |
|---|---|
| Database | `parkir`, dengan dua role: `pati_owner` (hanya migrasi) dan `pati_app` (dipakai aplikasi; tidak bisa mengubah skema, buku kas, atau audit) |
| `.env` | `backend/.env`, password acak, `chmod 640`. Password role migrasi di `/root/.parkir-db-owner` |
| Redis | prefix `parkir_`, DB 4 dan 5 (aplikasi lain memakai 0/1) |
| Nginx + HTTPS | `/etc/nginx/sites-available/parking.wan-client.com`, lalu certbot |
| Queue | systemd `parkir-queue` (www-data, maks. 256 MB) |
| Scheduler + backup | `/etc/cron.d/parkir`; backup harian 02:45 ke `/var/backups/parkir` |
| Log | `backend/storage/logs/app.log`, dirotasi 30 hari (`/etc/logrotate.d/parkir`) |

Kalau skrip berhenti, ia menyebut baris dan perintahnya. Aman dijalankan ulang.

## Update berikutnya

Di komputer: `git push`. Di server:

```bash
cd /var/www/parkir/html && bash deploy/update.sh
```

Jalankan `update.sh` juga setelah mengubah `backend/.env` (konfigurasi di-cache).

## QRIS

Terpasang dengan Midtrans **sandbox** tanpa kunci, jadi QRIS menjawab "penyedia tidak dapat
dihubungi" sampai `MIDTRANS_SERVER_KEY` diisi di `backend/.env`. Setelah itu:
`bash deploy/update.sh`, lalu isi Payment Notification URL di dashboard Midtrans:
`https://parking.wan-client.com/api/v1/payments/webhooks/midtrans`.

## Aplikasi Android

Tidak di-deploy ke server. Bangun APK di komputer (alamat server sudah diisi di
`android/gradle.properties`):

```bash
cd android && ./gradlew assembleRelease     # perlu ditandatangani sebelum dipasang
./gradlew assembleDebug -PpatiApiBaseUrlDebug=https://parking.wan-client.com/   # untuk uji cepat
```

HP baru yang login pertama kali berstatus "menunggu persetujuan"; setujui di Control Center
menu **Perangkat**.

## Perintah rutin

```bash
systemctl is-active nginx php8.4-fpm postgresql redis-server parkir-queue
tail -n 50 /var/www/parkir/html/backend/storage/logs/app.log
cd /var/www/parkir/html/backend && sudo -u www-data /usr/bin/php8.4 artisan ops:queue-health
bash /var/www/parkir/html/deploy/backup.sh       # cadangan manual
```

## Catatan

- Cadangan masih di server yang sama. Salin `/var/backups/parkir` ke luar server sebelum dipakai
  untuk uang sungguhan. Pemulihan: [backup-restore.md](../docs/operations/backup-restore.md).
- Server ini dipakai bersama banyak situs. Untuk produksi penuh, lihat risiko di
  [security-review.md](../docs/operations/security-review.md).
- Jalur ini (pemasangan langsung) menggantikan image Docker di
  [deployment.md](../docs/operations/deployment.md); aturan lain di dokumen itu tetap berlaku.
