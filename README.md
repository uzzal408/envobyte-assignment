<!-- ════════════════════════════════════════════════════════════════════════
     ENVOBYTE SENIOR BACKEND ASSIGNMENT — submission section.
     The original Monica README follows below the divider.
     ════════════════════════════════════════════════════════════════════════ -->

# Reliable Background Import System (Envobyte Assignment)

Redesign of Monica's contact import: from a synchronous, vCard-only, in-request
import into a **batched background pipeline** (CSV **and** vCard) with progress
tracking, per-row error isolation, cancellation, idempotency, crash recovery, and
observability — exposed through the API and driven by a live-progress UI at
`/settings/import/upload`. Formats sit behind a small driver interface
(`ContactImportDriver`), so adding another is a single class.

Branch: `envobyte-assignment`. Companion docs:
[`PROBLEM_ANALYSIS.md`](PROBLEM_ANALYSIS.md) (the "before" picture),
[`IMPLEMENTATION_PLAN.md`](IMPLEMENTATION_PLAN.md) (architecture + ADR rationale),
[`IMPLEMENTATION_STEPS.md`](IMPLEMENTATION_STEPS.md) (per-phase build & verification log).

## What changed (file map)

| Area | File |
|---|---|
| Migration | `database/migrations/2026_06_06_120000_create_contact_import_jobs_table.php` |
| Model | `app/Models/Account/ContactImportJob.php` (`progress_pct`, `estimated_remaining_sec`, `scopeStuck`) |
| Format drivers | `ContactImportDriver` (interface), `CsvImportDriver`, `VcardImportDriver`, `ContactImportDrivers` (resolver), `CsvContactParser` (`app/Services/Contact/ImportContacts/`) |
| Services | `InitiateContactImport`, `CreateContactFromRow`, `CancelContactImport` (`app/Services/Contact/ImportContacts/`) |
| UI | `resources/views/settings/imports/upload.blade.php` (single form, calls the API via the session cookie, live progress bar) |
| Batch job | `app/Jobs/Contact/ProcessContactImportChunk.php` |
| API | `app/Http/Controllers/Api/Contact/ApiImportController.php`, `routes/api.php`, `app/Http/Resources/Contact/ImportJob/*` |
| Commands | `app/Console/Commands/RecoverStuckImports.php`, `CheckImportFailureRate.php` (scheduled in `Console/Kernel.php`) |
| Config flag | `config/monica.php` → `contact_import_detect_duplicates` |
| Tests | `tests/Api/ApiImportTest.php`, `tests/Unit/Services/Contact/ImportContacts/CsvContactParserTest.php` |

## Local setup (Docker)

```bash
docker compose up -d --build          # app, worker, redis, mysql, phpmyadmin, mailhog
docker compose exec app php artisan setup:test    # seed: admin@admin.com / admin0
```

- App → http://localhost:8090 · phpMyAdmin → :3000 · Mailhog → :8025
- The `worker` service runs `queue:work redis --queue=imports,default`; `QUEUE_CONNECTION=redis` makes imports asynchronous. Watch it with `docker compose logs -f worker`.
- Dev-environment notes (PHP 8.2 / MySQL 8.0 / Node 20 pins, the `nc`/storage-perm fixes) are documented inline in `scripts/docker/Dockerfile`, `entrypoint.sh`, and `docker-compose.yml`.

## API

All endpoints are under `auth:api` (Passport). Get a token in-container:
`User::find(..)->createToken('cli')->accessToken`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/import` | upload a CSV or vCard → `201` (new) / `200` (duplicate); creates the job and dispatches the batch |
| GET | `/api/import` | paginated list with `progress_pct` |
| GET | `/api/import/{id}` | detail: progress %, counts, `estimated_remaining_sec`, first errors |
| POST | `/api/import/{id}/cancel` | cancel a running import |
| GET | `/api/import/{id}/errors` | paginated per-row errors `{row, message}` |
| GET | `/api/import/{id}/errors.csv` | original rows + an `error` column, streamed |

```bash
curl -F file=@contacts.csv -H "Authorization: Bearer $TOKEN" http://localhost:8090/api/import
```

## How it works

1. **Upload (`<500ms`)** — `InitiateContactImport` validates, stores the file, streams it once to count rows, hashes it for idempotency, creates a `pending` row, then dispatches a `Bus::batch` of 50-row chunk jobs and flips to `processing`.
2. **Processing** — each `ProcessContactImportChunk` streams its own slice, creates contacts row-by-row, isolates failures (`{row, message}` recorded, row skipped), and folds counters/errors back under a row-lock transaction. The batch's `then`/`catch`/`finally` settle the final status.
3. **Tracking** — the progress API reads only the `contact_import_jobs` row (`<50ms`), never the contact rows.
4. **Resilience** — `imports:recover` (hourly) reconciles/fails crashed imports; `imports:check-failure-rate` (hourly) raises a critical alert when the failure rate exceeds the threshold.

## Running the tests

```bash
# Single command (the spec's example) — works once the test DB exists:
docker compose exec app php artisan test --filter Import   # the import tests (15)
docker compose exec app php artisan test                   # full suite

# Cold checkout / zero setup (also creates + migrate:fresh's monica_test):
./run-tests.sh
./run-tests.sh --filter Import
```

Both run the same suite on the `sync` queue, so the dispatched batch executes
inline (an import is `completed` by the time the POST returns). `php artisan test`
is wired up via `tests/bootstrap.php`, which pins the testing values (`sync`
queue, `array` cache/session, `testing` DB connection) into `$_SERVER` **before**
the app boots. This is needed because the dev container injects `.env` as real OS
env vars that land in `$_SERVER`, and Laravel's `env()` reads `$_SERVER` before
`$_ENV` — so they would otherwise shadow `phpunit.xml`'s `<env force>` and the
queue would stay `redis` (batch never runs inline). `run-tests.sh` achieves the
same via explicit `-e` flags and additionally provisions/migrates `monica_test`,
so reach for it on a fresh checkout; afterwards `php artisan test` runs directly.

**Cache/invalidation behaviour:** N/A by design. Progress is read straight from
the `contact_import_jobs` row — a single indexed query (ADR 5), never a count of
contact rows — so there is no cache layer to keep in sync or invalidate. The
endpoint is fast because of what it *doesn't* read, not because of caching.

## Assumptions

- CSV headers are `name` (required), `email`, `phone` (case-insensitive; extra columns preserved). `name` splits into first/last on the first space.
- The uploaded file is **kept** on the storage disk (local in dev, S3-ready) so the error CSV can be reconstructed; cleanup is left to a retention policy.
- Contact creation is **not** idempotent, so crash recovery fails (doesn't re-run) a partial import — the user re-uploads and duplicate detection short-circuits it.
- "Notify the team" = a `critical` log event; routing to Slack/PagerDuty is a `config/logging.php` channel concern.

## Architecture Decision Records

**ADR 1 — New `contact_import_jobs` table, not extending legacy `import_jobs`.**
The legacy vCard importer still uses `import_jobs` with different columns. A dedicated table matches the spec's schema, leaves the legacy path untouched, and is trivial to remove. *Consequence:* two import tables coexist; acceptable and clearly scoped.

**ADR 2 — Keep the uploaded file; don't store rows in the DB.**
The error CSV needs original row data. Re-reading the stored file by row number is cheaper than persisting every row, and the file is needed only on rare, post-completion error downloads. *Trade-off:* storage cost vs DB bloat — storage wins; pairs with an S3 disk at scale.

**ADR 3 — Idempotency via content hash (`file_hash` + `account_id`), feature-flagged.**
Hashing content (not filename+size+date) catches true duplicates regardless of name. Gated by `monica.contact_import_detect_duplicates` so "let both run" is a config switch. *Consequence:* a re-upload returns the existing job (`200`).

**ADR 4 — `Bus::batch` of 50-row chunks (not one job, not per-row jobs).**
50 bounds memory and lets one chunk fail without losing the import, while keeping per-job overhead low. `allowFailures(false)` gives the spec's "one fatal failure stops the rest". *Trade-off:* batching vs streaming a single long job — batching enables progress, partial failure, and cancellation.

**ADR 5 — Progress lives in the DB row, read by polling (not Redis, not events).**
`processed_rows`/`failed_rows`/`status` on the row make the progress endpoint a single indexed read (`<50ms`) and survive restarts, with no extra moving parts. *Trade-off:* DB-polling vs event-driven/websockets — polling is simpler and sufficient; counters use a row lock to avoid lost updates across parallel chunks.

## Trade-offs & production notes

- **At 10×**: chunks scale horizontally (add workers); a dedicated `imports` queue isolates them from `default`. Building the chunk list in-request is O(rows/50) — for very large files, move dispatch itself into a job.
- **One queue vs per-import queues**: a shared `imports` queue with fair workers is simpler than per-import queues; revisit if a huge import starves others (chunk priorities / more workers).
- **Rollback**: the feature is additive — drop the migration and remove the routes/services; the legacy importer is untouched. Duplicate detection and the whole CSV path are flag/route-gated.
- **Debugging a stuck import**: `ContactImportJob::stuck(30)` lists them; `imports:recover` reconciles; `batch_id` links to `job_batches`; structured `contact_import.*` logs carry duration/throughput/failure-rate.
- **Questioning the requirements**: storing the CSV on S3 (not the DB) is the right call at scale (ADR 2); the schema is S3-ready via the configured disk.

## Reachable via the API & easy to remove

Every capability is exposed under `/api/import` (the project's hard rule). The `/settings/import/upload` page is a thin client over that API — it calls the endpoints with the session's `laravel_token` cookie + CSRF header (no separate token), uploads a CSV or vCard, and polls for live progress. The new code is isolated under `…/ImportContacts/`, `Jobs/Contact/`, and its own table/routes, so it can be lifted out without touching existing behaviour.

---

<!-- ════════════════════ Original Monica README below ════════════════════ -->

<p align="center">

![Monica's Logo](https://user-images.githubusercontent.com/61099/37693034-5783b3d6-2c93-11e8-80ea-bd78438dcd51.png)

<p>
<h1 align="center">Personal Relationship Manager</h1>

<div align="center">

[![Build Status](https://img.shields.io/github/workflow/status/monicahq/monica/Build?style=flat-square&label=Build%20Status)](https://github.com/monicahq/monica/actions)
[![Docker pulls](https://img.shields.io/docker/pulls/library/monica)](https://hub.docker.com/_/monica/)
![Lines of code](https://img.shields.io/tokei/lines/github/monicahq/monica)
[![Code coverage](https://img.shields.io/sonar/coverage/monica?server=https%3A%2F%2Fsonarcloud.io&style=flat-square&label=Coverage%20Status)](https://sonarcloud.io/project/activity?custom_metrics=coverage&amp;graph=custom&amp;id=monica)
[![License](https://img.shields.io/github/license/monicahq/monica)](https://github.com/monicahq/monica/blob/main/LICENSE.md)


</div>

Monica is a great open source personal relationship management system.

- [Introduction](#introduction)
  - [Purpose](#purpose)
  - [Features](#features)
  - [Who is it for?](#who-is-it-for)
  - [What Monica isn’t](#what-monica-isnt)
  - [Where does this tool come from?](#where-does-this-tool-come-from)
- [Get started](#get-started)
  - [Requirements](#requirements)
  - [Update your instance](#update-your-instance)
- [Contribute](#contribute)
  - [Contribute as a community](#contribute-as-a-community)
  - [Contribute as a developer](#contribute-as-a-developer)
- [Principles, vision, goals and strategy](#principles-vision-goals-and-strategy)
  - [Principles](#principles)
  - [Vision](#vision)
  - [Goals](#goals)
  - [Strategy](#strategy)
  - [Monetization](#monetization)
  - [Why Open Source?](#why-open-source)
  - [Patreon](#patreon)
- [Contact](#contact)
- [Team](#team)
- [Thank you, open source](#thank-you-open-source)
- [License](#license)

## Introduction

Monica is an open-source web application to organize and record your interactions with your loved ones. We call it a PRM, or Personal Relationship Management. Think of it as a [CRM](https://en.wikipedia.org/wiki/Customer_relationship_management) (a popular tool used by sales teams in the corporate world) for your friends or family. This is what it currently looks like:

<p align="center">

![Screenshot of the application](docs/images/main-app.png)

</p>

### Purpose

Monica allows people to keep track of everything that’s important about their friends and family. Like the activities with them. When you last called someone and what you talked about. It will help you remember the name and the age of their kids. It can also remind you to call someone you haven’t talked to in a while.

### Features

* Add and manage contacts
* Define relationships between contacts
* Reminders
* Automatic reminders for birthdays
* Stay in touch with a contact by sending reminders at a given interval
* Management of debts
* Ability to add notes to a contact
* Ability to record how you met someone
* Management of activities with a contact
* Management of tasks
* Management of gifts given and received and ideas for gifts
* Management of addresses and all the different ways to contact someone
* Management of contact field types
* Management of a contact’s pets
* Basic journal
* Ability to record how your day went
* Upload documents and photos
* Export and import of data
* Export contacts as vCards
* Ability to define custom genders
* Ability to define custom activity types
* Ability to favorite contacts
* Track conversations on social media or SMS
* Multiple users
* Tags to organize contacts
* Ability to define what section should appear on the contact sheet
* Multiple currencies
* Multiple languages
* An API that covers most of the data

### Who is it for?

This project is **for people who have difficulty remembering details about other people’s lives** – especially those they care about. Yes, you can still use Facebook to achieve this, but you will only be able to see what people do and post, and not add your own notes about them.

We’ve also received lots of positive feedback from users who suffer from Asperger syndrome, Alzheimer’s disease, or simply introverts who use this application on a daily basis.

### What Monica isn’t

 * Monica is not a social network and **it never will be**. It’s not meant to be social. It’s designed to be the opposite: it’s for your eyes only.
 * Monica is not a smart assistant. It won’t guess what you want to do. It’s actually pretty dumb: it will only send you emails for the things you asked to be reminded of.
 * Monica is not a tool that will scan your data and do nasty things with it. It’s your data, your server, do whatever you want with it. You’re in control of your data.

### Where does this tool come from?

I originally built this tool to help me in my private life: I’ve been living outside my own country for a long time now. I want to keep notes and remember the life of my friends in my home country and be able to ask the relevant questions when I email them or talk to them over the phone.

Moreover, as a foreigner in my new country, I met a lot of other foreigners – and most go back to their countries. I still want to remember the names or ages of their kids. You may call it cheating but considering my poor memory, I call it caring.

After a few months, I decided to open source Monica so it could help other people as well.

## Get started

There are multiple ways of getting started with Monica:

1. You can use [our Hosted version](https://monicahq.com "Monica website").  This is the simplest way to use Monica.
1. You can install it on your own server by following the [installation instructions here](/docs/installation/readme.md). There are no limitations on Monica if you install it on your own server.

    - The downloadable version will always be the most complete version – the same as offered on the paid plan on the Hosted version.
    - Self-hosted will always be completely free with no strings attached and you will be in complete control.

1. You can deploy straight on a [PaaS platform](https://en.wikipedia.org/wiki/Platform_as_a_service) like:

    - Platform.sh [![Deploy on Platform.sh](https://platform.sh/images/deploy/deploy-button-lg-blue.svg)](https://console.platform.sh/projects/create-project/?template=https%3A%2F%2Fraw.githubusercontent.com%2Fmonicahq%2Fmonica%2Fmain%2F.platform.app.yaml&amp;utm_campaign=deploy_on_platform&amp;utm_medium=button&amp;utm_source=affiliate_links&amp;utm_content=https%3A%2F%2Fgithub.com%2Fmonicahq%2Fmonica)

    - [Heroku](https://heroku.com) [![Deploy to Heroku](https://www.herokucdn.com/deploy/button.svg)](https://heroku.com/deploy?template=https://github.com/monicahq/monica/tree/4.x)


### Requirements

If you want to host Monica yourself, you will need a server with:

- PHP 8.1 or newer
- HTTP server with PHP support (eg: Apache, Nginx, Caddy)
- Composer
- MySQL

To successfully build and host Monica, we recommend a system with at least 1.5&thinsp;GB for RAM.  Monica can run on systems with significantly less memory, but due to the high memory requirements of the build process during updates, you may encounter issues and failed builds.

### Update your instance

Once the software is installed, you’ll need to update it from time to time to have access to the latest features. [Read this document](/docs/installation/update.md) to learn how to do it.

## Contribute

Do you want to help? That’s awesome. We welcome contributions of all kinds from everyone.

Here are some of the things you can do to help.

### Contribute as a community

- Unlike Fight Club, the best way to help is **to actually talk about Monica** as much as you can in blog posts and articles, or on Twitter and  Facebook.

- You can answer questions in [the issue tracker](https://github.com/monicahq/monica/issues) to help other community members.

- You can financially support Monica’s development [on Patreon](https://www.patreon.com/monicahq) or by subscribing to [a paid account](https://monicahq.com/pricing).

### Contribute as a developer

- Read our [Contribution Guide](/CONTRIBUTING.md).

- Install [the developer version locally](/docs/contribute/readme.md) so you can start contributing.

- Look for [issues labelled ‘Bugs’](https://github.com/monicahq/monica/issues?q=is%3Aopen+is%3Aissue+label%3Abug) if you are looking to have an immediate impact on Monica.

- Look for [issues labelled ‘Help Wanted’](https://github.com/monicahq/monica/issues?q=is%3Aissue+is%3Aopen+label%3A%22help+wanted%22). These are issues that you can solve relatively easily.

- Look for [issues labelled ’Good First Issue’](https://github.com/monicahq/monica/labels/good%20first%20issue). These issues are for people who want to contribute, but try to work on a small feature first.

- If you are an advanced developer, you can try to tackle [issues labelled ‘Feature Requests’](https://github.com/monicahq/monica/issues?q=is%3Aopen+is%3Aissue+label%3A%22feature+request%22). These are harder to do and will require a lot of back-and-forth with the repository administrator to make sure we are going to the right direction with the product.


## Principles, vision, goals and strategy

We want to use technology in a way that does not harm human relationships, like big social networks can do.

### Principles

Monica has a few principles.

- It should help have better relationships.

- It should be simple to use, simple to contribute to, simple to understand, extremely simple to maintain.

- It is not a social network and never will be.

- It is not and never will be ad-supported.

- Users are not and never will be tracked.

- It should be transparent.

- It should be open-source.

- It should do one thing (documenting social interactions) extremely well, and nothing more.

- It should be well documented.

### Vision

Monica’s vision is to **help people have more meaningful relationships**.

### Goals

We want to provide a platform that is:

- **really easy to use**: we value simplicity over anything else.

- **open-source**: we believe everyone should be able to contribute to this tool, and see for themselves that nothing nasty is done behind the scenes that would go against the best interests of the users. We also want to leverage the community to build attractive features and do things that would not be possible otherwise.

- **easy to contribute to**: we want to keep the codebase as simple as possible. This has two big advantages: anyone can contribute, and it’s easily maintainable on the long run.

- **available everywhere**: Monica should be able to run on any desktop OS or mobile phone easily. This will be made possible by making sure the tool is easily installable by anyone who wants to either contribute or host the platform themselves.

### Strategy

We think Monica has to become a platform more than an application, so people can build on it.

Here what we should do in order to realize our vision:

- (**done**) Build an API in order to create an ecosystem. The ecosystem is what will make Monica a successful platform.

- (**done**) Build importers and exporters of data. We don’t want to have any vendor lock-ins. Data is the property of the users and they should be able to do whatever they want with it.

- (**done**) Be the central point of contact management, by supporting CardDav protocol.

- (**done**) Be the central point of calendar events, by supporting CalDav protocol.

- (**partially done**) Build great reports so people can have interesting insights on how they interact with their loved ones.

- Create a smart recommendation system for gifts. For instance, if my nephew is soon 6 years old in a month, I will be able to receive an email with a list of 5 potential gifts I can offer to a 6 year old boy.

- Add more ways of being reminded: Telegram, SMS,...

- Create Chrome extensions to load Monica’s data in a sidebar when viewing a contact on Facebook, letting us take additional notes as we see them on Facebook.

- Add modules that can be activated on demand. One would be for instance, for the people who wants to use Monica for dating purposes (yes, we’ve received this kind of feedback already).

### Monetization

While it’s not the driving force behind Monica, it would be great if the tool could generate money so we could work full time on it and sustain it on the long run. We are big fans of [Sentry](https://sentry.io), Wordpress and GitLab and we believe this kind of business model is an inspiring one where everyone wins.

If you want to support the development of Monica, consider taking [a paid account](https://www.monicahq.com/pricing), or support us [on Patreon](https://www.patreon.com/monicahq).

- The [Hosted version of Monica](https://monicahq.com) is offered in two versions:

    * a [free plan](https://app.monicahq.com/register) which includes:
        + 10 contacts
        + data exporters

    * a [paid plan](https://www.monicahq.com/pricing) which includes:
        + unlimited contacts
        + email reminders
        + data importers
        + advanced features

    * We’re still working on the features included in the paid plan, and these may be subject to change while we work out our business model to make Monica’s development sustainable.

    * People who substantially contribute to the GitHub repository (with a pull request that adds value, that gets merged – not a typo fix, for instance) will also have access to the paid version for free.

- There is a [Patreon account](https://www.patreon.com/monicahq) for those who want to financially support Monica’s development in another way. The best way to support Monica it is to actually talk about it and help grow its userbase.


There are no ads on the platform and there never will be. We will never resell your data on [the Hosted version](https://monicahq.com/) and we have no access to it if you self-host.

We are like you, and this is why we are on GitHub: we hate big corporations that do not have at heart the best interests of their users, even if they say otherwise. We believe that the only way to sustain the development of Monica is to actually make money in a good old-fashioned way.

### Why Open Source?

Why is Monica open source? Is it risky? Will someone steal my code and do a for-profit business that will kill my own business? Why reveal my strategy to the world? These are the kind of questions we’ve received by email already.

The answer to these questions is simple: yes, you can fork Monica and make a competing project, make money out of it (even if the license is not super friendly towards that) and I’ll never know. But it’s okay, I don’t mind.

I wanted to open source Monica for several reasons:

- **I believe that this tool can really change people’s lives.**  
    While I aim to make money out of it, I also want everyone to benefit from it. Open sourcing a project like this will help Monica become much bigger than what I imagine myself. While I strongly believe that this software has to follow the vision I have for it, I need to be humble enough to know that ideas come from everywhere, and people have much better ideas than what I can have.

- **You can’t make something great alone.**  
    While Monica could become a company and hire a bunch of super smart people to work on it, you can’t beat the manpower of an entire community. Open sourcing the product means bugs will be fixed faster, features will be developed faster, and more importantly, developers will be able to contribute to a tool that positively changes their own lives and the lives of other people.

- **Doing things in a transparent way leads to formidable things.**  
    People respect the project more when they can see how it’s being worked on. You can’t hide nasty things in the code. You can’t do things behind the backs of your users. Doing everything in the open is a major driving force that motivates you to keep doing what’s right.

- **Once you’ve created a community of passionate developers around your project, you’ve won.**  
    Because developers are very powerful influencers. Developers will create apps around your product, talk about it on forums, and share the project with their friends, families, and colleagues. Cherish the developers – users will follow.

### Patreon

You can support the development of Monica [on Patreon](https://www.patreon.com/monicahq). Thanks for your help.

## Contact

## Team

Our team is made of two core members:

- [Maazarin (djaiss)](https://github.com/djaiss)

- [Alexis Saettler (asbiin)](https://github.com/asbiin)

We are also fortunate to have an amazing [community of developers](https://github.com/monicahq/monica/graphs/contributors) who help us greatly.

## Thank you, open source

Monica uses a lot of open source projects and we thank them with all our hearts. We hope that providing Monica as an free, open source project will help other people the same way those softwares have helped us.

## License

Copyright © 2016–2022

Licensed under [the AGPL License](/LICENSE.md).
