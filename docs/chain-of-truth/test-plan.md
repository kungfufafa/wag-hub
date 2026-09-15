# Test Plan — WhatsApp Gateway Hub MVP

Status: Reviewed  
Derived from: SRS 0.1.0, User Flows 0.1.0, UCIC 0.1.0, Prototype 0.1.0

## Scope and risk

P0 scope: API auth/isolation, phone normalization, idempotency, sync acceptance, async durability, fallback classification, expiry, attempt/event persistence, secret safety, admin authorization, attachment upload/URL ownership, media delivery, and retention. P1 scope: configuration UI, Inbox filtering/timeline, safe retry, web-shelf adapter compatibility. Raw inbound webhook, provider delivery/read, dan HA load test tidak termasuk MVP.

Risiko tertinggi adalah duplicate delivery setelah ambiguous timeout, false-positive 2xx, OTP terlambat, replay race, credential leak, dan cross-application status access.

## Strategy

- Unit: normalizer, token generation/hash, canonical fingerprint, provider result classifier, state/result DTO.
- Feature/API: auth, validation, idempotent replay/conflict, ownership, sync/async response, queue dispatch.
- Integration: database + mocked HTTP provider + ordered routing/attempt/event lifecycle.
- Attachment: multipart upload, MIME/extension checks, private ownership, signed URL GET/HEAD/Range, cleanup, provider media contracts, and missing-file behavior.
- UI: Filament panel authorization, secret casts, resource smoke tests, retry state guard.
- Pilot: web-shelf adapter dengan fake Hub response dan no provider credential dependency.
- NFR: raw database/log assertions, static formatting, dependency audit, coverage when driver available.

## Environment and data

- PHP 8.4, Laravel 12, PHPUnit 11.
- SQLite in-memory, `array` cache/session, sync/fake queue, dan Laravel `Http::fake()`.
- Factory records memakai placeholder credential; tidak ada credential/provider nyata.
- Test independen dan database di-refresh per test.

## Entry and exit criteria

- Entry: SRS/flows/data/UCIC berstatus Reviewed dan implementation exception dicatat.
- RED gate: test target benar-benar berjalan dan gagal karena behavior belum ada.
- Exit: semua P0/P1 outbound + attachment automated tests pass, tidak ada skipped test, Pint pass, dependency audit bersih, dan target coverage 80% saat coverage extension tersedia.
- Browser runtime inspection wajib mencakup login, dashboard, resource navigation, form, empty state, dan console error. Live-provider smoke tetap gate deployment terpisah.

## Execution and evidence

Primary commands: `php artisan test`, targeted `php artisan test --filter=...`, `vendor/bin/pint --test`, `composer audit`, `npm audit`, dan `npm run build`. Coverage: `php artisan test --coverage --min=80` pada CI/runtime yang memiliki Xdebug atau PCOV. Hasil dicatat di `test-execution.md`.
