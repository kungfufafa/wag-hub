# Engine v2 integration and operations

wag-hub owns WhatsApp sessions, authentication, capacity, transport outcomes, and
normalized events. A consuming application owns its users, permissions, sender
selection, business history, and UI. It stores hub IDs and disposable observations;
network failures never prove WhatsApp disconnected. The repositories and databases
remain independent. Public contract: [openapi-v2.yaml](openapi-v2.yaml).

## Installation and shortest connection flow

The hub operator creates a client application for each isolated installation and
issues an integration pack in the existing application administration UI. The
pack supplies `WAG_URL` and `WAG_TOKEN`. It grants the full-control preset:
`integration:read`, `sessions:read`, `sessions:manage`, `messages:read`,
`messages:send`, `media:read`, `media:write`, `webhooks:manage`, `capacity:manage`.
Existing migration credentials also retain their v1 abilities. Tokens stay in the
consumer backend; its user permissions apply before every hub operation.

Set the account ceiling through the operator command:

```bash
php artisan engine:grant-capacity cesa-installation 10 --limit=5
```

The consumer administrator configures the URL/token once. The backend discovers
`GET /api/v2/integration`, registers its signed callback with
`PUT /api/v2/integration/webhook`, and imports `GET /api/v2/sessions`.

The ordinary connection journey is **Connect WhatsApp → scan QR → ready**. Create
with `start:true`, omit the name and phone, then display the challenge from the
consumer's backend. Poll at two seconds while connecting, 15 seconds on a visible
account list, and reconcile in the background. Stop browser polling while hidden.
Re-use the same create idempotency key across double-clicks, reloads, and timeouts.
Name changes and test messages are optional after connection. Pairing is an
alternative inside the same dialog, requiring a phone only after selection.

A PHP backend example using Laravel's HTTP client:

```php
$hub = Http::baseUrl(rtrim(config('services.wag.url'), '/'))
    ->withToken(config('services.wag.token'))->acceptJson()->timeout(15);

// Persist this attempt key before the HTTP request; reuse it after a lost response.
$attempt = (string) Str::uuid();
$created = $hub->withHeaders(['Idempotency-Key' => $attempt])
    ->post('/api/v2/sessions', ['start' => true])->throw()->json('data');
$sessionId = $created['session']['id'];

// Re-fetch through the backend while connecting; never expose WAG_TOKEN to JS.
$challenge = $hub->get('/api/v2/sessions/'.$sessionId.'/auth/qr')
    ->throw()->json('data');
$snapshot = $hub->get('/api/v2/sessions/'.$sessionId)->throw()->json('data');
```

Do not mark the UI connected until `status=WORKING` and `stale=false`. On connection,
select the first successful account as default only if there is no default already.
Return the user to their originating workflow. A normal user with an authorized
connected default account needs no setup journey.

## Lifecycle, messages, and events

All public requests use HTTPS and a bearer token. Ownership is always derived from
that token. Public IDs are UUIDs. Successful resources use a `data` envelope.
Creation returns `201` with `data.session` and nullable `data.operation`; lifecycle
requests return `202` with the same shape. Poll `/api/v2/operations/{id}` until the
command completes. QR/pairing challenges are ephemeral and sent with `no-store`.

| CESA action | Public API | Effect |
| --- | --- | --- |
| Connect / Resume | Create with `start:true` / POST session `/start` | Reuses credentials or prompts authentication |
| Pause connection | POST session `/stop` | Blocks new sends; preserves credentials and slot |
| Unlink WhatsApp | POST session `/logout` | Clears auth; preserves slot; reports remote unlink separately |
| Remove account | DELETE session | Releases slot after local teardown; keeps audit tombstone |

Only `STOPPED`, `STARTING`, `SCAN_QR_CODE`, `WORKING`, and `FAILED` are connection
statuses. Pairing uses `SCAN_QR_CODE` with `auth_method=pairing`. Desired state,
pending operation, observation time, and freshness are separate. QR input does not
need any registered phone. The actual phone comes from authenticated WhatsApp.
Unlink/Remove require clear user confirmation in the consumer UI. Removing the
default asks for a new sender; queued sends never migrate silently to another one.

All non-deleted sessions consume capacity, including stopped and unauthenticated
sessions. Limits cannot be lowered below usage or raised past the granted ceiling.
Never-authenticated abandoned sessions are removed after ten minutes.

Sending:

```http
POST /api/v2/sessions/{sessionId}/messages
Authorization: Bearer <installation-token>
Idempotency-Key: business-delivery-123
Content-Type: application/json

{"type":"text","to":"+6281234567890","text":"Your appointment is confirmed."}
```

Use international recipient numbers. Text is capped at 10,000 characters. For
image/document/voice, first multipart-upload `file` and `type` to `/api/v2/media`,
then send `media_id`; optional `text` is its caption. Uploads are private, capped at
16 MiB, and expire after seven days. Images are JPEG/PNG. Voice conversion requires
FFmpeg/ffprobe, is limited to five minutes and bounded execution time, and emits
OGG/Opus. There is no arbitrary media-URL fetch endpoint.

The message is durable before `202`. Poll `/api/v2/messages/{id}` for
`transport_status=queued|processing|accepted|failed|expired|outcome_unknown`.
Recipient delivery/read/played receipts are separate. `accepted` is not delivered.
Use the same idempotency key for retries; changed payload returns `409`.
`outcome_unknown` is reconciled through the original runner journal and ACKs. Do
not blindly resend or choose another account. Already dispatched messages may
arrive after Pause/Unlink.

Only `message`, `message.ack`, and `session.status` events are public. The envelope
contains `id`, `schema_version`, `installation_id`, `session_id`, `revision`,
`occurred_at`, `event`, and `data`. Incoming media uses private `media_id` references.
Groups, channels, stories, labels, polls, calls, and presence are excluded.

Offline direct-message arrivals are retained when Baileys reconnects. Durable
provider-identity receipts suppress duplicate message/ACK events across restart
and hub acknowledgement. An unavailable incoming attachment remains pending with
bounded retries; unrelated messages, acknowledgements, and session events continue
through the spool. Pending attachments cannot be acknowledged before hydration
or an explicit terminal media error.

The hub signs the exact JSON request body:

```php
$timestamp = $request->header('X-Wag-Timestamp', '');
$expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401);
abort_unless(hash_equals($expected, $request->header('X-Wag-Signature', '')), 401);
// Transactionally insert the event with a unique event ID; enqueue work after commit.
// Return 2xx only after durable receipt. A duplicate valid event also receives 2xx.
```

Headers are `X-Wag-Timestamp`, `X-Wag-Signature` (hex HMAC-SHA256), and
`X-Wag-Event-ID`. Keep clocks synchronized. Deduplicate event IDs and provider
message IDs; ignore older session revisions. Verify the installation identity.
Webhook retries use capped exponential backoff with jitter for 24 hours; events
remain replayable for seven days through `/api/v2/events?after={next_cursor}`.
Start at `0`, continue while `has_more`, and persist the cursor only after processing.
A `410 event_cursor_expired` requires refreshed authoritative sessions and replay
from `0` with deduplication. Webhook failure does not change connection status.
Public callbacks require HTTPS; private callback hosts need the explicit operator
allowlist `WAG_WEBHOOK_PRIVATE_HOSTS`.

Inactive or deleted installations do not occupy webhook delivery batches for
active installations. Reactivation resumes pending delivery within its original
24-hour retry window; after that window, retained events remain available through
the replay endpoint until their seven-day expiry.

Typical recovery codes:

| Code / HTTP | Consumer response |
| --- | --- |
| 401 / 403 | Administrator rotates/replaces the token or grants required capability |
| `capacity_exceeded` / `capacity_invalid` | Adjust limit within grant or remove an unused account |
| `idempotency_conflict` | Correct the request/key mismatch; never silently overwrite intent |
| `session_not_working` / `session_stopped` | Refresh account; prompt Resume or authenticate |
| `media_expired` | Upload a fresh attachment for a new business delivery |
| `event_cursor_expired` | Refresh sessions and replay retained events |
| `delivery_unknown` | Keep outcome uncertain and query the same message |
| 429 | Back off; reuse the original idempotency key |
| 503 / timeout | Display hub unavailable and retain the last observation as stale |

## Supervised deployment

The new [Compose file](../engine/docker-compose.v2.yml) provides a MySQL database,
PHP/Apache app with FFmpeg, independent `engine-control` and `engine` queue workers,
a separate `default` queue worker for legacy traffic, a Laravel scheduler, and the
private Node runner. PHP **8.4.1 or newer** is required by the application and its
locked dependencies; the image uses PHP 8.4. Public access defaults to
loopback port 8080; terminate TLS in the existing reverse proxy. Only the app is
published. Runner and DB ports stay inside the container network.

From the wag-hub repository root:

```bash
cp engine/.env.v2.example engine/.env.v2
# Fill every secret independently and set the external HTTPS URL.
docker compose --env-file engine/.env.v2 -f engine/docker-compose.v2.yml build
docker compose --env-file engine/.env.v2 -f engine/docker-compose.v2.yml up -d database
docker compose --env-file engine/.env.v2 -f engine/docker-compose.v2.yml run --rm app php artisan migrate --force
docker compose --env-file engine/.env.v2 -f engine/docker-compose.v2.yml up -d
```

Migrations are an explicit release step, not a container startup race. Build
requires access to the Composer repositories already declared by the app. The
sample stack does not seed an administrator or expose stored tokens; use the
application's existing operator provisioning. Preserve the named DB, app-storage,
and runner-data volumes. Back up SQL together with the Laravel `APP_KEY` and runner
`WAG_BAILEYS_AUTH_KEY`; keep encryption keys separate from ordinary database copies.
Do not run competing runner replicas against one store.

Existing non-container deployments need the same supervised processes:

```bash
php artisan queue:work database --queue=engine-control --sleep=1 --tries=1 --timeout=60
php artisan queue:work database --queue=engine --sleep=1 --tries=1 --timeout=95
php artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=360
php artisan schedule:work
# In engine/baileys-runner after configuring its .env:
npm start
```

Keep the queue retry interval larger than worker/job timeouts; the sample uses
420 seconds. `engine:control-tick` runs every 15 seconds. `engine:transport-tick`
runs every five seconds; daily cleanup expires media/events. The scheduler also
recovers durable work after a lost enqueue. Separate worker processes prevent
message/network waits from delaying account management.

Keep the `default` worker running throughout the compatibility window so accepted
v1 messages and other existing default-queue jobs can complete. Its 390-second
shutdown grace period permits the 360-second legacy message job to finish; the
420-second queue retry interval prevents concurrent redelivery. After verified
legacy retirement, drain outstanding work and check other default-queue jobs
before stopping that worker.

Production runner authentication defaults to encrypted SQL and requires
`WAG_HUB_CONTROL_URL`, `WAG_BAILEYS_AUTH_KEY`, and `WAG_BAILEYS_DB_*`. Its internal
bearer token authorizes `GET /internal/engine/desired-sessions`. On restart, only
hub-authorized RUNNING sessions restore. An unreachable hub leaves readiness false
and retries startup synchronization; stale local intent cannot reconnect accounts.
See [runner operations](../engine/baileys-runner/README.md).

Monitor `/health/live` without a token and `/health/ready` with an installation
credential carrying `integration:read`. Readiness reports database, runner, and
worker heartbeats written only when queued probes execute. The scheduler alone
cannot report stopped workers as healthy. Background session observations run
as queued jobs so one slow runner call cannot block all scheduled maintenance.
Also monitor queue age/depth, failed jobs,
`engine_events.failed_at`/delivery errors, stale session observations,
`engine_messages.transport_status=outcome_unknown`, and runner disk usage. The
readiness endpoint is not a capacity benchmark or proof of WhatsApp delivery.

## Migration, rollback, and release acceptance

1. Back up both repositories' databases and runner storage. Restore `cesa.gz` into
   an isolated MySQL environment and run CESA additive migrations before touching
   production. Preserve account IDs and notification-history references.
2. Deploy hub v2 alongside v1 and grant enough capacity for existing accounts.
   Inspect `php artisan engine:migrate-legacy --application=cesa-installation --dry-run`,
   then run it without `--dry-run`. Mapping preserves each native runner ID and
   external reference; it does not send messages or invent verified connection state.
   Existing dashboard controls for mapped accounts use the same durable hub
   operations and generations as v2 clients. Account deletion waits for confirmed
   teardown, and old callbacks cannot overwrite the mapped authoritative status.
3. For existing native file authentication, stop the old runner, configure SQL auth,
   and run `npm run import-file-auth -- <runner-id>` from `engine/baileys-runner` for
   each mapped account. Source files remain intact; existing SQL auth is never
   overwritten, and import creates no RUNNING intent. Start the SQL runner after
   mapping. Consumer-local legacy engines require deliberate relinking unless
   separately migrated; a saved phone number is not authentication.
4. Configure CESA's WAG URL/token, register its signed webhook, synchronize hub
   observations, and enable the new path only after disabling the old sender for
   those accounts. Confirm account UI permissions and the first-account default.
5. Verify actual QR and pairing, text, image, document, voice, inbound media, and
   delivered/read receipts using designated test accounts. Test runner/hub restart,
   paused-account restart, webhook outage/replay, ambiguous-send handling, and
   cross-installation resource isolation. Load-test the intended account count.
6. After real acceptance for each active installation, record it with
   `php artisan engine:legacy-retirement --verify=cesa-installation`. Run
   `php artisan engine:legacy-retirement` to inspect eligibility. Legacy middleware
   records each installation's last v1 request. `php artisan engine:legacy-retirement --retire`
   refuses until every active installation has been verified for at least seven
   days and has no legacy traffic in the preceding seven days. Eligible retirement
   makes legacy requests return HTTP 410 while preserving code/data for audit.
   No production acceptance or retirement is executed by this implementation.

Rollback selects one sender path. Pause new dispatch, drain or record pending/
unknown outcomes, stop the v2 sender, and only then restore the selected previous
path against compatible backups. Never dual-run old and new engines for the same
account, re-send unknown messages, or drop additive tables as a routine rollback.
The checked-in tests and container definitions do not substitute for the real
MySQL restore, real WhatsApp media checks, and deployment/load acceptance above.
