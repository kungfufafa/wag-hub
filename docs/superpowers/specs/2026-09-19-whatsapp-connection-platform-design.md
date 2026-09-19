# WhatsApp Connection Platform

WAG Hub's application-facing model is **App → WhatsApp Connection → Message**.

Internal engine, provider, session, and routing resources remain the delivery
implementation. Connections orchestrate them transactionally.

## Connection

A `whatsapp_connections` row is the stable public resource:

- `managed_number` — pinned sender, no silent fallback
- `provider_route` — default delivery strategy; fallback is optional later

Public fields: id, application, name, type, status, capabilities, sender
identity, health summary. Credentials are never returned.

States: `setup_required`, `connecting`, `ready`, `degraded`, `disconnected`,
`error`. Each non-ready state has a recommended next action.

Capabilities are driver-derived, not provider-name branches.

## Provisioning

`ConnectionProvisioner` creates or resumes the internal session/account,
message policy, and optional number-check policy. Repeating the same app+name
is idempotent. Engine failure leaves a recoverable `setup_required` row.

Existing sessions and default routes are projected by
`ConnectionSynchronizer`.

## Send path

Connection-oriented `POST /api/v1/messages` (omit `purpose`/`mode`/`route_key`,
or pass `connection_id`) resolves the default or named connection.

Pinned `whatsapp_connection_id` + `pinned_provider_account_id` keep managed
sends on that number. Routed sends use the connection policy, including
configured fallback.

Legacy payloads keep existing error codes and gain `error.action`.

## UI

Primary: Koneksi WhatsApp and Hubungkan WhatsApp.
Advanced: Akun Provider, Aturan Rute, Perangkat WhatsApp.
