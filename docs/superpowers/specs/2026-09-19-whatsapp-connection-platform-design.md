# WhatsApp Connection Platform

WAG Hub's application-facing model is **App → WhatsApp Connection → Message**.

Internal engine, provider, session, and routing resources remain the delivery
implementation. Connections orchestrate them transactionally.

## Connection

A `whatsapp_connections` row is the stable public resource:

- `managed_number` — exactly one v2 `EngineSession` (`engine_session_id`,
  unique); pinned sender, no silent fallback
- `provider_route` — tenant-owned provider account behind a dedicated routing
  policy; fallback is optional later

Public fields: id, application, name, type, status, capabilities, sender
identity, health summary. Credentials are never returned.

States: `setup_required`, `connecting`, `ready`, `degraded`, `disconnected`,
`error`. Each non-ready state has a recommended next action.

Capabilities are driver-derived, not provider-name branches.

## Provisioning

The Connection API lives at `/api/v2/connections` (v2 auth, rate limit,
abilities, envelope and errors). `ConnectionProvisioner` is idempotent per
`Idempotency-Key` (`creation_key` / `creation_hash` on the row):

- `managed_number` calls `SessionService::create` with the request key and
  `start: true`, so capacity (`capacity_exceeded`), idempotency
  (`idempotency_conflict`) and the start operation match `POST /api/v2/sessions`.
  No legacy native session, provider account or route is created. Connect and
  retry issue `SessionService::command('start')`; a removed session is replaced
  by a new one.
- `provider_route` validates tenant settings (`ProviderEndpointGuard` public
  hosts only) and creates its own account and single-step policy. A failed
  validation leaves a recoverable row; replaying the request or `retry`
  completes it. Administrator-managed accounts are never rewritten.

Status, QR and pairing codes of managed numbers come from the session
(`SessionService::snapshot` / `challenge`); a managed number is `ready` only
while its session is `WORKING`, fresh and idle.

`ConnectionSynchronizer` (`gateway:sync-connections`, and every list call)
projects each live `EngineSession` — including sessions created by
`engine:migrate-legacy`, which re-link the pre-v2 connection of that native
account — and the default routing policy into connections.

## Send path

`POST /api/v2/connections/{id}/messages` (`ConnectionMessenger`):

- managed: `EngineTransportService::accept` on the connection's session (202).
- provider route: a `GatewayMessage` on the connection's routing policy,
  dispatched synchronously by `GatewayMessageDispatcher` (201 when accepted).

Idempotency-Key replay is answered before readiness checks. Outages are
`connection_not_ready` (retryable) with the engine cause in
`error.internal_code`. The legacy `/api/v1/messages` contract is unchanged.

## UI

Primary: Koneksi WhatsApp and Hubungkan WhatsApp.
Advanced: Akun Provider, Aturan Rute, Perangkat WhatsApp.
