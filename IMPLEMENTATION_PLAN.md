# Reliable Background Import System — Implementation Plan

> Envobyte Senior Backend Assignment · Monica CRM
> Branch: `envobyte-assignment`

This document is the implementation plan for redesigning Monica's contact import
system end-to-end: from file upload to batched background processing, with
progress tracking, error isolation, idempotency/recovery, and observability.

---

## 1. Problem Analysis — what's already in the repo

Monica **already ships an import system, and it is the exact "before" picture the
assignment describes.** The work is to fix the bottlenecks, not build from zero.

| Concern | Current reality | Location |
|---|---|---|
| Trigger | `storeImport()` stores the file then dispatches a job | `app/Http/Controllers/SettingsController.php:203` |
| "Background" job | A job class exists (`implements ShouldQueue`)… | `app/Jobs/AddContactFromVCard.php` |
| …but runs **synchronously** | `QUEUE_CONNECTION=sync` → the job executes *inside* the HTTP request | `.env.dev` |
| Processing | `ImportJob::process()` loops the **entire file in a single job** — no batching, all entries in memory | `app/Models/Account/ImportJob.php:105-245` |
| Domain logic location | Lives **in the Eloquent model**, violating the project's Service/Action rule | same file |
| File format | **vCard only** (Sabre VObject). No CSV parser exists | `app/Services/VCard/ImportVCard.php` |
| Schema | `import_jobs` has `contacts_found/skipped/imported`, `failed`, and `started_at/ended_at` typed as **`date`** (not datetime). No `status` enum, no `total_rows/processed_rows`, no `errors` JSON | `database/migrations/2017_06_27_134704_create_import_table.php` |
| Progress API | None. Only a Blade report view | `routes/web.php:258` |
| Errors | One row per contact in `import_job_reports` | model + migration |
| Redis | **Not present** in `docker-compose.dev.yml`; `CACHE_DRIVER=file` | `docker-compose.dev.yml` |

**Root cause:** the bottleneck is not a missing queue. It is the combination of
the `sync` driver, monolithic single-job processing, and business logic trapped
in an Eloquent model. The fix targets all three.

### Current flow (upload → contact created)
1. User submits the upload form → `SettingsController@storeImport`.
2. File stored to disk under `imports/`; an `import_jobs` row is created.
3. `AddContactFromVCard::dispatch()` — but `sync` means it runs immediately.
4. `ImportJob::process()` reads the whole file, loops every entry, and calls
   `ImportVCard::execute()` per entry, writing an `import_job_reports` row each.
5. The HTTP request only returns once **all** contacts are processed → timeouts
   on large files, no progress, no isolation.

---

## 2. Local environment with Docker

The dev compose file lacks Redis and a dedicated worker, and runs `sync`.

### 2.1 `docker-compose.dev.yml` changes
- Add a **`redis`** service (`redis:alpine`).
- Add a **`worker`** service: same `app` build, command
  `php artisan queue:work redis --queue=imports,default --tries=3`,
  `depends_on: [mysql, redis]`, same volume mounts. This makes "background"
  actually background and lets us stop/scale/observe it.
- Keep `phpmyadmin` (:3000) and `mailhog` (:8025).

### 2.2 `.env.dev` changes
- `QUEUE_CONNECTION=redis` — **the single most important change**; this is what
  makes imports asynchronous.
- `CACHE_DRIVER=redis`, `REDIS_HOST=redis`, `REDIS_PORT=6379`.
- Keep filesystem disk local (where uploaded CSVs land).

### 2.3 Bring-up sequence (documented in README)
1. `docker compose -f docker-compose.dev.yml up -d --build`
2. `docker compose exec app php artisan key:generate` (if needed)
3. `docker compose exec app php artisan migrate`
4. `docker compose exec app php artisan setup:test` (seeds `admin@admin.com` / `admin0`)
5. App on `:8090`, phpMyAdmin `:3000`, Mailhog `:8025`
6. Worker logs: `docker compose logs -f worker`

### 2.4 Tests / lint in-container
- `docker compose exec app vendor/bin/phpunit --testsuite Feature`
- The `testing` connection (`phpunit.xml`) uses `sync`, so batch jobs run inline
  and tests stay deterministic without a running worker.

---

## 3. Data model & migration

**ADR #1 — create a new dedicated table; do not mutate legacy `import_jobs`.**
The legacy vCard flow still uses `import_jobs`/`import_job_reports`. Overloading
it risks the existing importer and does not match the spec's column names. A new
table is cleaner and *easy to remove later* (explicitly rewarded by the brief).

### New migration — `contact_import_jobs`
Matches the assignment schema, with `account_id` for multi-tenancy (CLAUDE.md):

| Column | Type | Purpose |
|---|---|---|
| `id` | PK | |
| `account_id` | int | scoping / sharding |
| `user_id` | int | who initiated |
| `filename` | string | original upload name |
| `total_rows` | uint | count after CSV parse |
| `processed_rows` | uint | incremented by batch jobs |
| `failed_rows` | uint | rows that failed validation |
| `status` | enum | `pending, processing, completed, failed, cancelled` |
| `errors` | json | array of `{row, message}` |
| `started_at` | datetime | processing began |
| `completed_at` | datetime | processing finished |
| `file_hash` | string(64) | sha256 for duplicate detection (ADR #3) |
| `file_size` | uint | duplicate detection / audit |
| `cancelled_at` | datetime null | cancellation audit |
| timestamps | | |

**Added columns justified:** `cancelled` status + `cancelled_at` give the cancel
endpoint a distinct terminal state vs `failed`; `file_hash`/`file_size` power
idempotency. Indexes: `(account_id, status)` and `(status, started_at)` for the
stuck-import monitoring query.

**ADR #2 — keep the raw CSV file; do not store rows in the DB.** Store the upload
on the configured disk (local in dev, S3-ready in prod). The error-CSV endpoint
re-reads the original file by row number rather than persisting every row.
Trade-off: re-parse on demand vs storage cost; error CSV is rare and
post-completion, so re-parse is fine.

**Model** `app/Models/Account/ContactImportJob.php`: extends `ModelBindingHasher`
(Hashids in URLs), `account()`/`user()` relations, a `progress_pct` accessor.
**No domain logic in the model** — fixing the existing violation.

---

## 4. Background processing pipeline (batching)

**ADR #4 — Laravel `Bus::batch()` of per-chunk jobs.** Each chunk handles ~50
rows: small enough to bound memory, large enough to avoid per-job overhead.

Flow (HTTP side stays `<500ms`):
1. **Service** `app/Services/Contact/ImportContacts/InitiateImport.php`
   (BaseService — `rules()` + `execute()`):
   - validate the upload (mime, size limit)
   - store the file; **stream-count rows** for `total_rows` (no full load)
   - compute `file_hash`; run duplicate check (ADR #3)
   - create `contact_import_jobs` row, status `pending`
   - return the model; controller responds `201` immediately.
2. **Dispatcher**: build `Bus::batch([...ProcessImportChunk...])` on the
   `imports` queue.
   - `->then()` → status `completed`
   - `->finally()` → set `completed_at`, recompute final status
   - `->allowFailures(false)` → one fatal chunk failure stops the rest
     (spec: remaining batches must not start on failure)
   - store the batch id on the job row.
3. **`ProcessImportChunk` job** (per batch):
   - re-fetch the job; **if `status === cancelled` → return early**
     (cooperative cancellation)
   - `fseek` to its row offset, parse its 50 rows
   - per-row validation → on failure collect `{row, message}`, increment
     `failed_rows`, **continue** (error isolation)
   - valid rows → `CreateContactFromRow` service (reuse existing contact services)
   - atomic DB `increment()` on `processed_rows` to avoid lost updates across
     parallel chunks; append errors.

**CSV parsing:** a `CsvContactParser` helper (header map name/email/phone),
streamed with generators — never loads the whole file. A vCard adapter can sit
behind the same interface later (out of scope unless time permits).

---

## 5. Progress Tracking API

Per CLAUDE.md everything goes through the API — add to `routes/api.php` +
`app/Http/Controllers/Api/Contact/ApiImportController.php`. Thin controllers,
service delegation, API Resources for output.

| Method | Path | Notes |
|---|---|---|
| POST | `/api/import` | upload → validate → create → dispatch → `201` pending payload |
| GET | `/api/import` | paginated list with `progress_pct` |
| GET | `/api/import/{id}` | progress %, processed, failed, `estimated_remaining_sec`, first-N errors |
| POST | `/api/import/{id}/cancel` | set `status = cancelled` |
| GET | `/api/import/{id}/errors` | paginated per-row errors (JSON) |
| GET | `/api/import/{id}/errors.csv` | streamed error CSV: original columns + `error` |

**ADR #5 — progress is read straight from `contact_import_jobs`; never count
contact rows.** Guarantees the `<50ms` requirement. `estimated_remaining_sec` =
elapsed × (remaining / processed). All queries scoped `where('account_id', …)`.

---

## 6. Error handling & isolation
- Per-row try/catch in the chunk job → skip + record, never abort the batch.
- Final status `completed` even with some `failed_rows`; only `failed` if a chunk
  threw fatally or **all** rows failed.
- **Error CSV reconstruction:** stream the stored original file; for each row in
  `errors`, emit original columns + the message. (This is why we keep the file —
  ADR #2.)

---

## 7. Concurrency, idempotency & recovery
- **Duplicate upload:** `file_hash` + `account_id`. If an identical recent import
  exists → return the existing job `200` instead of re-running (behind a flag;
  ADR #3 weighs hash-content vs filename+size+date).
- **Lost updates:** DB-level `increment()` (or short Redis lock) for
  `processed_rows`/`failed_rows`, since chunks run in parallel.
- **Crashed / stuck batch:** artisan `imports:recover` (scheduled in
  `app/Console/Kernel.php`) finds `processing` jobs with no progress for >N min
  and re-dispatches or fails them. Chunk jobs use `tries`/`backoff`.
- **Cancellation:** cooperative — each chunk checks `status = cancelled` first and
  returns. An in-flight chunk finishes its current 50 rows (graceful); nothing new
  starts.

---

## 8. Observability & alerting
- **Metrics:** imports started/completed/failed, avg processing time per row,
  batch failure rate — structured log events; counts exposed via query.
- **Stuck-import query:**
  `status = 'processing' AND started_at < NOW() - INTERVAL 30 MINUTE`.
- **Alerting rule:** scheduled command computing
  `SUM(failed_rows) / SUM(total_rows)` over the last hour; if `> 20%`, log
  critical / notify. Wired as an artisan command in `Kernel.php`.

---

## 9. Tests (required — submission is "incomplete" without them)
PHPUnit, runnable via `vendor/bin/phpunit` (single command documented in README).
Covering the spec's list:
- Import initiation + status tracking (POST returns 201/pending fast)
- Batch processing with per-row error isolation (good + bad rows in one file)
- Cancellation (cancelled chunk returns early)
- Error CSV generation (content matches failed rows)
- Cache/invalidation behaviour where applicable
- Use the `Fixtures/` + Guzzle-mock pattern; never real outbound HTTP. Tests run
  with `sync` queue so batches execute inline.

---

## 10. Submission deliverables
- `README.md`: Docker setup (§2), approach, assumptions, test instructions.
- **ADRs** (≥2 required — this plan yields 5):
  1. New table vs extend legacy `import_jobs`
  2. Keep raw file vs store rows in DB
  3. Duplicate detection strategy
  4. `Bus::batch` + batch size selection
  5. DB vs Redis for progress tracking
- Branch `envobyte-assignment` (already set). Public repo with *just the changes
  on top of Monica* — do not fork Monica into the repo.

---

## 11. Architecture Decision Records (summary)

| # | Decision | Chosen | Why |
|---|---|---|---|
| 1 | Schema | New `contact_import_jobs` table | Matches spec, keeps legacy vCard importer intact, easy to remove |
| 2 | File storage | Keep raw file on disk (S3-ready) | Enables error-CSV reconstruction without row bloat |
| 3 | Idempotency | `file_hash` + `account_id`, flag-gated | Detects duplicate uploads cheaply; flag preserves "let both run" option |
| 4 | Batching | `Bus::batch` of 50-row chunks | Bounds memory, supports progress/cancel/failure semantics |
| 5 | Progress source | Read `contact_import_jobs` only | Meets `<50ms`; never scans contact rows |

---

## 12. Suggested build order
1. Docker (Redis + worker + env) → prove async end-to-end.
2. Migration + model.
3. Initiate service + POST endpoint (201 fast).
4. Batch + chunk job (batching + isolation).
5. Progress / list / detail endpoints.
6. Cancel + error CSV.
7. Recovery command + monitoring queries.
8. Tests + README / ADRs.
