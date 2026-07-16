# Traceability Matrix — WhatsApp Gateway Hub MVP

Status: Reviewed  
Baseline/version: 0.1.0

| Requirement | Use case | Page/component | Entity | UCIC/API | Implementation evidence | Test case | Coverage/status |
|---|---|---|---|---|---|---|---|
| FR-001 | UC-001/002 | MOD-001/002 | ENT-001/002 | API-001/002 | `AuthenticateApiCredential` + ability middleware | TC-001–003 | Implemented; Pass |
| FR-002 | UC-001/002 | MOD-001 | ENT-006 | API-001 | `PhoneNormalizer` + request validation | TC-004–006 | Implemented; Pass |
| FR-003 | UC-001/002 | MOD-001 | ENT-006 | API-001 | keyed payload hash + unique intake transaction | TC-007–009 | Implemented; Pass |
| FR-004 | UC-001 | MOD-001 | ENT-006–008 | API-001/INT-001 | synchronous `GatewayMessageDispatcher` | TC-010–014 | Implemented; Pass |
| FR-005 | UC-002 | MOD-001 | ENT-006/008 | API-001/JOB-001 | `GatewayMessageEnqueuer` + queue job + recovery | TC-015–017 | Implemented; Pass |
| FR-006 | UC-001/002 | MOD-001 | ENT-003–007 | INT-001 | deterministic routing, fallback, circuit breaker | TC-018–021 | Implemented; Pass |
| FR-007 | UC-001/002 | — | ENT-003/007 | INT-001 | WAHA/Fonnte/GOWA/WABA drivers, classifiers, endpoint guard | TC-022–029 | Implemented; Pass |
| FR-008 | UC-001/002/004 | PAGE-006 | ENT-006–008 | INT-001/UI-005 | encrypted attempt/event ledger | TC-030–032 | Implemented; Pass |
| FR-009 | UC-001/002/004 | PAGE-005/006 | ENT-006–008 | JOB-001/UI-005 | dispatcher + stale/queue recovery transitions | TC-033–036 | Implemented; Pass |
| FR-010 | UC-001/002 | MOD-001 | ENT-006 | API-001/JOB-001 | expiry guards before/within route | TC-037 | Implemented; Pass |
| FR-011 | UC-003 | PAGE-001–004 | ENT-001–005 | UI-001–004 | Filament resources and secure admin bootstrap | TC-038–041 | Implemented; Pass |
| FR-012 | UC-004 | PAGE-005/006 | ENT-006–008 | UI-005 | masked ledger + guarded retry action | TC-042–044 | Implemented; Pass |
| FR-013 | UC-001/002 | MOD-002 | ENT-006–008 | API-002 | app-scoped status controller | TC-045–047 | Implemented; Pass |
| FR-014 | UC-001/002 | web-shelf adapter | ENT-001/002/006 | API-001 | Shelf Hub adapter + stable reminder identity | TC-048–051 | Implemented; Pass |

## Non-functional traceability

| Requirement | Control/design | Verification | Evidence/result |
|---|---|---|---|
| NFR-001 | hashed client token, encrypted casts, admin gate, endpoint allowlist | TC-001–003, 032, 038–040, 051 | Pass; dependency audits clean |
| NFR-002 | unique app+key + keyed payload fingerprint | TC-007–009 | Pass |
| NFR-003 | local response target and attempt latency | TC-015, TC-031, deployment benchmark | Functional pass; production p95 pending |
| NFR-004 | masking + retention rules | TC-032, TC-042, code review | Masking/encryption pass; automated pruning pending |
| NFR-005 | correlation/attempt/event ledger | TC-030–036 | Pass |
| NFR-006 | driver interface | TC-022–029, architecture review | Pass |
| NFR-007 | PHPUnit coverage | full suite coverage | 201 tests pass; line percentage unavailable without Xdebug/PCOV |

## Coverage summary

Forward and implementation traceability cover 14/14 functional requirements. All functional test groups are GREEN. NFR performance benchmark, automated retention, live-provider smoke, dan numerical line coverage remain deployment/CI evidence rather than missing functional implementation.
