# UC-001 — Send text synchronously

Status: Reviewed  
Derived from: FR-001–FR-004, FR-006–FR-010, FR-013; BR-001–BR-008

## Intent

- Actor: ACT-001 Aplikasi sumber
- Goal: Mengetahui dalam request yang sama apakah provider secara eksplisit menerima pesan kritis.
- Trigger: Client mengirim `POST /api/v1/messages` dengan `mode=sync`.
- Preconditions: Client dan token aktif; minimal satu routing policy dengan provider step aktif.
- Postconditions: Message dan semua attempt tersimpan; response menunjukkan accepted, failed, outcome_unknown, atau expired.
- Related pages: MOD-001, MOD-002

## Main flow

| Step | Actor/system action | Data read/written | Rule/requirement |
|---|---|---|---|
| 1 | Client mengirim Bearer token, Idempotency-Key, recipient, text, purpose/route, dan optional expires_at | Request | FR-001–FR-004 |
| 2 | Hub mengautentikasi client, memvalidasi payload, menormalisasi recipient, dan menghitung payload fingerprint | ApplicationCredential | FR-001–FR-003 |
| 3 | Hub membuat Message secara atomik atau mengembalikan replay yang identik | Message | BR-001 |
| 4 | Hub memilih policy dan ordered active steps tanpa menerima provider ID dari client | RoutingPolicy/Step | FR-006, BR-002 |
| 5 | Hub membuat attempt lalu memanggil driver provider pertama | MessageAttempt | FR-007–FR-008 |
| 6 | Provider menerima eksplisit; Hub menandai attempt dan message `provider_accepted` | Message/Attempt/Event | FR-004, FR-009 |
| 7 | Hub mengembalikan 201 dengan message ID, status, provider label, dan `duplicate=false` | Response | BR-003, BR-008 |

## Alternative flows

1. **Idempotent replay:** key + fingerprint sama mengembalikan message yang sama dengan `duplicate=true` tanpa provider call baru.
2. **Definitive provider failure:** attempt disimpan gagal; Hub mencoba step berikutnya jika tersedia dan message belum kedaluwarsa.
3. **Global/default route:** bila route khusus aplikasi tidak ada, Hub memakai default aplikasi lalu default sistem.

## Exception flows

- Token invalid/nonaktif: 401/403 dan tidak membuat Message.
- Payload/recipient invalid atau Idempotency-Key tidak ada: 422 dan tidak memanggil provider.
- Key sama dengan fingerprint berbeda: 409 `idempotency_conflict`.
- Message sudah kedaluwarsa sebelum call: status `expired`, tanpa attempt provider.
- Semua step mengalami definitive failure: status `failed`/`dead_letter`, 503.
- Timeout/malformed response/hasil tidak pasti: status `outcome_unknown`, tidak fallback otomatis, response 502 dengan code `provider_outcome_unknown`.
- Tidak ada route/provider aktif: status `failed`, 503 `route_unavailable`.

## Acceptance criteria

| AC ID | Given | When | Then | Related requirement |
|---|---|---|---|---|
| AC-001 | Token dan route valid | Provider pertama menerima | 201 `provider_accepted`, message + attempt tersimpan | FR-004, FR-008 |
| AC-002 | Provider pertama menolak secara definitif | Provider kedua menerima | 201 accepted dan dua attempt berurutan tersimpan | FR-006–FR-008 |
| AC-003 | Provider pertama timeout setelah request dimulai | Hub mengklasifikasi outcome | Message `outcome_unknown`, tidak ada attempt provider kedua | FR-007, FR-009 |
| AC-004 | Key yang sama dan payload sama sudah ada | Request diulang | Message lama dikembalikan tanpa send ulang | FR-003 |
| AC-005 | Key yang sama memiliki payload berbeda | Request diulang | 409 tanpa send ulang | FR-003 |
| AC-006 | expires_at sudah lewat | Request diproses | Message expired dan provider tidak dipanggil | FR-010 |

## Evidence and validation

Semantik sinkron berasal dari alur OTP SAM/Helpdesk yang diperiksa 2026-07-15. Flow ditinjau oleh AI; implementasi diizinkan stakeholder melalui persetujuan ringkasan MVP.
