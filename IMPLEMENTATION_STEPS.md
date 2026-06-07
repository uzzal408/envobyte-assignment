# Implementation Steps — Reliable Background Import System

> Companion to `IMPLEMENTATION_PLAN.md`. This is the **ordered, build-and-verify**
> breakdown. Each step is independently testable; do them in order so every step
> leaves the app working. Branch: `envobyte-assignment`.

Legend: 🎯 goal · 📁 files · ✅ verify · 🏷 grade area (from the brief)

---

## Phase 0 — Environment (DONE)
🎯 Async-capable dev stack.
- `docker-compose.yml`: `redis` + `worker` services, `QUEUE_CONNECTION=redis`.
- Dockerfile/entrypoint fixes (PHP 8.2, Node 20, storage perms).
✅ `docker compose up -d` → app 302 on :8090, worker consuming Redis queue.

---

## Phase 1 — Problem analysis writeup (DONE)
🎯 Document the current synchronous flow (feeds the README). 🏷 *Understanding (10%)*
- 📁 `PROBLEM_ANALYSIS.md` — flow diagram (upload → contact created), key-files table, current `import_jobs` schema, and 12 documented problems (incl. issues the brief didn't mention).
- Confirmed by reading the code: `SettingsController@storeImport` → `AddContactFromVCard` job → `ImportJob::process()` loops the whole file; `QUEUE_CONNECTION=sync` ran it inline; logic lives in the model; **vCard-only** (no CSV anywhere); web-only (no API).
✅ Writeup complete; folds into the final README in Phase 12.

---

## Phase 2 — Data model & migration (DONE)
🎯 The `contact_import_jobs` table + model. 🏷 *Queue/batch design (25%)*

**Step 2.1 — Migration** ✓
- 📁 `database/migrations/2026_06_06_120000_create_contact_import_jobs_table.php`
- Columns: `id, account_id, user_id, filename, total_rows, processed_rows, failed_rows, status (string, default pending), errors (json), file_hash, file_size, batch_id, started_at, completed_at, cancelled_at, timestamps`. FKs to accounts/users (cascade).
- Indexes: `(account_id, status)`, `(status, started_at)`, `(account_id, file_hash)`.
- `status` is a `string` (matches the repo's `export_jobs` convention) with model constants, not a DB enum.
✅ migrate clean; rollback drops only this table; re-migrate clean.

**Step 2.2 — Model** ✓
- 📁 `app/Models/Account/ContactImportJob.php` — extends `ModelBindingHasher`; `account()`/`user()` relations; `$casts` (errors→array, datetimes, int counters); `getProgressPctAttribute()`; `STATUS_*` constants. No domain logic.
- 📁 `app/Models/Account/Account.php` — added `contactImportJobs()` hasMany.
✅ tinker: create/read scoped by `account_id`; errors JSON cast; `progress_pct=64` for 320/500; hashid resolves; datetimes → Carbon. PHPStan level 5: no errors.

---

## Phase 3 — CSV parsing helper (DONE)
🎯 Stream rows without loading the whole file. 🏷 *Queue/batch design (25%)*
- 📁 `app/Services/Contact/ImportContacts/CsvContactParser.php`
  - `headers(path)` — original header row (BOM stripped).
  - `countRows(path)` — streamed count of non-blank data rows for `total_rows`.
  - `rows(path, offset, limit)` — generator yielding `[rowNumber, assoc]` (1-based data rows; keys = normalised headers; values trimmed; short/long rows aligned to header width).
  - `FIELDS = [name, email, phone]`, `REQUIRED_HEADERS = [name]`. Uses `Safe\fopen/fclose/preg_replace`; raw `fgetcsv` with `@phpstan-ignore-line` (matches `app/Console/Commands/ImportCSV.php`).
- 📁 `tests/Unit/Services/Contact/ImportContacts/CsvContactParserTest.php` + fixture `tests/Fixtures/Services/Contact/ImportContacts/contacts.csv`.
✅ 7 tests / 20 assertions pass (count excl. header+blanks, offset/limit slice + row numbers, short/long row alignment, BOM strip). PHPStan level 5: no errors.

> Note: `tests/` is not in the compose volume mounts, so new test files must be
> `docker compose cp`'d into the container to run (or add a `./tests` mount).

---

## Phase 4 — Initiate import (HTTP < 500 ms) (DONE)
🎯 Upload → validate → store → create record. 🏷 *API (15%) + idempotency (20%)*

**Step 4.1 — Service** ✓
- 📁 `app/Services/Contact/ImportContacts/InitiateContactImport.php` (extends `BaseService`)
  - `rules()`: `file` required|file|mimes:csv,txt|max:`monica.max_upload_size`; `account_id`/`user_id` required.
  - `execute()`: sha256 `file_hash` + `file_size`, flag-gated duplicate check (returns existing → `wasRecentlyCreated=false`), store on default disk, stream `countRows` for `total_rows`, create row `pending` (counters explicit 0). Stores both `filename` (original) and `storage_path` (disk path).
- 📁 `config/monica.php` — `contact_import_detect_duplicates` flag (ADR #3).
- 📁 migration — added `storage_path` column (original name for display vs disk path for processing).

**Step 4.2 — API endpoint** ✓
- 📁 `routes/api.php` → `POST /import` (named `api.import.store`, inside `auth:api`).
- 📁 `app/Http/Controllers/Api/Contact/ApiImportController@store` — thin; `201` for new, `200` for duplicate (via `wasRecentlyCreated`).
- 📁 `app/Http/Resources/Contact/ImportJob/ImportJob.php` — `{data:{id,object,filename,total_rows,processed_rows,failed_rows,status,progress_pct,account,created_at,updated_at}}`.
✅ POST CSV → **201 in 96 ms**, `pending`, `total_rows=4`, counters 0; identical re-POST → **200 same id**; no file → **422**. Stored file on disk, hash/size set. PHPStan level 5: no errors.

> **Dev-workflow gotchas (apply to all later phases when verifying):**
> - The image sets `opcache.validate_timestamps=0`, so **edited code in mounted volumes isn't served until `docker compose restart app worker`** (CLI artisan sees changes; Apache does not).
> - `config/` and `tests/` are **not** mounted — `docker compose cp` them in (or restart after a rebuild).
> - API needs a Passport token: `createToken(...)->accessToken`; `migrate:fresh`/`setup:test` drop the personal-access client, so re-run `php artisan passport:client --personal --no-interaction` after seeding.

---

## Phase 5 — Batched background processing (DONE)
🎯 Move work to the queue in 50-row batches. 🏷 *Queue/batch design (25%) + error isolation (15%)*

**Step 5.1 — Chunk job** ✓
- 📁 `app/Jobs/Contact/ProcessContactImportChunk.php` (`ShouldQueue` + `Batchable`, `$tries=3`, on `imports` queue)
  - returns early if `batch()->cancelled()` or job `status === cancelled`.
  - streams its slice via `CsvContactParser::rows(offset, limit)`; per-row try/catch: valid → `CreateContactFromRow`; `ValidationException`/`Throwable` → `{row, message}` recorded, row skipped.
  - `record()` folds counters + errors into the job under a `lockForUpdate` transaction (no lost updates across parallel chunks).

**Step 5.2 — Row → contact service** ✓
- 📁 `app/Services/Contact/ImportContacts/CreateContactFromRow.php` (`BaseService`) — `name` required, `email` validated as `email`; splits name → first/last; reuses `CreateContact` (handles email) and mirrors it to add a phone `ContactField`.

**Step 5.3 — Dispatch batch** ✓
- `InitiateContactImport::dispatchBatch()`: `Bus::batch([...chunks of 50...])`, `->allowFailures(false)`, `->onQueue('imports')`; sets `processing` + `started_at` + `batch_id`.
  - `->then` → `completed` (unless ALL rows failed → `failed`); `->catch` → `failed`; `->finally` → `completed_at`. Empty file → `completed` immediately. `BATCH_SIZE=50` (ADR #4).
✅ 5-row file (2 bad): `completed`, processed=5 failed=2, errors at rows 2 (invalid email) & 3 (missing name), 3 contacts created (phones where present). 120-row file: **3 chunks**, processed=120 failed=0, `completed` — counters summed correctly across parallel chunks. PHPStan level 5: no errors.

> Note: dispatch is synchronous in the request, so the POST response shows
> `processing` (not `pending`) — accurate, since the batch is already queued.

---

## Phase 6 — Progress & detail API (DONE)
🎯 Fast status reads (<50 ms), never scan contacts. 🏷 *API (15%)*
- 📁 `ApiImportController@index` — `GET /import`, paginated (`getLimitPerPage`), `ImportJob` collection with `progress_pct` + `meta`.
- 📁 `ApiImportController@show` — `GET /import/{id}`, raw-id + `account_id` scoped `firstOrFail`; returns `ImportJobDetail`.
- 📁 `app/Http/Resources/Contact/ImportJob/ImportJobDetail.php` — base fields + first-10 `errors`, `estimated_remaining_sec`, `started_at`, `completed_at`, `url`.
- 📁 `ContactImportJob::getEstimatedRemainingSecAttribute()` — `elapsed × remaining / processed`, null unless processing (derived from own fields, no query).
- 📁 `routes/api.php` — `api.import` (index), `api.import.show`.
✅ List paginated with `meta`; detail shows errors + ETA; **600-row run polled live** → `progress_pct` 0→58 climbing, ETA decreasing, response ~20–28 ms (<50 ms; only rose under concurrent-import DB contention, still a single-row read); 404 for missing/other-account id. PHPStan level 5: no errors.

---

## Phase 7 — Cancellation (DONE)
🎯 Stop remaining batches gracefully. 🏷 *Concurrency (20%)*
- 📁 `app/Services/Contact/ImportContacts/CancelContactImport.php` — only cancels `pending`/`processing`; cancels the Bus batch (`Bus::findBatch($batchId)->cancel()`, queued chunks skipped) **and** sets `status=cancelled` + `cancelled_at` (each chunk re-checks). No-op on terminal states.
- 📁 `ApiImportController@cancel` + `POST /import/{id}/cancel` (`api.import.cancel`); returns `ImportJobDetail`, 404 if missing.
- Chunk job's Phase-5 cancel checks make an in-flight chunk finish its slice; nothing new starts.
✅ Cancel mid-run (800 rows): `status=cancelled`, `processed_rows` froze at **50/800** (one in-flight chunk finished, 15 skipped), and exactly **50 contacts** created. Cancel completed import → no-op (`completed`, 200). Cancel missing id → 404. PHPStan level 5: no errors.

---

## Phase 8 — Error reporting (DONE)
🎯 Per-row errors + downloadable CSV. 🏷 *Error handling (15%)*
- 📁 `ApiImportController@errors` — `GET /import/{id}/errors` (`api.import.errors`), paginated JSON `{data:[{row,message}], meta}` from the `errors` column; `per_page` falls back to model default when no `?limit` (avoids paginate(0)).
- 📁 `ApiImportController@errorsCsv` — `GET /import/{id}/errors.csv` (`api.import.errors.csv`), `streamDownload` (Safe\f*); re-reads the **stored file** and, for each row in `errors`, emits the original columns + an `error` column (this is why we keep the file — ADR #2).
✅ errors JSON: `{rows 2,3}` with `meta{per_page:15,total:2}`; `?limit=1&page=2` → second error only, `per_page:1`. errors.csv exactly reconstructs the 2 failed rows (`"Jane Smith",not-an-email,555-0002,...` / `,noname@example.com,555-0003,...`); clean import → header only; missing id → 404. PHPStan level 5: no errors.

---

## Phase 9 — Concurrency, idempotency & recovery (DONE)
🎯 Survive crashes and duplicates. 🏷 *Concurrency (20%)*
- **Lost updates:** chunk `record()` folds counters + errors under a `lockForUpdate` transaction (Phase 5) — verified by the 120-row run summing exactly across parallel chunks.
- **Duplicate uploads:** flag-gated `file_hash`+`account_id` check (Phase 4) — verified identical re-POST → `200` same id (ADR #3: content hash vs filename+size+date).
- **Stuck/crashed batch:** 📁 `app/Console/Commands/RecoverStuckImports.php` (`imports:recover --minutes=15`) — finds `processing` imports whose `updated_at` (each chunk touches it) hasn't moved past the threshold, distinguishing *hung* from *slow*. Three cases: batch cancelled → `cancelled`; batch finished (lost callback) → reconcile from counters; batch gone/hung → cancel + `failed`. **Does not re-dispatch** (contact creation isn't idempotent → would duplicate); user re-uploads, dup-detection short-circuits. Scheduled hourly in `Console/Kernel.php`. Chunk jobs: `$tries=3`, `$backoff=5`.
✅ Fabricated scenarios: stuck/no-batch → `failed`; finished-batch → reconciled to `completed`; fresh/progressing → "Found 0 stuck" (untouched). PHPStan level 5: no errors.

---

## Phase 10 — Observability & alerting (DONE)
🎯 Metrics + stuck detection + failure alert. 🏷 *Production awareness (15%)*
- **Structured logs** (`InitiateContactImport`): `contact_import.started` (id, account, total, batch), `contact_import.completed` (status, counts, `duration_ms`, `ms_per_row`, `failure_rate`), `contact_import.failed` (reason). A log aggregator turns these into the imports-started/completed/failed + avg-ms-per-row + failure-rate metrics.
- **Stuck query**: 📁 `ContactImportJob::scopeStuck($minutes=30)` → `status='processing' AND started_at < now()-Nm` (time-based monitoring, distinct from the progress-based recovery in Phase 9).
- **Alert**: 📁 `app/Console/Commands/CheckImportFailureRate.php` (`imports:check-failure-rate --minutes=60 --threshold=20`) — `SUM(failed_rows)/SUM(total_rows)` over the window; if exceeded → `Log::critical('contact_import.failure_rate_high', …)` (route `critical` channel to Slack/PagerDuty) + non-zero exit. Scheduled hourly in `Kernel.php`.
✅ Logs emitted with `ms_per_row=50.0`, `failure_rate=0.1538`; failure-rate cmd → OK at default, ALERT + `CRITICAL` log at `--threshold=0.1`; `stuck(30)` count=1 for a 40-min-old processing row. PHPStan level 5: no errors.

---

## Phase 11 — Tests (DONE)
🎯 Cover the brief's list, runnable via one command. 🏷 *all areas*
- 📁 `tests/Api/ApiImportTest.php` (7): initiation + status tracking, per-row error isolation (`completed` w/ failed_rows + error rows + only-valid-row-imported), duplicate detection (200 same id), upload validation (422), cancellation, error-CSV reconstruction, account-scoped listing.
- 📁 `tests/Unit/Services/Contact/ImportContacts/CsvContactParserTest.php` (7, Phase 3).
- 📁 `run-tests.sh` — single command: ensures `monica_test` exists, migrates it, runs PHPUnit with the testing env. (Needed because the container injects `.env` as OS env vars that shadow `phpunit.xml`; also added `force="true"` to the test env entries for clean/CI runs.)
- Runs under `sync` queue → batch executes inline; surfaced & fixed a real bug: `dispatchBatch` set `processing` *after* `dispatch()`, clobbering the inline-`completed` status — now set before dispatch, only `batch_id` after (verified async still completes).
- Cache/invalidation: list endpoint isn't cached (CACHE_DRIVER=array in tests), so N/A — covered account-scoping isolation instead.
✅ `./run-tests.sh --filter 'ApiImportTest|CsvContactParserTest'` → **14 tests, 55 assertions, OK**. Async live import still completes after the reorder.

---

## Phase 12 — Docs & ADRs (DONE)
🎯 Submission deliverables. 🏷 *Understanding + production awareness*
- 📁 `README.md` — submission section prepended (above the original Monica README): overview, file map, Docker setup, API table + curl, how-it-works, `./run-tests.sh`, assumptions, **5 ADRs**, trade-offs/production notes (10×, one-queue-vs-per-import, rollback, debugging stuck, questioning S3-vs-DB), and "reachable via API / easy to remove".
- Companion docs cross-linked: `PROBLEM_ANALYSIS.md`, `IMPLEMENTATION_PLAN.md`, `IMPLEMENTATION_STEPS.md`.
- Everything exposed under `/api/import`; new code isolated (own table/routes/`ImportContacts` namespace) + flag-gated → easy to remove.
✅ All deliverables present; submission section leads the README.

---

## Dependency order (quick view)
```
0 ✓ → 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12
                   └─ 4 unblocks 6/7/8 (API surface)
                   └─ 5 depends on 3 (parser) + 2 (model)
```
Smallest first shippable slice: **Phases 2 → 4 → 5** (upload → batched import working), then layer 6–10.
