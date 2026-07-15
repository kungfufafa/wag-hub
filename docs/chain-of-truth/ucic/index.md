# UCIC Registry

Status: Reviewed  
Derived from: User Flows 0.1.0 and Data Model 0.1.0

| Use case | Name | File | Interfaces | Entities | Status |
|---|---|---|---|---|---|
| UC-001 | Send text synchronously | `./uc-001-send-sync-message.md` | API-001, API-002, INT-001 | ENT-001–ENT-008 | Reviewed |
| UC-002 | Queue and process asynchronously | `./uc-002-send-async-message.md` | API-001, API-002, JOB-001, INT-001 | ENT-001–ENT-008 | Reviewed |
| UC-003 | Configure gateway | `./uc-003-configure-gateway.md` | UI-001–UI-004 | ENT-001–ENT-005 | Reviewed |
| UC-004 | Monitor and retry | `./uc-004-monitor-retry-message.md` | UI-005, JOB-001 | ENT-006–ENT-008 | Reviewed |

## Common interface conventions

- Base API `/api/v1`; JSON request/response; HTTPS required outside local test.
- Auth: Bearer token; database stores SHA-256 only. Client identity always comes from token.
- `Idempotency-Key` required, 1–160 characters; `X-Correlation-ID` optional and generated when absent.
- Error envelope: `{ "message": "...", "error": { "code": "...", "retryable": false }, "request_id": "..." }`.
- Request never contains provider/account ID, provider credential, atau callback URL.
- On client timeout, caller repeats the exact request with the same idempotency key.
- Provider request timeout default 15 seconds; ambiguous outcome stops automatic routing.

## Coverage and revision notes

Semua flow yang diimplementasikan memiliki UCIC. Inbound provider/webhook ditunda dan tidak memiliki contract pada baseline ini.
