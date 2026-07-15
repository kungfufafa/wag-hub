# User Flow Registry

Status: Reviewed  
Derived from: SRS 0.1.0

| Use case | Name | Actor | File | Requirements | Status |
|---|---|---|---|---|---|
| UC-001 | Send text synchronously | ACT-001 | `./uc-001-send-sync-message.md` | FR-001–FR-004, FR-006–FR-010, FR-013 | Reviewed |
| UC-002 | Queue and process text asynchronously | ACT-001, ACT-003 | `./uc-002-send-async-message.md` | FR-001–FR-003, FR-005–FR-010, FR-013 | Reviewed |
| UC-003 | Configure applications, providers, and routes | ACT-002 | `./uc-003-configure-gateway.md` | FR-011 | Reviewed |
| UC-004 | Monitor and safely retry messages | ACT-002 | `./uc-004-monitor-retry-message.md` | FR-008, FR-009, FR-012 | Reviewed |

## Requirement coverage

| Requirement | User flows | Coverage/conflict |
|---|---|---|
| FR-001–FR-004 | UC-001 | Covered |
| FR-005 | UC-002 | Covered |
| FR-006–FR-010 | UC-001, UC-002 | Covered |
| FR-011 | UC-003 | Covered |
| FR-012 | UC-004 | Covered |
| FR-013 | UC-001, UC-002 | Covered |
| FR-014 | UC-001, UC-002 | Pilot adapter; client-side flow tracked in migration tests |

## Page coverage and dependencies

- UC-001/UC-002 use MOD-001 and MOD-002; no human page.
- UC-003 uses PAGE-001–PAGE-004.
- UC-004 uses PAGE-001, PAGE-005, dan PAGE-006 and depends on message/attempt data created by UC-001/UC-002.
