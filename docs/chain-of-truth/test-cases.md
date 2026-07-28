# Test Cases — WhatsApp Gateway Hub MVP

Status: Reviewed

| ID | Type | Traces to | Preconditions | Steps/input | Expected result | Priority |
|---|---|---|---|---|---|---|
| TC-001 | API | FR-001/UC-001 | active app+token | POST valid | authenticated app derived from token | P0 |
| TC-002 | API | FR-001/UC-001 | invalid/revoked token or inactive app | POST | 401, zero side effects | P0 |
| TC-003 | API | FR-001/BR-002 | token lacks ability/payload injects app/provider | POST | 403/422; identity/routing cannot be forged | P0 |
| TC-004 | Unit | FR-002 | normalizer | dataset `08`, `8`, `+62`, `0062`, formatting | same canonical `62...` | P0 |
| TC-005 | Unit/API | FR-002 | normalizer | empty, alpha, short/long, multi-target, raw JID | rejected | P0 |
| TC-006 | Unit | FR-002 | configured bounds | min/max valid phones | inclusive validation | P1 |
| TC-007 | API | FR-003/BR-001 | no prior message | missing/long key then valid request | 422 then exactly one message | P0 |
| TC-008 | API | FR-003/BR-001 | existing same app/key/fingerprint | replay across statuses | same UUID, no new job/attempt/call | P0 |
| TC-009 | API/DB | FR-003/BR-001 | existing key | different payload and separate-app same key | 409 for same app; allowed for other app; unique race guard | P0 |
| TC-010 | Integration | FR-004/AC-001 | WAHA primary | valid explicit accepted response | 201 provider_accepted after persisted attempt | P0 |
| TC-011 | Integration | FR-004/BR-005 | Fonnte primary | status true response | provider_accepted, never delivered | P0 |
| TC-012 | Integration | FR-004 | route valid | all definitive provider failures | typed 503 + failed ledger | P0 |
| TC-013 | Integration | FR-004/BR-004 | provider call | ambiguous timeout/malformed response | outcome_unknown, no fallback | P0 |
| TC-014 | API | FR-004 | sync message accepted | inspect response | no secret/raw response/body exposed | P0 |
| TC-015 | API | FR-005/AC-007 | queue fake | valid async POST | 202, committed queued message/event, one job | P0 |
| TC-016 | Integration | FR-005 | transaction commit/rollback | dispatch job | afterCommit only, no orphan on rollback | P0 |
| TC-017 | Integration | FR-005/AC-008 | queued message | run worker/job twice | one provider dispatch; terminal rerun no-op | P0 |
| TC-018 | Integration | FR-006 | primary accepts | dispatch | one attempt; fallback untouched | P0 |
| TC-019 | Integration | FR-006/AC-002 | safe primary provider failure | dispatch | secondary attempted and accepted | P0 |
| TC-020 | Integration | FR-006 | invalid recipient rejection | dispatch | fallback next NotSent step; fail only after all steps reject | P0 |
| TC-021 | Integration | FR-006 | disabled/circuit-open step | dispatch | skip event and next healthy step | P1 |
| TC-022 | Unit | FR-007/BR-005 | WAHA classifier | 2xx valid JSON | accepted and remote ID when present | P0 |
| TC-023 | Unit | FR-007/BR-005 | Fonnte classifier | 2xx status true/id/process | accepted metadata | P0 |
| TC-024 | Unit | FR-007/BR-005 | Fonnte classifier | false variants | rejected/provider error, not accepted | P0 |
| TC-025 | Unit | FR-007/BR-004 | either driver | empty/malformed/indeterminate 2xx | outcome_unknown | P0 |
| TC-026 | Unit | FR-007/BR-004 | connection exception | cURL connect refusal | provider_failed + fallback allowed | P0 |
| TC-027 | Unit | FR-007/BR-004 | connection exception | timeout/reset after possible write | outcome_unknown + reconcile only | P0 |
| TC-028 | Unit | FR-007 | HTTP errors | invalid payload vs provider auth/rate failure | message rejection and account failure may fallback when NotSent | P0 |
| TC-029 | Contract | FR-007 | fake HTTP | inspect WAHA/Fonnte/GOWA/WABA requests | correct endpoint/header/payload; no cross-driver leak | P0 |
| TC-030 | Integration | FR-008 | success dispatch | inspect DB | message, attempt, events consistent | P0 |
| TC-031 | Integration | FR-008 | fallback dispatch | inspect DB | ordered attempts, HTTP status, latency, disposition retained | P0 |
| TC-032 | Security | FR-008/NFR-001 | request completes | inspect raw DB/log/API | token/secret/body/phone/raw response not leaked | P0 |
| TC-033 | Unit/Integration | FR-009 | state machine | legal transitions | accepted | P0 |
| TC-034 | Unit/Integration | FR-009 | state machine | backward/second terminal transition | rejected; event not duplicated | P0 |
| TC-035 | Integration | FR-009 | safe fallback | dispatch | fallback is event, not primary status | P1 |
| TC-036 | Integration | FR-009 | no usable route | dispatch | failed + dead_lettered_at once, no loop | P0 |
| TC-037 | API/Integration | FR-010/BR-006 | expired or expires between attempts | dispatch | no/next provider call; status expired | P0 |
| TC-038 | Feature | FR-011/NFR-001 | guest/non-admin/inactive/admin | visit `/admin` | login/403/allow as appropriate | P0 |
| TC-039 | Feature/DB | FR-011 | active admin | create/rotate API credential | plaintext once; hash only raw DB | P0 |
| TC-040 | Feature/DB | FR-011 | active admin | create/update provider | encrypted config; secret never redisplayed | P0 |
| TC-041 | Feature | FR-011 | active admin | create policy/reorder duplicate steps | valid order saved; invalid duplicates rejected | P1 |
| TC-042 | Feature | FR-012/AC-015 | messages/attempts exist | list/filter/view | masked list + correct timeline | P1 |
| TC-043 | Feature/Integration | FR-012/AC-016 | failed nonexpired message | confirm retry | one queued job/event; old attempts retained | P0 |
| TC-044 | Feature | FR-012/AC-017 | queued/accepted/expired/outcome_unknown | inspect/attempt retry | action unavailable/rejected | P0 |
| TC-045 | API | FR-013 | owner token | GET own UUID | current safe status envelope | P0 |
| TC-046 | API | FR-013 | different-app token | GET UUID | 404/403 without data leak | P0 |
| TC-047 | API | FR-013 | read ability missing | GET own UUID | 403 | P0 |
| TC-048 | Pilot unit | FR-014 | web-shelf adapter | Hub 202 queued | `send()` true | P0 |
| TC-049 | Pilot unit | FR-014 | web-shelf adapter | Hub validation/auth/failed response | `send()` false + safe log | P0 |
| TC-050 | Pilot unit | FR-014/FR-003 | caller supplies stable business-event context | retry the same reminder after ambiguous transport failure | same keyed request identity, Idempotency-Key, client reference, and canonical body | P0 |
| TC-051 | Pilot security | FR-014/NFR-001 | migrated config | inspect code/env contract | no provider credential required by Shelf adapter | P0 |

## Coverage summary

Every FR-001–FR-014, UC-001–UC-004, API-001/API-002/JOB-001/INT-001/UI-001–UI-005, dan AC-001–AC-017 has at least one test case. Provider delivery/read webhook and production HA remain excluded as documented scope.
