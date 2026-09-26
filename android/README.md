# Pati Parking — Android app (attendant)

Kotlin, Jetpack Compose, Room, WorkManager, OkHttp. Offline-first: every action is written to
Room and a sync queue first, then sent to the backend (`/api/v1`) when the network allows.
Contracts: [docs/api](../docs/api/README.md), offline rules: [ADR-0008](../docs/decisions/0008-offline-operation-policy.md).

## Requirements

- JDK 21 (Android Studio's bundled JBR works: `C:\Program Files\Android\Android Studio\jbr`).
- Android SDK with platform 37. `local.properties` holds `sdk.dir` (not committed).
- minSdk 26.

## Build and test

```sh
cd android
./gradlew testDebugUnitTest      # JVM unit tests (tariff, offline rules, sync engine, DTOs)
./gradlew assembleDebug          # app/build/outputs/apk/debug/app-debug.apk
```

## Backend URL

Set per build type in `gradle.properties`, exposed as `BuildConfig.API_BASE_URL`. It is never
hardcoded in the source:

| Property | Default | Notes |
|---|---|---|
| `patiApiBaseUrlDebug` | `http://10.0.2.2:8080/` | The emulator's alias for the host running `docker compose` |
| `patiApiBaseUrlRelease` | `https://parkir.example.invalid/` | Placeholder. Set the real HTTPS URL at release time (`-PpatiApiBaseUrlRelease=…`) |

Cleartext HTTP is allowed **only in debug builds** (`network_security_config` in `src/debug`).

## What the app does (MVP)

- **Login** with username and password. The device UUID is generated once. A new device starts
  as `PENDING_APPROVAL` until an operator approves it in the Control Center.
- Tokens are encrypted with an Android Keystore AES-GCM key (`SecureTokenStore`). Passwords are
  never stored. Backups exclude tokens and the database.
- **Bootstrap cache**: assignment, location, tariff schedule, settings, `valid_until`.
- **Start shift** online, or offline when the 6 ADR-0008 preconditions hold (`OfflineShiftRules`).
- **Record CASH**: the price comes from the cached tariff schedule (`TariffCalculator`). The app
  keeps a per-device `sync_sequence` and GPS fix with accuracy and mock flag.
- **QRIS** (online only): shows the dynamic QR from the server and polls the status every 3 s. The
  attendant can withdraw the QR. The app never decides that a payment succeeded. A request whose
  answer was lost is resent unchanged (same UUID). QRIS rows are stored locally but never queued.
- **Setor kas**: shows expected / deposited / outstanding from the server ledger. The attendant
  declares a deposit (online, only after everything is synced; retried unchanged) and can
  withdraw it until finance decides.
- **End shift**.
- Pending/failed sync counters and the cash held are shown on the home screen.

## Sync

`sync/SyncEngine` drains `sync_queue` in the server's required order: shift starts, then
transactions in batches of 50 (`POST /api/v1/sync/transactions`), then shift ends once all of a
shift's transactions are synced. `SyncWorker` (WorkManager) runs it on network availability with
exponential backoff. Payloads are resent unchanged, so retries are safe. Permanently rejected
items become FAILED and stay on the device. Details: [docs/api/sync.md](../docs/api/sync.md).

## Backend integration test (opt-in)

`BackendIntegrationTest` runs the real `ApiClient` and `SyncEngine` against a running backend.
It simulates an offline shift with 3 cash transactions, syncs it, then resends everything and
checks that nothing is duplicated. A second test issues a QRIS payment, polls it and withdraws it. A third test declares a cash
deposit twice with the same UUID, gets the same settlement back, and then withdraws it. It is skipped unless the variables below are set:

```sh
docker compose up -d && docker compose exec app php artisan db:seed
export PATI_E2E_BASE_URL=http://localhost:8080/
export PATI_E2E_USERNAME=jp-000001
export PATI_E2E_PASSWORD=<DEV_SEED_PASSWORD from backend/.env>
export PATI_E2E_DEVICE_UUID=<uuid of an ACTIVE device of that attendant>
./gradlew testDebugUnitTest --tests id.pati.parking.BackendIntegrationTest
```

The attendant needs an assignment for today. A shift left open by an earlier run is closed first.

## Not yet in the app

- Deposit proof photo from the app (the API accepts it; the app does not send one yet).
- Printing receipts.
