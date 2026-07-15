# UC-004 — Monitor and safely retry messages

Status: Reviewed  
Derived from: FR-008, FR-009, FR-012; BR-004, BR-007, BR-008

## Intent

- Actor: ACT-002 Administrator gateway
- Goal: Mengetahui apa yang terjadi pada setiap pesan dan memulihkan definitive failure secara aman.
- Trigger: Admin membuka message log atau alert kegagalan.
- Preconditions: User admin aktif; message ledger tersedia.
- Postconditions: Status/attempt dipahami; retry aman membuat attempt baru tanpa merusak history.
- Related pages: PAGE-001, PAGE-005, PAGE-006

## Main flow

| Step | Actor/system action | Data read/written | Rule/requirement |
|---|---|---|---|
| 1 | Admin memfilter message berdasarkan app/status/provider/tanggal | Message | FR-012 |
| 2 | Admin membuka detail dan membaca status serta attempt timeline tersanitasi | Message/Attempts/Events | FR-008, BR-008 |
| 3 | Pada terminal definitive failure, admin memilih Retry dan mengonfirmasi | Message | FR-012 |
| 4 | Hub memvalidasi expiry/status, mengubah message ke queued, dan dispatch job baru | Message/Job/Event | BR-007 |
| 5 | Attempt baru ditambahkan; history lama tetap ada | Attempts | FR-008 |

## Alternative flows

1. Admin hanya melakukan diagnosis tanpa retry.
2. Admin menonaktifkan provider bermasalah lalu retry agar policy memakai step aktif berikutnya.

## Exception flows

- Message accepted/processing/queued: retry ditolak.
- Message expired: retry ditolak; caller harus membuat message baru dengan idempotency key baru.
- Message outcome_unknown: retry default ditolak karena risiko duplicate; override tidak termasuk MVP.
- Provider response rahasia/raw credential tidak terlihat di UI.

## Acceptance criteria

| AC ID | Given | When | Then | Related requirement |
|---|---|---|---|---|
| AC-015 | Message failed | Admin membuka detail | Timeline menunjukkan setiap attempt dan error aman | FR-008, FR-012 |
| AC-016 | Message failed dan belum expired | Admin retry | Job baru dibuat, attempt lama tetap ada | FR-012 |
| AC-017 | Message outcome_unknown/expired/accepted | Admin mencoba retry | Action tidak tersedia atau ditolak | BR-004, FR-012 |

## Evidence and validation

Flow mengatasi ketiadaan durable ledger dan risiko retry ganda yang ditemukan pada audit. Status Reviewed.
