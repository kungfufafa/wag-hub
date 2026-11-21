# Compatibility guarantees

WAG Hub keeps existing integrations working while the application-facing
model is **App → WhatsApp Connection → Message**.

## Stable contracts

These remain supported without a breaking version bump:

- `POST /api/v1/messages` and `GET /api/v1/messages/{uuid}`
- `POST /api/v1/attachments` and `GET /api/v1/attachments/{uuid}`
- `POST /api/v1/number-checks`
- `/api/v1/engine/*`, `/engine/*`, and `/engine/t/{token}/*`
- Header `Idempotency-Key`, hashed application tokens, encrypted secrets
- Message statuses including `outcome_unknown`
- No blind fallback after a request may already have reached a provider

`/api/v1/*` and `/engine/*` are legacy surfaces: their contracts do not
change, and they answer `410` once retired. `POST /api/v1/messages` keeps its
original request (`purpose`, `mode`, `route_key`) and response; it has no
connection fields.

## Connection API (v2)

The application-facing model lives in the v2 API with the v2 conventions
(`client.auth`, `client.rate:engine`, v2 abilities, `{"data": ...}` envelope,
`{"message", "error": {"code", "retryable"}}` errors):

- `GET /api/v2/connections` (`sessions:read`)
- `POST /api/v2/connections` (`sessions:manage`, `Idempotency-Key` required, 201)
- `GET /api/v2/connections/{id}` (`sessions:read`)
- `POST /api/v2/connections/{id}/connect` and `/retry` (`sessions:manage`)
- `POST /api/v2/connections/{id}/messages` (`messages:send`, `Idempotency-Key` required)

```http
POST /api/v2/connections/{id}/messages
Authorization: Bearer <WAG_TOKEN>
Idempotency-Key: order-1
```

```json
{"to": "6281234567890", "type": "text", "text": "Halo"}
```

A managed number is a v2 engine session: it counts against v2 capacity
(`capacity_exceeded`), is listed by `GET /api/v2/sessions`, and its sends are
`POST /api/v2/sessions/{session}/messages` semantics (202, same idempotency
and media rules). Engine and route outages are reported as
`connection_not_ready` with `retryable: true` and the cause in
`error.internal_code`; other codes match `/api/v2/sessions`.

The `/api/v1/connections` routes of the unreleased preview were removed.

## Delivery semantics

- A **managed_number** send is pinned to that number. It never switches
  sender because another provider is healthier.
- A **provider_route** send uses the connection's routing policy, including
  any fallback the operator later configures. It is dispatched synchronously:
  201 once a provider accepted it.
- An `Idempotency-Key` replay returns the original message before any
  readiness check, so a retry after an outage never sends twice.
- Switching providers on a routed connection does not require application
  code changes.

## Internal terms

Engine driver, provider step, routing policy, route key, and purpose stay
available in the panel and advanced APIs. They are not required for the
first-run path.
