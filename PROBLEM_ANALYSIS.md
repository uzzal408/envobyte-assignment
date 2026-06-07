# Problem Analysis — Current Import Implementation

> Phase 1 deliverable (see `IMPLEMENTATION_STEPS.md`). Documents how Monica's
> contact import works **today**, before the redesign. Folds into the final
> submission README (Phase 12).

## TL;DR

Monica already has an import system, and it is exactly the "before" picture the
assignment describes: a queue-able job that nonetheless **processes the entire
file in one pass**, with the dev queue set to `sync` so it ran **inside the HTTP
request**. There is **no CSV support, no batching, no progress API, and no
per-row error reporting surfaced to the user**. The business logic also lives in
an Eloquent model, against the project's own Service/Action convention.

## The current flow: upload → contact created

```
Browser (upload.blade.php)
   │  POST /settings/import/storeImport   (multipart: vcard file + behaviour)
   ▼
SettingsController@storeImport            app/Http/Controllers/SettingsController.php:203
   │  • store file on disk under imports/
   │  • create import_jobs row (type=vcard, filename)
   │  • AddContactFromVCard::dispatch($importJob, $behaviour)
   ▼
AddContactFromVCard (ShouldQueue)         app/Jobs/AddContactFromVCard.php
   │  handle() → $importJob->process($behaviour)
   ▼
ImportJob::process()                      app/Models/Account/ImportJob.php:105
   │  initJob()        → started_at, counters = 0, save
   │  getPhysicalFile()→ read whole file from disk (stream)
   │  getEntries()     → Sabre VCardReader over the file
   │  processEntries() → WHILE loop over EVERY entry in the file:
   │        processSingleEntry()
   │           └─ app(ImportVCard::class)->execute([...one entry...])   app/Services/VCard/ImportVCard.php:136
   │           └─ fileImportJobReport(name, status, reason)  → 1 row per contact
   │  deletePhysicalFile()
   │  endJob()         → ended_at, save
   ▼
import_job_reports rows (one per contact) + report Blade view
   GET /settings/import/report/{importjobid}   SettingsController@report:223
```

## Key files

| Concern | File | Note |
|---|---|---|
| Upload form | `resources/views/settings/imports/upload.blade.php` | field `vcard`, behaviour add/replace |
| Validation | `app/Http/Requests/ImportsRequest.php` | `mimes:vcf,vcard` — **vCard only** |
| Web controller | `app/Http/Controllers/SettingsController.php:194-229` | `upload`, `storeImport`, `report` |
| Job | `app/Jobs/AddContactFromVCard.php` | `ShouldQueue`, but calls one monolithic `process()` |
| Processing logic | `app/Models/Account/ImportJob.php:105-310` | the whole loop lives **in the model** |
| Per-entry parse/create | `app/Services/VCard/ImportVCard.php` | `BaseService`, processes a **single** vCard entry |
| Per-contact result | `app/Models/Account/ImportJobReport.php` | one row per imported/skipped contact |
| Schema | `database/migrations/2017_06_27_134704_create_import_table.php` | see below |
| Routes | `routes/web.php:257-260` | **web only — no `routes/api.php` entries** |

## Current `import_jobs` schema

```
id, account_id, user_id, type (default 'vcard'),
contacts_found, contacts_skipped, contacts_imported,   -- nullable ints
filename,
started_at, ended_at,                                  -- DATE (not datetime!)
failed (bool), failed_reason (mediumText),
timestamps
```
Companion `import_job_reports`: `id, account_id, user_id, import_job_id,
contact_information, skipped (bool), skip_reason, timestamps`.

## Problems & limitations

**Called out by the assignment**
1. **Synchronous in practice.** `QUEUE_CONNECTION=sync` (dev) makes the dispatched
   job run inside the request → request timeouts on files >100 contacts.
2. **No batching.** `processEntries()` is a single `while` loop over the whole
   file in one job → unbounded memory/time; one job owns the entire import.
3. **No progress tracking.** Counters are written, but only viewable via a Blade
   report after the fact; there is **no API**, no progress %, no ETA.
4. **No CSV support.** Validation and parsing are vCard-only (`grep csv` over
   `app/` returns nothing). The assignment's CSV format isn't handled at all.
5. **Failure semantics are coarse.** A `ValidationException` on any entry calls
   `fail()` and aborts the whole import; there's no clean "skip row, continue,
   record error" with a downloadable error report.

**Issues the requirements did *not* mention (worth fixing)**
6. **Domain logic in an Eloquent model.** `ImportJob::process()` and friends
   violate the project's Service/Action rule (logic belongs in `app/Services`).
7. **`started_at` / `ended_at` are `DATE`, not `datetime`** → no time-of-day, so
   duration/ETA can't be computed accurately.
8. **`import_job_reports` writes one row per contact** → table bloat on large
   imports; expensive to query for a progress summary.
9. **Not exposed through the API** — breaks the project's hard "everything via the
   API" rule.
10. **File deletion regardless of outcome** — `deletePhysicalFile()` runs even on
    partial failure, so the source can't be re-read to reconstruct an error CSV.
11. **No idempotency / duplicate detection** — re-uploading the same file just
    runs again.
12. **No recovery path** — a job that dies mid-run leaves an import that never
    completes, with no stuck-detection or retry.

## Why a new table instead of extending this one
The new system needs different columns (`status` enum, `total_rows/processed_rows/
failed_rows`, `errors` JSON, datetime timestamps, `file_hash`). Reusing
`import_jobs` would entangle the new CSV flow with the legacy vCard importer that
still depends on these columns. A dedicated `contact_import_jobs` table keeps the
legacy path working and is easy to remove later (ADR — see `IMPLEMENTATION_PLAN.md`).
