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

Legacy fields `purpose`, `mode`, and `route_key` still work. If they are
present, Hub uses the existing routing engine.

## Additive application-facing contract

New clients can omit `purpose`, `mode`, and `route_key` and send through a
`connection_id` or the application's default connection:

```http
POST /api/v1/messages
Authorization: Bearer <WAG_TOKEN>
Idempotency-Key: order-1
```

```json
{
  "recipient": {"type": "phone", "value": "081234567890"},
  "message": {"type": "text", "text": "Halo"}
}
```

New resources:

- `GET/POST /api/v1/connections`
- `GET /api/v1/connections/{id}`
- `POST /api/v1/connections/{id}/connect`
- `POST /api/v1/connections/{id}/retry`
- `POST /api/v1/connections/{id}/messages`

Connection-oriented errors use actionable codes such as
`connection_not_ready` and `connection_not_found`, and keep
`error.internal_code` plus `error.audit_id`. Legacy payloads keep their
original `error.code` and gain `error.action`.

## Delivery semantics

- A **managed_number** send is pinned to that number. It never switches
  sender because another provider is healthier.
- A **provider_route** send uses the connection's routing policy, including
  any fallback the operator later configures.
- Switching providers on a routed connection does not require application
  code changes.

## Internal terms

Engine driver, provider step, routing policy, route key, and purpose stay
available in the panel and advanced APIs. They are not required for the
first-run path.
