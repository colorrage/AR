# AR package — Agent instructions

> **Start here** when working in `/AR` (this folder). Read [`.claude.md`](.claude.md) for the full technical digest; this file is a shorter checklist for any AI agent.

## One-line summary

**`cmr-management/laravel-autoresponder`** — Laravel package (`ColorrageAR\Autoresponder`) for autoresponder sequences, campaigns, mailer lists, HTML templates, tracking, and unsubscribes. Subscribers are **any** Eloquent model implementing **`Subscribable`**, configured via **`config/autoresponder.php`**.

---

## Before you edit

1. Open **[`config/autoresponder.php`](config/autoresponder.php)** — single source of truth for keys (`table_prefix`, `subscriber_model`, `conditions`, `stop_events`, `trigger_handlers`, `queue`, etc.).
2. Open **[`src/helpers.php`](src/helpers.php)** — `ar_table`, `ar_log`, `ar_queue`, `ar_subscriber_model`, `ar_subscriber_key`.
3. For behavior changes, trace **`AutoresponderService`** → jobs **`SendStepEmail`** / **`ProcessEnrollment`** → models **`Enrollment`**, **`SendLog`**, **`StepLog`**.

---

## File locations (do not search blindly)

| Task | Go to |
|------|--------|
| Enrollment / steps / conditions | `src/Services/AutoresponderService.php`, `src/Models/{Sequence,Step,Enrollment,StepLog}.php` |
| Template tokens / buttons | `src/Services/TokenService.php` |
| Broadcast campaigns | `src/Services/CampaignService.php`, `src/Models/Campaign.php`, jobs `SendCampaignBatch`, `SendSingleCampaignEmail` |
| Mailer lists | `src/Services/ListService.php`, `src/Models/MailerList*.php` |
| Tracking pixels / clicks | `src/Http/Controllers/TrackingController.php`, `SendLog` |
| Unsubscribe | `src/Http/Controllers/UnsubscribeController.php`, `src/Models/Unsubscribe.php` |
| Scheduled / cron entrypoints | `src/Commands/*.php` |
| Event-driven enroll | `src/Listeners/TriggerListener.php`, `src/Events/*.php` |
| Provider / registration | `src/AutoresponderServiceProvider.php` |
| HTTP routes | `routes/web.php` |

---

## Invariants (breaking if wrong)

- All package tables use **`getTable()` → `ar_table('...')`** matching migrations’ **`config('autoresponder.table_prefix')`**.
- **`send_logs.id`** is what tracking URLs use; do not rename without updating controllers and jobs.
- **`enrollments.processing_token`** is used for atomic claiming in **`ProcessEnrollments`** command — keep compatible with raw `UPDATE` logic.
- Route names **`autoresponder.track.open`**, **`autoresponder.track.click`**, **`autoresponder.unsubscribe`** are referenced from **`SendStepEmail`** and similar — keep names stable or update all call sites.

---

## Extension contracts

| Contract | Purpose |
|----------|---------|
| `Subscribable` | Identity + email + name + locale for sends |
| `ConditionChecker` | Per-step “should send” when `condition_type` is custom |
| `StopEventChecker` | Stop sequence when `stop_sequence_on_event` is custom |
| `TriggerHandler` | Return subscribers for **`autoresponder:check-triggers`** |
| `TokenResolver` | Optional extra **`##...##`** tokens via `custom_token_resolver` |

Register classes in **`config/autoresponder.php`** under `conditions`, `stop_events`, `trigger_handlers`.

---

## Commands (artisan)

- `autoresponder:process-enrollments` — claim due enrollments, dispatch `ProcessEnrollment`
- `autoresponder:check-triggers` — time-based triggers via `trigger_handlers`
- `autoresponder:process-scheduled-campaigns` — scheduled broadcast campaigns
- `autoresponder:import-templates` — import HTML files as `Template` rows

Schedule these in the **host** app’s `app/Console/Kernel.php` or Laravel 11+ scheduler.

---

## What this package does **not** contain

- No Filament / Nova / Livewire admin UI
- No default `users` table migration (host provides subscriber model)
- No coupling to CMR **`Client`** / shared legacy DB — that lives in **`laravel/`**, not here

---

## Doc drift warning

**[`README.md`](README.md)** is user-facing and may show alternate config shapes. For implementation details, prefer **`config/autoresponder.php`** and **`.claude.md`**.
