# Software Requirements Specification — WhatsApp Gateway Hub

Status: Reviewed  
Version: 0.1.0  
Owner: Complete Selular IT  
Last reviewed: 2026-07-15

## Purpose and problem

`appscript-ft`, `web-shelf`, `web-sam`, dan `web-helpdesk` saat ini mengulang konfigurasi WAHA/Fonnte, normalisasi nomor, throttle, fallback, dan logging di setiap sistem. Perubahan provider harus dilakukan berulang dan tidak ada ledger pusat untuk membedakan request diterima, provider menerima, gagal, fallback, atau hasilnya tidak pasti.

Gateway Hub menjadi satu batas transport WhatsApp. Aplikasi sumber tetap memiliki aturan bisnis dan membangun isi pesan; Hub mengautentikasi aplikasi, mencegah duplikasi, memilih provider, mengirim, dan merekam hasil setiap percobaan.

## Scope

### In scope

- Laravel 12 API dan panel admin Filament 4.
- Pesan teks outbound ke satu nomor individual per request.
- Token terpisah untuk setiap aplikasi sumber.
- Mode sinkron sampai `provider_accepted` untuk OTP/transaksi kritis.
- Mode asinkron yang berhenti di `queued` untuk notifikasi biasa.
- Banyak akun WAHA/Fonnte dan route step berurutan.
- Idempotency, masa kedaluwarsa, prioritas, attempt ledger, dan status message.
- Pengelolaan aplikasi, credential, provider account, routing policy, dan route step.
- Pencarian/filter log, detail timeline attempt, dan retry manual untuk kegagalan terminal.
- Pilot integrasi `web-shelf` tanpa memindahkan message builder bisnisnya.

### Out of scope

- Media, group chat, multi-target, template interaktif, broadcast marketing, dan conversation inbox.
- NLP/bot atau aturan bisnis Shelf/SAM/Helpdesk di dalam Hub.
- Raw inbound provider webhook dan forwarding ke aplikasi; skema event disiapkan tetapi aktivasi adalah fase berikutnya.
- Klaim `delivered` atau `read` tanpa webhook provider yang terverifikasi.
- High availability multi-node dan autoscaling; deployment MVP tetap harus memakai worker terkelola dan backup.

## Stakeholders, actors, and goals

| Actor ID | Actor | Goal | Permission boundary |
|---|---|---|---|
| ACT-001 | Aplikasi sumber | Meminta pengiriman dan membaca status pesan miliknya | Hanya token aplikasi aktif; tidak dapat memilih credential/provider account langsung |
| ACT-002 | Administrator gateway | Mengelola aplikasi, provider, route, dan memantau/retry pesan | User aktif dengan `is_admin=true` |
| ACT-003 | Queue worker | Memproses pesan asinkron | Proses internal; tidak diekspos sebagai API publik |
| ACT-004 | Provider WAHA/Fonnte | Menerima pesan dari adapter | Hanya menerima credential account yang dipilih routing engine |

## Assumptions and constraints

| ID | Type | Statement | Evidence/owner |
|---|---|---|---|
| CON-001 | Constraint | Aplikasi lama mengharapkan `send(phone, message): bool`; adapter pilot harus mempertahankan bentuk ini | Implementasi identik pada tiga aplikasi Laravel |
| CON-002 | Constraint | OTP SAM/Helpdesk hanya boleh dianggap berhasil setelah provider menerima pesan | Alur OTP yang diperiksa pada 2026-07-15 |
| CON-003 | Constraint | Nomor utama menggunakan kode negara Indonesia `62`; raw JID/group ID ditolak pada MVP | Implementasi saat ini dan batas MVP |
| CON-004 | Constraint | SQLite dipakai untuk test/local; database produksi harus mendukung unique constraint dan transaksi | Keputusan implementasi MVP |
| CON-005 | Assumption | Database queue cukup untuk MVP; Redis/Horizon dapat menggantikannya tanpa mengubah kontrak API | Keputusan arsitektur MVP |
| CON-006 | Constraint | Credential provider yang pernah tertanam di Apps Script harus dirotasi; Hub tidak boleh menyalinnya dari source | Audit 2026-07-15 |

## Functional requirements

| ID | Requirement | Priority | Source | Verification |
|---|---|---|---|---|
| FR-001 | Sistem harus mengautentikasi setiap request API menggunakan Bearer token yang di-hash dan terikat ke satu aplikasi aktif. | Must | Persetujuan MVP | TC-001–TC-003 |
| FR-002 | Sistem harus memvalidasi dan menormalisasi satu nomor individual Indonesia serta menolak raw JID dan multi-target. | Must | Kompatibilitas aplikasi | TC-004–TC-006 |
| FR-003 | Sistem harus mewajibkan `Idempotency-Key` dan mengembalikan message/result yang sama untuk pengulangan key dalam aplikasi yang sama. | Must | Risiko duplikasi | TC-007–TC-009 |
| FR-004 | Sistem harus mendukung mode sinkron yang sukses hanya setelah salah satu provider mengembalikan penerimaan eksplisit. | Must | OTP | TC-010–TC-014 |
| FR-005 | Sistem harus mendukung mode asinkron yang menyimpan pesan secara durabel lalu menjalankan job pengiriman. | Must | Notifikasi | TC-015–TC-017 |
| FR-006 | Sistem harus memilih routing policy berdasarkan aplikasi + route key/purpose, lalu mencoba provider account aktif sesuai urutan step. | Must | Routing berlapis | TC-018–TC-021 |
| FR-007 | Sistem harus menyediakan driver WAHA dan Fonnte dengan request serta klasifikasi response masing-masing. | Must | Provider saat ini | TC-022–TC-029 |
| FR-008 | Sistem harus menyimpan satu attempt record untuk setiap panggilan provider, termasuk status, latency, HTTP status, remote ID, dan error tersanitasi. | Must | Observability | TC-030–TC-032 |
| FR-009 | Sistem harus menjaga lifecycle message `queued`, `processing`, `provider_accepted`, `failed`, `outcome_unknown`, `expired`, dan `dead_letter`. | Must | Operasi | TC-033–TC-036 |
| FR-010 | Sistem harus menolak pengiriman pesan yang sudah melewati `expires_at`. | Must | OTP | TC-037 |
| FR-011 | Administrator harus dapat mengelola aplikasi, API credential, provider account, routing policy, dan ordered route step. | Must | Operasi pusat | TC-038–TC-041 |
| FR-012 | Administrator harus dapat melihat message timeline dan melakukan retry manual hanya pada status terminal yang aman. | Must | Operasi pusat | TC-042–TC-044 |
| FR-013 | Sistem harus menyediakan endpoint baca status yang hanya dapat melihat pesan milik aplikasi pemanggil. | Should | Integrasi | TC-045–TC-047 |
| FR-014 | Pilot `web-shelf` harus menggunakan Hub melalui adapter yang mempertahankan `send(): bool` dan tidak lagi menyimpan credential provider. | Must | Persetujuan MVP | TC-048–TC-051 |

## Business rules

| ID | Rule | Applies to | Source |
|---|---|---|---|
| BR-001 | Unique idempotency scope adalah `(client_application_id, idempotency_key)`; payload yang berbeda dengan key sama menghasilkan conflict. | FR-003 | Risiko replay |
| BR-002 | Client mengirim `route_key`/`purpose`, bukan provider account ID atau credential. | FR-006 | Boundary keamanan |
| BR-003 | Mode sinkron mengembalikan sukses hanya pada `provider_accepted`; mode asinkron mengembalikan `queued`. | FR-004, FR-005 | Semantik kompatibilitas |
| BR-004 | Timeout setelah request mungkin terkirim diklasifikasikan `outcome_unknown` dan tidak otomatis fallback. Connection refusal sebelum transmisi boleh fallback. | FR-006, FR-007 | Pencegahan pesan ganda |
| BR-005 | Fonnte diterima hanya jika HTTP 2xx dan JSON `status=true`; WAHA diterima pada HTTP 2xx dan response JSON yang dapat diproses. | FR-007 | Kontrak provider |
| BR-006 | Pesan kedaluwarsa tidak boleh dipanggil ke provider. | FR-010 | OTP safety |
| BR-007 | Retry manual membuat attempt baru dan tidak menghapus history lama. | FR-008, FR-012 | Auditability |
| BR-008 | Provider/response rahasia tidak pernah dikembalikan ke client atau log umum. | FR-007, FR-008 | Security |

## Non-functional requirements

| ID | Quality | Measurable requirement | Verification |
|---|---|---|---|
| NFR-001 | Security | Provider credential dan message body tersimpan terenkripsi; API token hanya tersimpan sebagai SHA-256 hash; admin nonaktif/non-admin ditolak. | Model cast review, TC-001–TC-003, TC-038 |
| NFR-002 | Reliability | Idempotency harus tetap benar pada request berulang dan unique constraint menjadi guard terakhir. | TC-007–TC-009 |
| NFR-003 | Performance | Validasi/queue response lokal p95 target <300 ms di luar waktu provider; latency provider dicatat per attempt. | Benchmark pasca-deploy dan TC-031 |
| NFR-004 | Privacy | List admin selalu mem-mask recipient; raw body tidak muncul di application log; OTP content direkomendasikan retensi maksimal 24 jam. | UI/code review |
| NFR-005 | Observability | Setiap message memiliki UUID/correlation ID dan setiap provider call memiliki attempt record. | TC-030–TC-036 |
| NFR-006 | Maintainability | Provider baru ditambahkan melalui `ProviderDriver` tanpa mengubah controller API. | Unit architecture review |
| NFR-007 | Testability | Business/API paths MVP memiliki minimal 80% line coverage saat coverage driver tersedia. | PHPUnit coverage CI |

## Data and retention requirements

- Provider credentials, recipient, message body, dan raw provider response adalah data sensitif dan memakai encrypted cast.
- Hash recipient terpisah boleh disimpan untuk pencarian/deduplikasi tanpa membuka nilai mentah.
- Default usulan: body OTP 24 jam, body notifikasi 7 hari, metadata/attempt 90 hari; nilai final tetap menjadi keputusan deployment owner.
- Token plaintext hanya ditampilkan saat dibuat/dirotasi dan tidak dapat diambil kembali.
- Menghapus aplikasi/provider tidak boleh menghapus ledger; record operasional dinonaktifkan atau soft-deleted.

## External interfaces

- `POST /api/v1/messages` — menerima pesan sync/async.
- `GET /api/v1/messages/{uuid}` — status milik aplikasi pemanggil.
- WAHA: `POST {base_url}/api/sendText` dengan `X-Api-Key` dan JSON.
- Fonnte: `POST {endpoint}` dengan header `Authorization` dan multipart/form.
- `/admin` — panel Filament untuk administrator.
- Pilot `web-shelf` memanggil Hub melalui HTTPS Bearer token dan `Idempotency-Key`.

## Open questions and conflicts

| ID | Question/conflict | Impact | Owner | Due/decision |
|---|---|---|---|---|
| OQ-001 | Retensi final body OTP/notifikasi untuk produksi | Storage dan privasi | Complete Selular IT | Sebelum production go-live; safe default diterapkan |
| OQ-002 | URL/domain dan database/Redis produksi | Deployment | Complete Selular IT | Sebelum deployment |
| OQ-003 | Webhook delivery/read WAHA dan Fonnte | Status delivery aktual | Complete Selular IT | Fase 2 |

## Validation record

Pada 2026-07-15 stakeholder menyetujui ringkasan arsitektur outbound-first dengan balasan “lanjur mvp”. Dokumen rinci ini berstatus `Reviewed`, bukan `Validated`, karena dibuat setelah persetujuan ringkasan. Persetujuan tersebut dicatat sebagai izin eksplisit untuk implementasi MVP dengan exception bahwa artefak rinci akan ditinjau pada handoff.
