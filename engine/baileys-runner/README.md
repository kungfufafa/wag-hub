# Private WhatsApp runner

This single-process Node/Baileys runner belongs to wag-hub. Consumer products
communicate with the public Laravel API, never this port. Bind to loopback or a
private network and require `Authorization: Bearer <WAG_BAILEYS_TOKEN>` on every
request, including health checks.

## Setup

Requires Node 22.14+, MySQL/InnoDB, and persistent private storage.

```bash
cp .env.example .env
# Set the internal token, SQL credentials, control URL, and encryption key.
npm ci --omit=dev
npm start
```

The internal token must match Laravel's `WAG_BAILEYS_TOKEN`. It is separate from
installation API tokens. Generate the authentication encryption key with
`openssl rand -base64 32`; retain it securely alongside encrypted DB backups.
Changing it without re-encrypting the database makes existing authentication
unreadable. The table `wag_runner_auth` is created during startup. Credentials
and Signal keys use AES-256-GCM, bound to the owning session/key, with atomic
transactions for key batches. Late credential writers are fenced after logout.
Use a least-privilege DB account with access to that runner table; initial table
creation requires CREATE permission.

`WAG_BAILEYS_AUTH_MODE=sql` is the default. File authentication requires explicit
`WAG_BAILEYS_AUTH_MODE=file` and is rejected with `NODE_ENV=production`.

`WAG_HUB_CONTROL_URL` is the hub origin, required in SQL mode. On boot, the runner
fetches `GET /internal/engine/desired-sessions` using its internal bearer token.
Only hub-authorized RUNNING sessions restore; unlisted/stopped sessions remain
inactive. If the hub is unreachable, `/health` returns 503, startup restoration
waits, and synchronization retries every five seconds. Lifecycle commands remain
available. Development file mode can restore explicit local RUNNING intents.
Merely finding an old credentials directory never starts an account.

Run one supervised process per storage root, with automatic restart. Preserve
`runner-state/`, `runner-events/`, and `whatsapp-messages/` across releases.
Their directories/files use 0700/0600 permissions. Session intent, events, and
send dispatch records are fsynced before acknowledgement. Spool files contain
message content and must reside on private storage. Monitor free disk space;
never delete pending events or send journals to recover space without an
operator-led retention decision. Production sizing needs real-account load tests.

## Import existing native authentication

Stop the runner, configure SQL credentials and `WAG_BAILEYS_AUTH_KEY`, then run
once for each existing native runner ID:

```bash
npm run import-file-auth -- wgh-11223344-5566-7788-9900-aabbccddeeff
```

The importer reads that ID's directory under `WAG_BAILEYS_SESSION_ROOT`, validates
regular JSON files, preserves Baileys buffer values, and imports all records in
one encrypted transaction. It refuses to overwrite existing SQL authentication.
Original files remain intact and no RUNNING intent or socket is created. Existing
file-encoded Signal key names remain readable; subsequent updates migrate those
keys naturally. Start the runner in SQL mode after importing. Retain the old files
securely until rollback is no longer needed, then remove them through your normal
credential-retention process. Consumer-side local authentication is not imported
unless explicitly moved as part of a separately reviewed migration.

## Private HTTP contract

All responses use `Cache-Control: no-store`. Session UUIDs and legacy lowercase
IDs are accepted. Lifecycle calls are serialized per session. Supply the hub's
monotonic integer `generation` on lifecycle and send requests; obsolete commands
and sends are rejected. Automatic reconnection preserves command generation.

| Request | Behavior |
| --- | --- |
| `GET /health` | Authenticated process, startup-sync and SQL readiness |
| `POST /sessions/{id}/start` | `{mode:"qr"|"pairing"|"restore",phone?,generation}`; QR default |
| `GET /sessions/{id}` | Snapshot; legacy `status` plus canonical `canonical_status` |
| `POST /sessions/{id}/stop` | `{generation}`; pause and retain credentials |
| `POST /sessions/{id}/logout` | `{generation}`; stop, clear auth, report `logout_confirmed` separately |
| `DELETE /sessions/{id}` | `{generation}`; stop, clear auth, retain fencing tombstone |
| `GET /sessions/{id}/auth/qr` | Snapshot with current QR, challenge ID, expiry |
| `POST /sessions/{id}/auth/pairing-code` | `{phone,generation}`; request code when socket is ready |
| `POST /sessions/{id}/messages` | Native text/image/document/voice send |
| `GET /sessions/{id}/messages/{key}` | Durable transport result for idempotency key |
| `GET /events?after=0` | Pending durable events and `next_cursor` |
| `DELETE /events/{eventId}` | Acknowledge hub's committed ingestion; idempotent |

Snapshots include `desired_state`, `generation`, `revision`, `observed_at`,
`canonical_status` (`STOPPED`, `STARTING`, `SCAN_QR_CODE`, `WORKING`, `FAILED`),
`auth_method`, `phone_number`, `challenge_id`, `expires_at`, `qr`, `pairing_code`.
`phone_number` is the authenticated identity; the legacy `phone` field may be the
number typed for pairing. QR/pairing secrets never enter the event spool.

Messages take `{type,phone,idempotency_key,generation,text?}`. For media, add
`media_base64`, `mimetype`, `filename?`, and `caption?`. Decoded bytes are capped
at 16 MiB. Images support JPEG/PNG. Voice must already be OGG/Opus; the hub handles
bounded FFmpeg conversion before calling the runner. URL-based media is rejected.
A send never starts an inactive account. Results preserve the internal v1 shape:
`{ok,status:"sent"|"unknown"|"failed",id,retryable,error_code?}`. `sent` means
transport acceptance, not recipient delivery. Ambiguous dispatch is journaled as
unknown and never retried automatically. Later completion or ACK may resolve it.

Legacy `POST /sessions`, `POST /sessions/{id}/send`, and number lookup remain
available during the v1 migration window. Their bearer token is still mandatory.

## Event handoff

The hub must commit ingestion before deleting an event. Pending events remain on
disk across restarts. IDs are UUID strings; cursors are increasing, zero-padded
20-digit decimal strings. Hub polling from `after=0` drains all unacknowledged
records safely. Batches are limited to 100 records and approximately 24 MiB.
Ready events can pass an attachment waiting for download. `next_cursor` never
advances past that pending attachment; poll from `after=0` and explicitly ACK
each committed event. An attachment that is still pending cannot be ACKed.

```json
{
  "events": [{
    "id": "uuid", "cursor": "00000000000000000001",
    "event": "message.ack", "session_id": "runner-id",
    "generation": 3, "revision": 8, "occurred_at": "ISO-8601",
    "data": {"message_id": "provider-id", "idempotency_key": "hub-message-uuid", "ack": "read", "status": 4}
  }],
  "next_cursor": "00000000000000000001"
}
```

Only `message`, `message.ack`, and `session.status` are emitted. ACKs expose
`accepted`, `delivered`, `read`, or `played`. Direct inbound text/image/document/
voice messages are normalized; groups and other advanced features are excluded.
Both live `notify` and offline `append` arrivals are consumed. Bulk history sync,
outgoing message copies, and protocol-only events are excluded. Stable provider
message identities and each ACK stage have durable dedup receipts, including
after reconnect, runner restart, and hub acknowledgement. Receipts retain only
event IDs/cursors, not message contents, and live with the persistent event store.
Media events are durably staged before download, then include `media_base64`,
`mimetype`, and `filename` for private hub ingestion. Transient download failures
retain the raw envelope with durable exponential backoff (5 seconds to 15 minutes).
Each poll attempts at most one attachment, with a 15-second abortable deadline;
other ready events continue through even when more than a page of attachments is
pending. Oversized media or download failures exceeding 24 hours emit
`media_error`; pending media never prevents another session's events. Consumers
still deduplicate provider messages and event IDs because delivery is at least
once.

## Verification

```bash
npm test
```

The suite uses fake sockets and a transactional SQL test double, covering durable
pause/restart, authoritative startup grants, races, QR expiry, distinct FAILED
status, uncertain teardown/send outcomes, idempotency, native media, event replay,
late ACK reconciliation, encrypted row ownership, auth transaction rollback, and
legacy auth import. An opt-in real MySQL test creates and removes its own fresh
database and verifies encrypted Buffer roundtrips, transaction rollback, import,
and logout fencing:

```bash
WAG_BAILEYS_TEST_MYSQL_SOCKET=/path/to/disposable-mysql.sock node --test sql-auth.mysql.test.mjs
```

This test was also run against the task's isolated MySQL instance. Actual WhatsApp
connection/media tests remain deployment acceptance checks; the suite does not
contact real accounts.
