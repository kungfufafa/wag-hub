# Traceability Matrix — WhatsApp Gateway Hub MVP

Status: Reviewed  
Baseline/version: 0.1.0

| Requirement | Use case | Page/component | Entity | UCIC/API | Implementation evidence | Test case | Coverage/status |
|---|---|---|---|---|---|---|---|
| FR-001 | UC-001/002 | MOD-001/002 | ENT-001/002 | API-001/002 | API auth middleware (planned) | TC-001–003 | Test designed |
| FR-002 | UC-001/002 | MOD-001 | ENT-006 | API-001 | PhoneNormalizer (planned) | TC-004–006 | Test designed |
| FR-003 | UC-001/002 | MOD-001 | ENT-006 | API-001 | Message intake transaction (planned) | TC-007–009 | Test designed |
| FR-004 | UC-001 | MOD-001 | ENT-006–008 | API-001/INT-001 | Sync dispatcher (planned) | TC-010–014 | Test designed |
| FR-005 | UC-002 | MOD-001 | ENT-006/008 | API-001/JOB-001 | Queue job (planned) | TC-015–017 | Test designed |
| FR-006 | UC-001/002 | MOD-001 | ENT-003–007 | INT-001 | Routing engine (planned) | TC-018–021 | Test designed |
| FR-007 | UC-001/002 | — | ENT-003/007 | INT-001 | WAHA/Fonnte drivers (planned) | TC-022–029 | Test designed |
| FR-008 | UC-001/002/004 | PAGE-006 | ENT-006–008 | INT-001/UI-005 | Attempt/event ledger (planned) | TC-030–032 | Test designed |
| FR-009 | UC-001/002/004 | PAGE-005/006 | ENT-006–008 | JOB-001/UI-005 | Message transitions (planned) | TC-033–036 | Test designed |
| FR-010 | UC-001/002 | MOD-001 | ENT-006 | API-001/JOB-001 | Expiry guard (planned) | TC-037 | Test designed |
| FR-011 | UC-003 | PAGE-001–004 | ENT-001–005 | UI-001–004 | Filament resources (planned) | TC-038–041 | Test designed |
| FR-012 | UC-004 | PAGE-005/006 | ENT-006–008 | UI-005 | Message resource retry (planned) | TC-042–044 | Test designed |
| FR-013 | UC-001/002 | MOD-002 | ENT-006–008 | API-002 | Status controller (planned) | TC-045–047 | Test designed |
| FR-014 | UC-001/002 | web-shelf adapter | ENT-001/002/006 | API-001 | Shelf central adapter (planned) | TC-048–051 | Test designed |

## Non-functional traceability

| Requirement | Control/design | Verification | Evidence/result |
|---|---|---|---|
| NFR-001 | hashed client token, encrypted casts, admin gate | TC-001–003, 032, 038–040, 051 | Pending |
| NFR-002 | unique app+key + payload fingerprint | TC-007–009 | Pending |
| NFR-003 | local response target and attempt latency | TC-015, TC-031, deployment benchmark | Pending |
| NFR-004 | masking + retention rules | TC-032, TC-042, code review | Pending |
| NFR-005 | correlation/attempt/event ledger | TC-030–036 | Pending |
| NFR-006 | driver interface | TC-022–029, architecture review | Pending |
| NFR-007 | PHPUnit coverage | full suite coverage | Pending; local coverage extension unavailable |

## Coverage summary

Forward design coverage is 14/14 functional requirements and 7/7 NFRs with at least one UC/control and planned test (100%, evidence date 2026-07-15). Implementation/execution coverage is 0/14 until GREEN evidence is recorded.
