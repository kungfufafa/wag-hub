# Provider Health Alerts (Telegram + Email)

**Date:** 2026-07-25  
**Status:** Draft for review  
**Scope:** Notify Filament admins when a `ProviderAccount` health status changes, via Telegram and/or Email configured entirely from the admin UI (not `.env`).

## Problem

WAG-Hub already tracks provider health (`unknown` / `healthy` / `degraded` / `unavailable`) and opens a circuit breaker via `ProviderHealthRecorder`, but operators only learn about problems by looking at the Filament panel. There is no outbound alert channel.

Operators need Uptime-Kuma-style notifications so they can investigate immediately when a provider account fails or recovers.

## Goals

- Alert on **every** health status transition, including recovery to `healthy`.
- Support **Telegram and Email** from day one.
- All operational config lives in the **UI** (no `MAIL_*` / Telegram secrets in `.env` for this feature).
- Global **sender** credentials; **per-admin** destinations.
- Cooldown per provider to avoid alert storms during flapping.
- Full alert body + admin **delivery history** for tracing.

## Non-goals

- Monitoring arbitrary HTTP endpoints (Uptime Kuma’s core job).
- Discord / Slack / generic webhooks (can be added later).
- Per-provider channel overrides.
- Alerting on inactive / soft-deleted providers that never change health.
- Replacing Filament in-app `Notification::make()` toasts.

## Decisions (locked)

| Topic | Choice |
|---|---|
| Trigger | Every `health_status` change, including recovery |
| Channels | Telegram + Email |
| Sender credentials | Global UI settings (SMTP + Telegram bot token) |
| Destinations | Per Filament admin profile |
| Flapping | Cooldown per provider (default 5 minutes, UI-configurable) |
| Traceability | Rich alert body + read-only Alert Deliveries page |
| Detection | Emit `ProviderHealthChanged` from `ProviderHealthRecorder` when status actually changes |
| Delivery | One queued job per (user × channel) delivery; Laravel Notification used inside the job |

## Architecture

```
ProviderHealthRecorder
  → status changed?
  → dispatch ProviderHealthChanged (after commit)
  → SendProviderHealthAlerts listener
       → master switch off? stop
       → create alert_deliveries rows (pending / skipped_cooldown)
       → for each pending delivery: queue DeliverProviderHealthAlert job
            → build mailer / call Telegram from alert_settings
            → send via Notification (sync inside job)
            → mark delivery sent | failed
```

### Why emit from the recorder (not a model observer)

`ProviderHealthRecorder` already knows the previous status, the new status, consecutive failures, circuit window, and the provider error context from `ProviderResult` / number-check failures. An Eloquent observer on `health_status` would catch manual edits but lose that context and produce thinner alerts. Manual admin edits of health are rare; richer failure context is more valuable for investigation.

### Why a job wraps the notification

Laravel’s `Mail::build()` is not a reliable Laravel 12 public API for this design. Instead:

1. The job loads encrypted SMTP settings from `alert_settings`.
2. Registers / selects a named mailer for this send (runtime `config` + `Mail` manager purge/select pattern, or equivalent supported Laravel 12 approach).
3. Sends a non-queued Notification for that one notifiable + channel.
4. Records success/failure on `alert_deliveries`.

This keeps retry, backoff, and delivery logging accurate per channel.

## Data model

### `alert_settings` (singleton, one row)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| is_enabled | boolean | Master switch |
| cooldown_seconds | unsigned int | Default 300 |
| smtp_host | string nullable | |
| smtp_port | unsigned smallint nullable | Default 587 |
| smtp_username | string nullable | |
| smtp_password | text nullable | `encrypted` cast |
| smtp_encryption | string nullable | `tls` / `ssl` / null |
| smtp_from_address | string nullable | |
| smtp_from_name | string nullable | |
| telegram_bot_token | text nullable | `encrypted` cast |
| timestamps | | |

### `user_alert_preferences` (1:1 with `users`)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | FK unique | Admin user |
| telegram_enabled | boolean | Default false |
| telegram_chat_id | string nullable | |
| email_enabled | boolean | Default false |
| email_address | string nullable | Null = use `users.email` |
| timestamps | | |

### `alert_deliveries`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| uuid | uuid unique | Public / UI key |
| provider_account_id | FK nullable | Soft-delete safe (nullOnDelete) |
| user_id | FK nullable | nullOnDelete |
| channel | string | `telegram` \| `email` |
| from_status | string | |
| to_status | string | |
| consecutive_failures | unsigned int | Snapshot |
| circuit_open_until | datetime nullable | Snapshot |
| error_summary | string nullable | Truncated/sanitized provider error |
| status | string | `pending` \| `sent` \| `failed` \| `skipped_cooldown` |
| failure_reason | text nullable | Transport / API error (no secrets) |
| sent_at | datetime nullable | |
| timestamps | | |

Indexes: `(provider_account_id, created_at)`, `(status, created_at)`, `(user_id, created_at)`, `(channel, created_at)`.

## Components

| Unit | Responsibility |
|---|---|
| `ProviderHealthChanged` | Domain event: provider id, old/new status, failures, circuit, error summary |
| `SendProviderHealthAlerts` | After-commit listener: gate master switch, apply cooldown, create deliveries, queue jobs |
| `AlertCooldownStore` | Cache key `alert:cooldown:provider:{id}` with TTL = cooldown_seconds |
| `DeliverProviderHealthAlert` | Queued job (tries=3, backoff): send one channel to one user, update delivery row |
| `ProviderHealthChangedNotification` | Builds mail / telegram message content (not `ShouldQueue`) |
| `TelegramChannel` | Custom notification channel: `sendMessage` to Bot API |
| `AlertSettings` / `UserAlertPreference` / `AlertDelivery` | Eloquent models |
| Filament **Alert Settings** page | Global SMTP, Telegram token, cooldown, master switch, test buttons |
| Filament **My Alert Preferences** page | Per-admin toggles + destinations + test buttons |
| Filament **Alert Deliveries** resource | Read-only history, filterable |

## Alert content

Shared body for Telegram and Email (severity/emoji differ by severity):

```
🔴 Provider UNAVAILABLE
Nama: WAHA Produksi
Driver: waha
Status: healthy → unavailable
Kegagalan beruntun: 3
Circuit open sampai: 2026-07-25 10:20:00 WIB
Error terakhir: Connection timed out
Telusuri: https://{app-url}/panel/provider-accounts/{uuid}
```

Severity mapping:

| `to_status` | Marker |
|---|---|
| `unavailable` | 🔴 |
| `degraded` | 🟡 |
| `healthy` | 🟢 |
| `unknown` | ⚪ |

Error summary max length: 500 characters; strip credentials-looking substrings where practical; never include SMTP password or bot token.

## Cooldown rules

- Keyed by `provider_account_id` only (all channels share one cooldown window).
- On a **sent** attempt for a provider transition, set/refresh the cooldown TTL.
- If still cooling down: create `alert_deliveries` with `skipped_cooldown` (for audit), do **not** queue send jobs.
- Cooldown does **not** suppress recovery alerts preferentially — every transition type is treated equally under the same window (operators chose “alert on every change” + cooldown, not confirm-then-notify).
- Default cooldown: 300 seconds; editable in Alert Settings (minimum 0 = no cooldown, maximum e.g. 3600).

## UI behavior

### Alert Settings (global, admin-only)

- Master enable/disable.
- Cooldown seconds.
- SMTP fields + “Send test email” (asks for a destination address).
- Telegram bot token + “Send test Telegram” (asks for a chat ID).
- Secrets show as empty/password fields; leaving blank on save keeps existing secret.

### My Alert Preferences (each admin)

- Toggle Telegram / Email independently.
- Telegram chat ID.
- Optional email override (default `user.email`).
- Test buttons require complete global sender config for that channel. **Test sends ignore the master switch** so operators can verify setup before enabling live alerts.

### Alert Deliveries

- List + view only (no create/edit/delete from UI).
- Filters: provider, channel, status, date range, user.
- Shows from→to, error summary, failure reason, sent_at.

## Security

- `smtp_password` and `telegram_bot_token` use Laravel encrypted casts (same pattern as `ProviderAccount.configuration`).
- Secrets never written to `alert_deliveries`, logs, or Filament notifications.
- Only users with `canAccessPanel` (active admins) receive preference UI; only those users are notifiable targets.
- Authorization: Alert Settings and Deliveries restricted to panel admins (same gate as other resources).
- Provider deep-link uses public `uuid`, not internal bigint id.

## Failure & retry

- Job: `tries = 3`, exponential/backoff delays, `timeout` sane (e.g. 60s).
- On final failure: mark delivery `failed` with sanitized `failure_reason`.
- Telegram failure must not block Email for the same event (separate jobs / deliveries).
- If SMTP or Telegram global config is incomplete for a channel, mark that delivery `failed` with a clear reason (or skip creating pending for that channel) — prefer fail with reason so history shows misconfiguration.

## Testing plan (acceptance)

1. Health transition emits event only when status string changes.
2. All transitions among `unknown` / `healthy` / `degraded` / `unavailable` create expected deliveries.
3. Recovery to `healthy` alerts when not in cooldown.
4. Cooldown skips with `skipped_cooldown` records.
5. Only active admins with the matching channel enabled are targeted.
6. Email path uses DB SMTP, not `.env` `MAIL_*`, for this feature.
7. Telegram HTTP client mocked; success and API error paths covered.
8. Channel isolation: Telegram fail does not prevent Email job success.
9. Encrypted columns are not readable as plaintext in DB assertions.
10. Filament authorization + read-only deliveries.
11. Deep link URL points at the correct provider account page.

## Out of scope follow-ups

- Digest / daily summary.
- Quiet hours.
- Webhook / Discord channels.
- Observing manual `health_status` edits outside the recorder.

## Open implementation notes (non-blocking)

- Exact Filament 4 page registration for profile-style “My Alert Preferences” (custom Page vs user resource edit) — prefer a dedicated Page under `app/Filament/Pages` for clarity.
- Named mailer registration strategy must follow Laravel 12-supported APIs (runtime config + mailer selection on `MailMessage` / `Mail` facade); avoid relying on undocumented helpers.
