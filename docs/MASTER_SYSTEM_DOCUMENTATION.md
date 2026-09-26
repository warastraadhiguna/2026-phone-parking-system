# Sistem Perparkiran Kabupaten Pati
## Master System Documentation untuk Implementasi dengan Claude Code

**Dokumen kerja utama**  
**Versi:** 1.0  
**Status:** Approved Baseline for Implementation  
**Target implementer:** Claude Code  
**Arsitektur awal:** Laravel Modular Monolith + PostgreSQL + Redis + Kotlin Android  
**Payment awal:** QRIS Dynamic melalui payment gateway dengan implementasi awal Midtrans  
**Sifat dokumen:** Requirement + Architecture + Implementation Guardrails

---

# 1. Tujuan Dokumen

Dokumen ini menjadi sumber acuan utama bagi Claude Code dalam membangun **Sistem Perparkiran Kabupaten Pati**.

Claude harus:

1. Menganggap dokumen ini sebagai baseline requirement.
2. Tidak mengubah requirement inti tanpa persetujuan.
3. Tidak langsung membangun seluruh sistem sekaligus.
4. Mengerjakan sistem secara bertahap dan terverifikasi.
5. Menjaga kompatibilitas antara backend, web admin, dan aplikasi Android.
6. Memprioritaskan traceability, auditability, reliability, dan kemudahan operasional lapangan.
7. Menghindari over-engineering, khususnya microservices pada fase awal.

Sistem ini bukan sekadar aplikasi pembayaran parkir digital. Sistem ini adalah:

> **Parking Revenue Control System Kabupaten Pati**

Tujuan utamanya adalah menghubungkan aktivitas parkir lapangan, transaksi, pembayaran, setoran tunai, rekonsiliasi, monitoring, dan audit ke dalam satu sistem terpusat.

---

# 2. Latar Belakang Produk

Blueprint yang menjadi inspirasi sistem menekankan satu *digital control loop* yang menghubungkan:

- kendaraan,
- juru parkir,
- pembayaran,
- database pusat,
- command center pemerintah.

Konsep operasional dasarnya adalah:

1. Check-in / pencatatan kendaraan,
2. pencatatan sesi atau transaksi parkir,
3. pembayaran tunai atau QRIS,
4. pencatatan setoran tunai,
5. monitoring oleh pemerintah,
6. audit dan rekonsiliasi.

Untuk implementasi Kabupaten Pati, desain diperkuat dengan:

- registry juru parkir,
- registry lokasi,
- GPS dan geofence,
- offline-first mobile transaction,
- dynamic QRIS,
- settlement,
- reconciliation,
- immutable audit trail,
- fraud/anomaly flagging,
- versioned tariff,
- device binding,
- role-based access control,
- executive dashboard.

---

# 3. Prinsip Utama Sistem

## 3.1 Single Source of Truth

Seluruh transaksi harus masuk ke database pusat.

Database pusat adalah sumber kebenaran utama untuk:

- transaksi,
- pembayaran,
- setoran,
- saldo cash,
- tarif,
- lokasi,
- user,
- shift,
- audit trail.

Tidak boleh ada perhitungan keuangan resmi yang hanya hidup di mobile device.

---

## 3.2 Setiap Transaksi Harus Dapat Ditelusuri

Setiap transaksi minimal memiliki relasi ke:

- juru parkir,
- lokasi,
- shift,
- device,
- waktu,
- jenis kendaraan,
- tarif yang berlaku,
- metode pembayaran,
- status pembayaran,
- status sinkronisasi,
- audit trail.

---

## 3.3 Tidak Ada Hard Delete untuk Data Keuangan

Data berikut tidak boleh dihapus permanen melalui flow aplikasi biasa:

- parking transaction,
- payment,
- cash settlement,
- reconciliation,
- tariff history,
- audit log.

Kesalahan transaksi diselesaikan melalui:

- void,
- reversal,
- adjustment,
- approval workflow.

---

## 3.4 Cash Tetap Didukung

Sistem tidak boleh dirancang sebagai cashless-only.

Metode pembayaran awal:

- CASH
- QRIS

Cash tetap harus dapat dikontrol melalui:

- expected cash,
- deposited cash,
- outstanding cash,
- settlement history.

---

## 3.5 Offline-First untuk Mobile

Aplikasi jukir harus tetap dapat mencatat transaksi saat koneksi internet buruk.

Offline mode wajib memiliki:

- local database,
- transaction queue,
- retry sync,
- idempotency,
- UUID,
- device timestamp,
- sequence number,
- sync status.

---

# 4. Ruang Lingkup Sistem

Sistem memiliki tiga komponen utama:

## 4.1 Backend/API

Teknologi utama:

- Laravel
- PostgreSQL
- Redis
- Queue Worker
- REST API

Arsitektur:

> Modular Monolith

Backend harus stateless sebanyak mungkin agar dapat di-scale horizontal jika dibutuhkan.

---

## 4.2 Web Admin / Control Center

Digunakan oleh:

- Dishub Admin,
- operator,
- finance,
- supervisor,
- auditor,
- executive viewer.

Fungsi utama:

- dashboard,
- monitoring,
- master data,
- konfigurasi,
- transaksi,
- payment,
- settlement,
- reconciliation,
- audit,
- fraud review,
- reporting.

---

## 4.3 Android App untuk Juru Parkir

Teknologi:

- Kotlin
- Android native
- Room Database
- REST API
- background sync

Fungsi utama:

- login,
- device binding,
- shift,
- lokasi aktif,
- transaksi parkir,
- CASH,
- QRIS,
- offline queue,
- sync,
- cash balance,
- setoran,
- riwayat.

---

# 5. Batasan Awal

Untuk fase pertama:

- jangan gunakan microservices,
- jangan gunakan Kubernetes,
- jangan gunakan Kafka,
- jangan gunakan event sourcing penuh,
- jangan membangun aplikasi customer,
- jangan mewajibkan masyarakat meng-install aplikasi,
- jangan mewajibkan foto kendaraan di setiap transaksi,
- jangan membuat AI fraud detection,
- jangan mengintegrasikan terlalu banyak payment provider sekaligus.

Prinsip:

> Build simple, auditable, scalable-enough, and maintainable.

---

# 6. User Roles

Role awal:

## 6.1 Super Admin

Hak:

- konfigurasi sistem,
- semua master data,
- user management,
- role management,
- payment configuration,
- audit read,
- system configuration.

---

## 6.2 Dishub Admin

Hak:

- lokasi,
- jukir,
- penugasan,
- tarif,
- monitoring,
- laporan,
- konfigurasi operasional tertentu.

---

## 6.3 Parking Operator

Hak:

- monitoring transaksi,
- monitoring shift,
- melihat lokasi,
- melihat status jukir,
- melihat transaksi.

Tidak boleh:

- mengubah payment yang sudah paid,
- menghapus transaksi,
- mengubah audit log.

---

## 6.4 Finance

Hak:

- cash settlement,
- reconciliation,
- payment monitoring,
- laporan keuangan operasional.

---

## 6.5 Supervisor

Hak:

- approve void,
- approve adjustment,
- review anomaly,
- review shift issue.

---

## 6.6 Auditor

Read-only terhadap:

- transaksi,
- payment,
- settlement,
- reconciliation,
- audit trail,
- tariff history,
- user activity.

---

## 6.7 Executive Viewer

Read-only terhadap:

- dashboard eksekutif,
- KPI,
- revenue,
- target,
- lokasi,
- tren,
- outstanding.

---

## 6.8 Juru Parkir

Mobile-only.

Hak:

- login,
- start shift,
- create transaction,
- receive cash transaction,
- create QRIS payment,
- view shift cash,
- submit settlement,
- end shift.

---

# 7. Modul Sistem

Backend harus dipisahkan dalam domain/module berikut.

```text
Identity
ParkingLocation
ParkingAttendant
Device
Assignment
Shift
Tariff
ParkingTransaction
Payment
CashLedger
CashSettlement
Reconciliation
Audit
FraudReview
Reporting
Notification
SystemConfiguration
```

Setiap module tetap berada dalam satu Laravel application pada fase awal.

---

# 8. Struktur Domain Utama

## 8.1 Parking Attendant

Field minimal:

```text
id
attendant_code
name
identity_number
phone
photo
status
registered_at
expired_at
created_at
updated_at
```

Status:

```text
ACTIVE
INACTIVE
SUSPENDED
EXPIRED
```

---

## 8.2 Parking Location

Field minimal:

```text
id
location_code
name
address
latitude
longitude
geofence_radius_m
location_type
status
motorcycle_capacity
car_capacity
created_at
updated_at
```

Status:

```text
ACTIVE
INACTIVE
SUSPENDED
```

---

## 8.3 Device

Satu jukir dapat memiliki device terdaftar.

Field:

```text
id
device_uuid
attendant_id
device_model
android_version
app_version
registered_at
last_seen_at
status
```

Status:

```text
ACTIVE
REVOKED
LOST
```

Device binding harus dapat dicabut admin.

---

## 8.4 Assignment

Menghubungkan jukir dengan lokasi.

```text
id
attendant_id
location_id
effective_from
effective_until
status
```

---

## 8.5 Shift

```text
id
shift_uuid
attendant_id
location_id
device_id
started_at_device
started_at_server
ended_at_device
ended_at_server
start_latitude
start_longitude
end_latitude
end_longitude
status
```

Status:

```text
OPEN
CLOSED
FORCED_CLOSED
```

Satu jukir hanya boleh memiliki satu active shift.

---

# 9. Tariff Management

Tarif tidak boleh hardcoded.

Model:

```text
id
vehicle_type
location_type
location_id nullable
amount
effective_from
effective_until
regulation_reference
status
created_by
approved_by
```

Vehicle type fase awal:

```text
MOTORCYCLE
CAR
OTHER
```

Tarif transaksi harus disimpan sebagai snapshot.

Contoh:

```text
tariff_id
tariff_amount_snapshot
```

Perubahan tarif di masa depan tidak boleh mengubah transaksi historis.

---

# 10. Parking Transaction

Field minimal:

```text
id
transaction_uuid
transaction_number
shift_id
attendant_id
location_id
device_id

vehicle_type
vehicle_plate nullable

tariff_id
tariff_amount

payment_method

transaction_time_device
transaction_time_server

latitude
longitude
gps_accuracy

offline_created
sync_sequence

status

created_at
updated_at
```

Status:

```text
PENDING
WAITING_PAYMENT
PAID
COMPLETED
VOID_REQUESTED
VOIDED
CANCELLED
```

Catatan:

Untuk CASH, transaksi dapat langsung menjadi `COMPLETED`.

Untuk QRIS:

```text
WAITING_PAYMENT
↓
PAID
↓
COMPLETED
```

---

# 11. Cash Flow

Jika transaksi CASH sebesar Rp2.000:

```text
transaction
↓
cash ledger +2000
↓
expected cash jukir +2000
```

Sistem harus mampu menghitung:

```text
Expected Cash
-
Cash Deposited
=
Outstanding Cash
```

Contoh:

```text
Expected Cash     Rp120.000
Deposited         Rp100.000
Outstanding       Rp20.000
```

---

# 12. Cash Ledger

Setiap pergerakan cash harus memiliki ledger.

Tipe awal:

```text
PARKING_CASH_IN
SETTLEMENT_OUT
ADJUSTMENT
REVERSAL
```

Field:

```text
id
attendant_id
shift_id
transaction_id nullable
settlement_id nullable
type
amount
balance_after
created_at
```

Ledger tidak boleh diedit langsung.

---

# 13. Cash Settlement

Jukir dapat mengajukan setoran.

Field:

```text
id
settlement_number
attendant_id
shift_id
amount
submitted_at
verified_at
verified_by
status
proof_file nullable
notes
```

Status:

```text
SUBMITTED
VERIFIED
REJECTED
CANCELLED
```

Pada fase awal, proses verifikasi dapat dilakukan manual oleh finance/admin.

---

# 14. Reconciliation

Reconciliation minimal mencocokkan:

```text
Parking Transactions
vs
Payment Records
vs
Cash Ledger
vs
Cash Settlement
```

Output:

```text
expected_cash
cash_deposited
cash_outstanding
qris_expected
qris_paid
qris_difference
total_revenue
```

---

# 15. QRIS Architecture

Payment harus dibuat melalui abstraction layer.

Interface konseptual:

```text
PaymentGatewayInterface
```

Implementasi awal:

```text
MidtransPaymentGateway
```

Arsitektur:

```text
Parking Transaction
        ↓
Payment Service
        ↓
PaymentGatewayInterface
        ↓
Midtrans
```

Hal ini memungkinkan provider lain ditambahkan kemudian.

---

# 16. QRIS Dynamic Flow

Flow:

```text
Jukir pilih QRIS
↓
Backend membuat Parking Transaction
↓
Backend membuat Payment record
↓
Payment gateway membuat Dynamic QR
↓
QR ditampilkan pada Android
↓
Customer scan
↓
Payment Gateway menerima pembayaran
↓
Gateway mengirim webhook
↓
Backend memverifikasi webhook
↓
Payment = PAID
↓
Transaction = COMPLETED
↓
Android mendapat status terbaru
```

Android tidak boleh menentukan sendiri bahwa pembayaran berhasil.

Sumber kebenaran status QRIS adalah backend berdasarkan provider confirmation.

---

# 17. Payment Table

Field:

```text
id
payment_uuid
transaction_id
provider
provider_reference
payment_method
amount
status
expired_at
paid_at
raw_response_reference
created_at
updated_at
```

Status:

```text
CREATED
PENDING
PAID
EXPIRED
FAILED
REFUNDED
CANCELLED
```

---

# 18. Payment Webhook

Webhook wajib:

- verify authenticity,
- idempotent,
- log safely,
- tidak double-credit,
- menggunakan transaction/database lock bila diperlukan.

Webhook yang sama dapat dikirim ulang oleh provider.

Backend wajib aman terhadap duplicate callback.

---

# 19. QRIS Expiration

Dynamic QR harus memiliki expiration.

Jika QR expired:

```text
Payment = EXPIRED
Transaction = CANCELLED / payment retry allowed
```

Desain final retry ditentukan pada implementasi payment module.

---

# 20. Offline Transaction

Offline diperbolehkan untuk:

```text
CASH
```

QRIS membutuhkan internet.

Jika device offline:

```text
CASH transaction
↓
stored locally
↓
status UNSYNCED
↓
internet available
↓
sync API
↓
server confirms
↓
status SYNCED
```

Offline QRIS tidak perlu didukung pada MVP.

---

# 21. Idempotent Sync

Setiap offline transaction harus memiliki:

```text
transaction_uuid
device_uuid
sync_sequence
```

Jika Android mengirim transaksi yang sama dua kali:

server tidak boleh membuat dua transaksi.

Server harus mengembalikan transaction existing.

---

# 22. Mobile Local Database

Gunakan Room.

Entitas lokal minimal:

```text
LocalShift
LocalTransaction
SyncQueue
LocalConfig
```

Sync queue status:

```text
PENDING
SYNCING
SYNCED
FAILED
```

---

# 23. Sync Strategy

Gunakan pendekatan:

```text
write local
↓
enqueue
↓
background sync
↓
server acknowledgement
↓
mark synced
```

Retry menggunakan exponential backoff.

Jangan menghapus data lokal sebelum server ACK diterima.

---

# 24. GPS & Geofence

Setiap transaksi menyimpan:

```text
latitude
longitude
accuracy
timestamp
```

Server membandingkan GPS dengan geofence lokasi.

Hasil:

```text
INSIDE
OUTSIDE
UNKNOWN
```

Transaksi di luar radius tidak otomatis dihapus.

Sistem dapat:

- menolak,
- menerima tetapi flag,
- memerlukan supervisor review.

Default MVP:

> menerima transaksi tetapi memberikan anomaly flag jika GPS berada di luar geofence secara signifikan.

Hal ini menghindari kehilangan transaksi saat GPS device tidak akurat.

---

# 25. Location Integrity

Minimal rule:

```text
GPS accuracy
Geofence distance
Mock-location indicator
Impossible movement
Device consistency
Timestamp consistency
```

Output risk:

```text
NORMAL
WARNING
SUSPICIOUS
```

Jangan menggunakan AI pada MVP.

Gunakan rule-based detection.

---

# 26. Mock Location

Android harus berusaha mendeteksi penggunaan mock location.

Namun:

> Mock location detection tidak boleh dianggap 100% anti-fraud.

Semua hasil menjadi signal.

Backend menyimpan:

```text
mock_location_detected
location_risk
```

---

# 27. Audit Trail

Semua tindakan penting harus masuk audit log.

Contoh:

```text
LOGIN
LOGOUT
START_SHIFT
END_SHIFT
CREATE_TRANSACTION
PAYMENT_CREATED
PAYMENT_PAID
SETTLEMENT_SUBMITTED
SETTLEMENT_VERIFIED
VOID_REQUESTED
VOID_APPROVED
TARIFF_CHANGED
USER_CHANGED
DEVICE_REVOKED
```

Field:

```text
id
actor_type
actor_id
action
entity_type
entity_id
metadata
ip_address
device_id
created_at
```

Audit log:

- immutable secara aplikasi,
- tidak memiliki UI edit,
- tidak memiliki ordinary delete.

---

# 28. Void Transaction

Flow:

```text
Jukir / operator request void
↓
VOID_REQUESTED
↓
Supervisor review
↓
APPROVE / REJECT
```

Jika approve:

```text
transaction = VOIDED
```

Tetapi transaksi asli tetap disimpan.

Financial reversal dicatat melalui ledger/reversal entry.

---

# 29. Dashboard Operator

Minimal widgets:

```text
Total Revenue Today
Cash Revenue
QRIS Revenue
Transaction Count
Active Jukir
Active Locations
Open Shift
Outstanding Cash
Pending Settlement
Payment Failure
Anomaly Count
```

---

# 30. Dashboard Executive

Lebih sederhana:

```text
Revenue Today
Revenue This Month
Target Progress
Transaction Count
Cash vs QRIS
Outstanding Cash
Top Locations
Revenue Trend
Map
```

Tidak perlu detail teknis.

---

# 31. Monitoring Map

Peta minimal menampilkan:

- lokasi parkir,
- status active/inactive,
- jumlah transaksi hari ini,
- revenue hari ini.

Tidak perlu realtime vehicle tracking.

---

# 32. Reporting

Laporan minimal:

```text
Revenue by Date
Revenue by Location
Revenue by Attendant
Revenue by Vehicle Type
Cash vs QRIS
Cash Outstanding
Settlement
Reconciliation
Transaction Detail
Anomaly
Audit Log
```

Export:

```text
CSV
XLSX
PDF
```

Export besar harus diproses via queue.

---

# 33. Security

Minimal:

- HTTPS only,
- token authentication,
- refresh mechanism,
- RBAC,
- rate limiting,
- password hashing,
- secure secrets,
- no secret in repository,
- validation,
- SQL injection protection melalui ORM/query binding,
- XSS protection,
- CSRF untuk web bila relevan,
- audit login,
- webhook verification.

---

# 34. Authentication Mobile

Recommended:

```text
Access Token
+
Refresh Token
```

Token disimpan menggunakan Android secure storage.

Jangan menyimpan password plaintext.

---

# 35. Device Binding

Login jukir harus terkait device.

Scenario:

```text
first login
↓
register device
↓
device approved/activated
```

Jika account login di device baru:

- optional block,
- atau memerlukan re-approval.

MVP direkomendasikan:

> hanya satu active device per jukir.

---

# 36. Laravel Architecture

Gunakan modular monolith.

Contoh:

```text
app/
  Domain/
    Identity/
    ParkingLocation/
    ParkingAttendant/
    Shift/
    Tariff/
    ParkingTransaction/
    Payment/
    CashSettlement/
    Reconciliation/
    Audit/
```

Claude boleh menyesuaikan layout jika mengikuti praktik Laravel yang sehat.

Namun boundary domain harus tetap jelas.

---

# 37. Database

Gunakan PostgreSQL.

Gunakan:

- foreign keys,
- unique constraints,
- indexes,
- database transactions,
- row locking ketika diperlukan.

Index yang kemungkinan penting:

```text
transaction_uuid
transaction_number
attendant_id
location_id
shift_id
transaction_time_server
payment status
settlement status
```

Jangan melakukan premature indexing berlebihan.

---

# 38. Redis

Digunakan untuk:

- cache,
- queue,
- rate limiting,
- optional distributed lock.

Jangan jadikan Redis sebagai source of truth keuangan.

---

# 39. Queue

Gunakan queue untuk proses asynchronous:

```text
report export
notification
dashboard aggregation
anomaly analysis
audit enrichment
payment reconciliation
```

Create parking transaction tidak boleh menunggu proses berat tersebut.

---

# 40. Scalability

Target awal sistem harus dapat menangani ribuan juru parkir terdaftar.

Jumlah user terdaftar bukan alasan menggunakan microservices.

Backend harus dapat di-scale horizontal:

```text
Nginx / Load Balancer
        ↓
Laravel 1
Laravel 2
Laravel N
        ↓
PostgreSQL
Redis
```

Laravel app harus sebisa mungkin stateless.

---

# 41. API Versioning

Semua API mobile:

```text
/api/v1/
```

Contoh:

```text
POST /api/v1/auth/login
POST /api/v1/shifts/start
POST /api/v1/shifts/end
POST /api/v1/parking-transactions
GET  /api/v1/parking-transactions/{id}
POST /api/v1/payments/qris
GET  /api/v1/payments/{id}
POST /api/v1/settlements
POST /api/v1/sync/transactions
```

---

# 42. Standard API Response

Recommended:

```json
{
  "success": true,
  "data": {},
  "meta": {},
  "error": null
}
```

Error:

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "SHIFT_NOT_ACTIVE",
    "message": "Active shift is required."
  }
}
```

---

# 43. Error Codes

Jangan mengandalkan message string.

Gunakan code seperti:

```text
AUTH_INVALID
DEVICE_NOT_ALLOWED
SHIFT_NOT_ACTIVE
LOCATION_NOT_ALLOWED
TARIFF_NOT_FOUND
TRANSACTION_DUPLICATE
PAYMENT_FAILED
PAYMENT_EXPIRED
SETTLEMENT_INVALID
SYNC_CONFLICT
```

---

# 44. Logging

Gunakan structured logging.

Jangan log:

- password,
- full access token,
- private API key,
- full sensitive payment payload jika tidak perlu.

Log harus memiliki correlation ID/request ID.

---

# 45. Testing Strategy

Minimal:

## Unit Test

Untuk:

- tariff selection,
- cash calculation,
- reconciliation,
- geofence rule,
- status transition.

## Feature Test

Untuk:

- login,
- shift,
- create transaction,
- duplicate transaction,
- QRIS flow,
- webhook idempotency,
- settlement,
- void.

## Integration Test

Untuk payment gateway sandbox.

---

# 46. Data Integrity Rules

Aturan penting:

1. Satu active shift per jukir.
2. Satu transaction UUID hanya boleh satu.
3. Paid payment tidak boleh kembali ke pending.
4. Completed transaction tidak boleh diedit langsung.
5. Settlement verified tidak boleh diedit langsung.
6. Tariff history tidak boleh overwrite.
7. Audit record immutable.
8. QRIS amount harus sama dengan transaction amount.
9. Cash transaction harus menambah cash ledger.
10. Void cash transaction harus membuat reversal.

---

# 47. State Transition

Parking Transaction:

```text
PENDING
↓
WAITING_PAYMENT
↓
PAID
↓
COMPLETED
```

Cash:

```text
PENDING
↓
COMPLETED
```

Void:

```text
COMPLETED
↓
VOID_REQUESTED
↓
VOIDED
```

QRIS expired:

```text
WAITING_PAYMENT
↓
CANCELLED
```

Claude tidak boleh membuat transisi status sembarangan.

---

# 48. Minimum MVP

MVP dianggap selesai jika terdapat:

## Backend

- authentication,
- role,
- jukir,
- lokasi,
- assignment,
- device,
- shift,
- tariff,
- cash transaction,
- QRIS transaction,
- payment webhook,
- cash ledger,
- settlement,
- basic reconciliation,
- audit trail.

## Admin

- login,
- dashboard,
- locations,
- attendants,
- assignments,
- tariff,
- transactions,
- payments,
- settlements,
- basic reports.

## Android

- login,
- device binding,
- start shift,
- cash transaction,
- QRIS transaction,
- offline CASH transaction,
- sync,
- view cash summary,
- submit settlement,
- end shift.

---

# 49. Feature yang Bukan MVP

Jangan dikerjakan sebelum MVP stabil:

- AI vehicle recognition,
- ANPR otomatis,
- IoT parking sensor,
- barrier integration,
- customer mobile application,
- dynamic pricing,
- predictive analytics,
- advanced AI fraud detection,
- Kubernetes,
- microservices,
- Kafka.

---

# 50. Delivery Phases untuk Claude

Claude harus mengerjakan berurutan.

---

## PHASE 0 — Repository & Architecture

Deliverables:

- repo structure,
- environment template,
- Docker development environment,
- Laravel bootable,
- PostgreSQL,
- Redis,
- health endpoint,
- coding convention,
- architecture note.

Stop setelah phase selesai.

---

## PHASE 1 — Identity & Access

Deliverables:

- users,
- roles,
- permissions,
- auth API,
- admin login,
- mobile login,
- tests.

---

## PHASE 2 — Master Data

Deliverables:

- parking locations,
- attendants,
- devices,
- assignments,
- tariffs.

---

## PHASE 3 — Shift

Deliverables:

- start shift,
- active shift,
- end shift,
- location validation,
- GPS capture.

---

## PHASE 4 — Cash Transaction

Deliverables:

- create cash transaction,
- tariff snapshot,
- cash ledger,
- transaction history,
- idempotency.

---

## PHASE 5 — Android Offline Sync

Deliverables:

- Room DB,
- local transaction,
- sync queue,
- retry,
- duplicate-safe sync.

---

## PHASE 6 — QRIS

Deliverables:

- PaymentGatewayInterface,
- Midtrans adapter,
- dynamic QR,
- payment record,
- webhook,
- payment status,
- sandbox tests.

Claude wajib mengecek dokumentasi Midtrans terbaru pada saat fase ini dikerjakan.

---

## PHASE 7 — Settlement

Deliverables:

- cash summary,
- submit settlement,
- admin verification,
- outstanding cash.

---

## PHASE 8 — Reconciliation

Deliverables:

- daily reconciliation,
- attendant reconciliation,
- location reconciliation,
- mismatch reporting.

---

## PHASE 9 — Audit & Anomaly

Deliverables:

- immutable audit log,
- geofence anomaly,
- mock location signal,
- impossible movement basic rule,
- review queue.

---

## PHASE 10 — Dashboard & Reporting

Deliverables:

- operator dashboard,
- executive dashboard,
- reports,
- exports.

---

## PHASE 11 — Hardening

Deliverables:

- performance tests,
- security review,
- database index review,
- queue failure handling,
- backup/restore documentation,
- observability,
- deployment documentation.

---

# 51. Claude Working Rules

Claude wajib mengikuti aturan berikut.

## Rule 1

Jangan lanjut ke phase berikut sebelum phase aktif:

- berhasil dibangun,
- dites,
- hasilnya dilaporkan.

## Rule 2

Jangan mengubah stack utama tanpa persetujuan.

## Rule 3

Jangan melakukan rewrite besar tanpa alasan kuat.

## Rule 4

Jangan menambahkan dependency hanya karena populer.

## Rule 5

Setiap migration harus reversible bila memungkinkan.

## Rule 6

Setiap endpoint penting harus memiliki test.

## Rule 7

Tidak boleh membuat credential palsu seolah-olah production credential.

## Rule 8

Integrasi payment harus menggunakan sandbox hingga ada izin production.

## Rule 9

Tidak boleh hardcode:

- tariff,
- secret,
- merchant credential,
- base URL,
- API key.

## Rule 10

Setiap akhir phase, Claude harus membuat implementation report.

---

# 52. Format Implementation Report Claude

Setiap phase harus ditutup dengan:

```text
PHASE:
STATUS:

IMPLEMENTED:
- ...

FILES CHANGED:
- ...

DATABASE CHANGES:
- ...

API:
- ...

TESTS:
- ...

KNOWN LIMITATIONS:
- ...

SECURITY NOTES:
- ...

NEXT RECOMMENDED PHASE:
- ...

BLOCKERS:
- ...
```

Status hanya:

```text
DONE
PARTIAL
BLOCKED
```

Jangan menyatakan DONE jika test penting gagal.

---

# 53. Definition of Done

Feature dianggap DONE jika:

1. requirement terpenuhi,
2. migration berhasil,
3. API konsisten,
4. test relevan lulus,
5. tidak ada known critical bug,
6. error handling ada,
7. logging cukup,
8. authorization diterapkan,
9. dokumentasi singkat tersedia.

---

# 54. Production Guardrail

Sebelum production:

- environment production terpisah,
- payment production credential terpisah,
- HTTPS,
- database backup,
- restore test,
- Redis persistence/configuration reviewed,
- worker supervisor,
- log rotation,
- monitoring,
- alerting,
- secret management,
- database migration plan,
- rollback plan.

Tidak boleh mengaktifkan production payment hanya karena sandbox sudah lulus.

---

# 55. Deployment Baseline

Baseline awal:

```text
Linux Server
Nginx
Laravel PHP-FPM
PostgreSQL
Redis
Supervisor/Systemd Queue Worker
```

Containerization diperbolehkan.

Production dapat memiliki:

```text
Load Balancer
↓
Multiple Laravel Instances
↓
PostgreSQL
Redis
```

jika trafik membutuhkan.

---

# 56. Performance Philosophy

Tidak melakukan premature optimization.

Namun hindari:

- N+1 query,
- dashboard query berat langsung,
- synchronous report generation,
- synchronous notification,
- full-table scan untuk transaksi besar.

Gunakan:

- indexing,
- pagination,
- queue,
- caching yang aman,
- aggregation bila dibutuhkan.

---

# 57. Naming

Working product name:

> Sistem Perparkiran Kabupaten Pati

Internal technical name boleh:

```text
pati-parking-platform
```

Aplikasi Android:

```text
Pati Parking
```

Nama branding final dapat berubah kemudian tanpa mengubah domain architecture.

---

# 58. Acceptance Scenario Utama

## Scenario A — CASH

```text
Jukir login
↓
Start Shift
↓
Pilih MOTOR
↓
Tarif muncul
↓
Pilih CASH
↓
Transaction completed
↓
Cash expected bertambah
↓
Audit tercatat
```

---

## Scenario B — QRIS

```text
Jukir login
↓
Start Shift
↓
Pilih MOTOR
↓
Pilih QRIS
↓
Dynamic QR muncul
↓
Customer scan & bayar
↓
Webhook diterima
↓
Payment PAID
↓
Transaction COMPLETED
```

---

## Scenario C — Offline CASH

```text
Internet putus
↓
Jukir create CASH transaction
↓
Stored Room DB
↓
Transaction local pending sync
↓
Internet kembali
↓
Auto sync
↓
Server accepts
↓
Local transaction marked synced
```

---

## Scenario D — Settlement

```text
Expected Cash 100.000
↓
Jukir submit 90.000
↓
Finance verify
↓
Outstanding 10.000
```

---

## Scenario E — Duplicate Sync

```text
Device kirim UUID ABC
↓
Server create transaction
↓
Response hilang
↓
Device retry UUID ABC
↓
Server menemukan existing transaction
↓
Tidak membuat duplikat
```

---

# 59. Critical Success Criteria

Sistem berhasil bila pemerintah dapat menjawab:

```text
Berapa kendaraan parkir?
Di mana?
Dilayani siapa?
Tarif berapa?
Metode pembayaran apa?
Berapa uang seharusnya diterima?
Berapa yang benar-benar diterima?
Berapa cash yang belum disetor?
Berapa QRIS yang sudah confirmed?
Apakah ada selisih?
Apakah transaksi memiliki jejak audit?
```

---

# 60. Instruksi Awal untuk Claude

Saat menerima dokumen ini, Claude jangan langsung coding semua fitur.

Pertama lakukan:

1. review dokumen,
2. identifikasi ambiguity,
3. usulkan struktur repository,
4. usulkan domain boundaries,
5. usulkan initial database model,
6. usulkan Phase 0 implementation plan,
7. jangan memulai Phase 1 sebelum Phase 0 diterima.

Output pertama Claude harus berupa:

```text
UNDERSTANDING
ARCHITECTURE PROPOSAL
REPOSITORY STRUCTURE
DATABASE HIGH-LEVEL MODEL
PHASE 0 PLAN
RISKS / QUESTIONS
```

Claude harus menghindari pertanyaan minor yang sebenarnya dapat diputuskan dengan best practice teknis.

Tanyakan hanya keputusan yang:

- mempengaruhi proses bisnis,
- mempengaruhi uang,
- mempengaruhi regulasi,
- sulit diubah setelah implementasi.

---

# 61. Keputusan Arsitektur yang Sudah Final

Claude tidak perlu meminta konfirmasi ulang untuk:

```text
Backend        Laravel
Architecture   Modular Monolith
Database       PostgreSQL
Cache/Queue    Redis
Android        Kotlin Native
Mobile DB      Room
API            REST
Payment        QRIS Dynamic
Provider MVP   Midtrans
Cash           Supported
Offline        CASH supported
QRIS Offline   Not supported
Audit Trail    Required
Geofence       Required
Microservices  Not for MVP
```

---

# 62. Hal yang Masih Perlu Dikonfirmasi dengan Pemilik Produk

Sebelum production final:

1. Tarif resmi per jenis kendaraan.
2. Daftar resmi lokasi parkir.
3. Struktur organisasi operasional Dishub.
4. Approval flow setoran.
5. Approval flow void.
6. Apakah plat kendaraan wajib.
7. Radius geofence per lokasi.
8. Format nomor transaksi resmi.
9. Payment merchant ownership.
10. Rekening tujuan QRIS.
11. Policy settlement per shift atau per hari.
12. Format laporan resmi yang dibutuhkan.
13. Retention period data.
14. Requirement integrasi dengan sistem Pemkab lain.

Claude boleh menggunakan nilai dummy/dev untuk development, tetapi tidak boleh menganggapnya sebagai keputusan production.

---

# 63. Prinsip Penutup

Sistem ini harus dirancang sebagai sistem kontrol pendapatan.

Urutan prioritas:

```text
Correctness
↓
Auditability
↓
Data Integrity
↓
Operational Reliability
↓
Security
↓
Maintainability
↓
Performance
↓
Advanced Features
```

Jangan mengorbankan auditability demi UI yang terlihat modern.

Jangan mengorbankan data integrity demi development yang cepat.

Jangan menggunakan arsitektur kompleks tanpa alasan.

Target akhir:

> setiap transaksi parkir memiliki identitas, lokasi, petugas, tarif, pembayaran, dan jejak audit yang dapat direkonsiliasi secara terpusat.

---

# Appendix A — Suggested Repository Structure

```text
pati-parking/
│
├── backend/
│   ├── app/
│   ├── database/
│   ├── routes/
│   ├── tests/
│   └── ...
│
├── admin-web/
│   └── ...
│
├── android/
│   └── ...
│
├── docs/
│   ├── architecture/
│   ├── api/
│   ├── database/
│   ├── implementation-reports/
│   └── decisions/
│
├── docker/
│
├── README.md
└── CLAUDE.md
```

Claude boleh menggunakan monorepo.

---

# Appendix B — Suggested CLAUDE.md Rules

```text
1. Read docs/MASTER_SYSTEM_DOCUMENTATION.md before coding.
2. Work only on the currently approved phase.
3. Do not introduce microservices.
4. Do not change financial state transition without approval.
5. Do not hardcode tariffs or payment credentials.
6. Keep payment integration behind PaymentGatewayInterface.
7. Keep transaction creation idempotent.
8. Preserve immutable audit history.
9. CASH must work offline.
10. QRIS status must be server/provider verified.
11. Add tests for each critical business rule.
12. At the end of each phase produce an implementation report.
```

---

# Appendix C — First Prompt to Give Claude

```text
You are the implementation engineer for Sistem Perparkiran Kabupaten Pati.

Read MASTER_SYSTEM_DOCUMENTATION.md completely.

Do not start full implementation yet.

Your first task is architecture review and Phase 0 planning only.

Produce:

1. Your understanding of the system.
2. Proposed monorepo/repository structure.
3. Proposed Laravel modular-monolith boundaries.
4. High-level PostgreSQL entity model.
5. Authentication approach for admin and Android.
6. Offline-sync strategy summary.
7. Payment abstraction strategy.
8. Phase 0 implementation plan.
9. Technical risks or ambiguities that truly require owner decisions.

Constraints:
- Laravel modular monolith.
- PostgreSQL.
- Redis.
- Android Kotlin + Room.
- REST API.
- Dynamic QRIS with Midtrans as initial provider.
- CASH must support offline transactions.
- No microservices.
- No Kubernetes.
- Do not start Phase 1 yet.
- Do not invent production credentials or official tariff values.

After presenting the plan, stop and wait for approval.
```
