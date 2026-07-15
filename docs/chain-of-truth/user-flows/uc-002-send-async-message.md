# UC-002 — Queue and process text asynchronously

Status: Reviewed  
Derived from: FR-001–FR-003, FR-005–FR-010, FR-013; BR-001–BR-008

## Intent

- Actor: ACT-001 Aplikasi sumber, ACT-003 Queue worker
- Goal: Menyimpan notifikasi secara cepat dan durabel tanpa memblokir request aplikasi pada latency provider.
- Trigger: Client mengirim message dengan `mode=async`.
- Preconditions: Token aktif dan database queue tersedia.
- Postconditions: Client memperoleh `queued`; worker kemudian menghasilkan terminal/accepted status dan attempt ledger.
- Related pages: MOD-001, MOD-002

## Main flow

| Step | Actor/system action | Data read/written | Rule/requirement |
|---|---|---|---|
| 1 | Client mengirim request async yang valid | Request | FR-001–FR-003, FR-005 |
| 2 | Hub membuat Message `queued` dalam transaksi dan menjadwalkan job setelah commit | Message/Job | FR-005 |
| 3 | Hub mengembalikan 202 dan message ID | Response | BR-003 |
| 4 | Worker mengunci Message yang masih layak, mengubah ke `processing`, dan memilih route | Message/Route | FR-006, FR-009 |
| 5 | Worker melakukan ordered attempts dengan aturan definitive vs outcome_unknown | Attempts | FR-007–FR-010 |
| 6 | Worker menyimpan final status; client dapat membaca status kemudian | Message/Event | FR-008, FR-009, FR-013 |

## Alternative flows

1. Replay idempotent sebelum/selama/sesudah job mengembalikan message yang sama.
2. Definitive failure berlanjut ke provider step berikutnya.
3. Job yang melihat message expired menandai `expired` tanpa provider call.

## Exception flows

- Queue dispatch gagal sebelum transaksi commit: transaksi gagal dan client menerima error; tidak ada message yatim yang dianggap queued.
- Worker menerima message yang sudah accepted/outcome_unknown/terminal: no-op, tidak mengirim ulang.
- Worker crash setelah provider request mulai: recovery tidak otomatis mengirim ulang attempt `processing` yang stale; operator harus merekonsiliasi/retry dengan risiko eksplisit.
- Outcome unknown: status `outcome_unknown`, tidak fallback otomatis.

## Acceptance criteria

| AC ID | Given | When | Then | Related requirement |
|---|---|---|---|---|
| AC-007 | Request async valid | API menyimpan message | 202 queued dan job terdaftar setelah commit | FR-005 |
| AC-008 | Worker memproses queued message | Provider menerima | Message menjadi provider_accepted dengan attempt | FR-007–FR-009 |
| AC-009 | Message kedaluwarsa di antrean | Worker mengambilnya | Status expired dan tidak ada HTTP call | FR-010 |
| AC-010 | Worker/job dijalankan lagi pada message terminal | Handler berjalan | Tidak ada provider call tambahan | FR-003, FR-009 |

## Evidence and validation

Flow berasal dari kebutuhan notifikasi non-OTP dan risiko request blocking pada empat sistem. Status Reviewed dengan implementasi exception yang disetujui.
