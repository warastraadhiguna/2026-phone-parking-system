# Implementation Report — Phase 5

```text
PHASE:
Phase 5 — Android Offline Sync

STATUS:
DONE
```

Date: 2026-09-25. Continued without waiting for approval, as instructed by the owner.

## IMPLEMENTED

- **Batch sync endpoint** `POST /api/v1/sync/transactions`:
  - 1–50 items, processed in order, each in its own DB transaction;
  - a per-item result `CREATED` / `EXISTING` / `REJECTED` with a stable error code and
    `retryable`, a summary, and the cash balance;
  - reuses exactly the rules and idempotency of the single CASH endpoint (the shared
    `CashTransactionPayload`), so a whole batch can be resent after a lost response.
- **Android app** (`android/`, package `id.pati.parking`), offline-first:
  - login with device registration (PENDING_APPROVAL → approved in the Control Center);
    tokens encrypted with an Android Keystore AES-GCM key; no password stored; backups disabled;
  - bootstrap cache (assignment, location, tariff schedule, settings, `valid_until`);
  - start shift online, or offline only when the 6 ADR-0008 preconditions hold (`OfflineShiftRules`);
  - CASH transaction priced from the cached tariff schedule (`TariffCalculator`), with a
    per-device `sync_sequence`, GPS fix, accuracy and mock-location flag;
  - end shift; home screen with the shift total, cash held, and pending/failed sync counters.
- **Sync engine** (`SyncEngine` + Room `sync_queue` + `SyncWorker`/WorkManager):
  - order: shift starts → transactions (batches of 50) → shift ends only when all the shift's
    transactions are synced;
  - payloads are resent unchanged; a network error stops the run and keeps everything PENDING;
  - retryable rejections stay PENDING, permanent ones become FAILED and are kept on the device;
  - items interrupted mid-sync (process killed) are reset; `401` triggers one token refresh;
  - WorkManager unique work, network constraint, exponential backoff from 30 s.
- **Backend URL** per build type from `gradle.properties` (`BuildConfig.API_BASE_URL`), not
  hardcoded. Cleartext is allowed only in debug builds.
- Dev PHP-FPM pool raised to `max_children = 20` (dev image only). With 5 workers, concurrent
  app and emulator traffic queued requests.

## FILES CHANGED

Backend:
- new: `app/Http/Api/V1/Sync/SyncController.php`, `app/Http/Api/V1/ParkingTransaction/CashTransactionPayload.php`
  (rules shared by the single and batch endpoints), `tests/Feature/Transactions/SyncTest.php`
- changed: `StoreTransactionRequest.php` (uses the shared payload), `routes/api.php`

Android (all new):
- build: `settings.gradle.kts`, `build.gradle.kts`, `gradle.properties`, `gradle/libs.versions.toml`,
  the Gradle wrapper, `app/build.gradle.kts`, `app/schemas/…/1.json` (Room schema export)
- `app/src/main`: `AndroidManifest.xml`, `res/xml/{network_security_config, data_extraction_rules}.xml`;
  `app/src/debug/res/xml/network_security_config.xml`
- `app/src/main/java/id/pati/parking/`:
  - `PatiParkingApp.kt`
  - `data/remote/{Dto, ApiClient}.kt`, `data/prefs/{SecureTokenStore, AppPrefs}.kt`
  - `data/local/{Entities, Daos, AppDatabase}.kt`, `data/ParkingRepository.kt`
  - `domain/{TariffCalculator, OfflineShiftRules}.kt`
  - `sync/{SyncEngine, RoomSyncStore, SyncWorker}.kt`
  - `location/LocationReader.kt`, `ui/{MainViewModel, MainActivity}.kt`
- `app/src/test/java/id/pati/parking/`: `TariffCalculatorTest`, `OfflineShiftRulesTest`,
  `SyncEngineTest`, `DtoParsingTest`, `BackendIntegrationTest`
- `android/README.md`

Other:
- `docker/php/Dockerfile` (dev FPM pool size)
- Docs:
  - new: `docs/api/sync.md`, this report
  - updated: `docs/api/README.md`, `docs/architecture/overview.md`,
    `docs/decisions/0008-offline-operation-policy.md` (Phase 5 notes)

## DATABASE CHANGES

- Backend: none. The batch endpoint uses the Phase 4 tables and constraints.
- Android Room (version 1, schema exported):
  - `local_shifts`, `local_transactions`
  - `sync_queue` (kind, entity UUID, shift UUID, payload, status PENDING/SYNCING/SYNCED/FAILED,
    attempts, last error, server reference)
  - `local_config` (bootstrap cache), `device_counters` (`sync_sequence`)

## API

| Endpoint | Notes |
|---|---|
| `POST /api/v1/sync/transactions` | 200 with per-item results; 422 when the batch is empty or has more than 50 items |

Contract: [docs/api/sync.md](../api/sync.md). The app also uses the Phase 1–4 endpoints (auth,
bootstrap, shifts, cash balance).

## TESTS

- Backend: `docker compose exec app composer check` → Pint PASS, **343 passed (1523 assertions)**,
  Larastan `[OK] No errors`. The frontend build passes.
- `SyncTest` (4):
  - a batch of offline transactions is stored in order with per-item results (Scenario C);
  - resending a whole batch creates nothing new (Scenario E);
  - bad items are rejected without blocking good ones (validation, conflict, unknown shift
    `retryable: true`);
  - the batch size is limited.
- Android: `gradlew testDebugUnitTest assembleDebug` → BUILD SUCCESSFUL, 18 tests pass and 1 is
  skipped (the opt-in integration test). The debug APK is about 13.9 MB.
  - `SyncEngineTest` (8): Scenario C order; offline keeps everything; never a transaction before its
    shift; Scenario E replay; permanent failures kept; shift end last; interrupted reset;
    batches of 50.
  - `OfflineShiftRulesTest` (3), `TariffCalculatorTest` (4), `DtoParsingTest` (3).
- **End to end against the real backend** (`BackendIntegrationTest` with the `PATI_E2E_*`
  variables): an offline shift, 3 cash transactions and the shift end synced with the real
  `ApiClient` + `SyncEngine`; a full resend created nothing. PostgreSQL shows the shift CLOSED,
  `offline_created`, with 3 offline transactions totalling Rp6.000, and `cash:verify-balances`
  reports that everything matches.
- **Mutation check:** letting the engine send transactions before their shift start makes the
  ordering tests fail.

Issues found during the phase:
1. A doc path containing `/*` inside a KDoc comment opened a nested comment and commented out the
   rest of `Dto.kt`. The text was changed.
2. Editing sources with PowerShell `Get-Content`/`Set-Content` double-encoded UTF-8 and added
   BOMs. This was repaired, and files are no longer edited through PowerShell.
3. The emulator (software GPU) made the host so slow that logins took 27–41 s and the launcher
   stopped responding. This was not a backend fault: logins took about 3 s without the emulator.

## STATIC ANALYSIS

- Pint PASS (255 files), Larastan level 8 OK, architecture tests pass, TypeScript build passes.
- Android: compiles with no errors under Kotlin 2.4 / AGP 9.3.

## SECURITY NOTES

- Tokens are encrypted with a Keystore key that never leaves the device's secure hardware or
  software keystore. If the key is invalidated, the attendant logs in again. Passwords are never stored.
- No backup or device transfer of the database or preferences (unsynced money records and tokens
  stay on the device).
- The release build refuses cleartext HTTP. The release base URL is a placeholder
  (`.invalid`) until the production domain is known.
- The server re-validates everything. The app's offline checks are a convenience and are never
  trusted.

## ARCHITECTURE DECISIONS / ADR

- ADR-0008 extended with Phase 5 implementation notes. No new ADR.

## KNOWN LIMITATIONS

- The UI flow was not tested end to end on an emulator, because the emulator was too unstable
  on this machine. Sync was verified with the real client code against the real backend on the
  JVM instead. The app should be checked on a real device before the pilot.
- Shift starts and ends are sent one by one (at most 20 each per run). This is enough for one
  device.
- The app UI is minimal (one screen, Indonesian). There is no receipt printing yet, and no screen
  that lists FAILED items in detail (only a counter).
- QRIS in the app comes in Phase 6.

## DEVIATIONS FROM APPROVED PLAN

- None in scope. The FPM pool change affects only the dev image.

## BLOCKERS

None. Owner items: the production API domain (release base URL), and the device model(s) for the
pilot, to test on.

## NEXT RECOMMENDED PHASE

Phase 6 — QRIS payment (PaymentGatewayInterface, Midtrans sandbox, webhook, refunds as records).
