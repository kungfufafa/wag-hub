# UC-003 — Configure applications, providers, and routes

Status: Reviewed  
Derived from: FR-011, NFR-001

## Intent

- Actor: ACT-002 Administrator gateway
- Goal: Mengubah provider/fallback cukup di Hub tanpa menyentuh aplikasi sumber.
- Trigger: Administrator membuka menu konfigurasi.
- Preconditions: User aktif, admin, dan login.
- Postconditions: Konfigurasi valid tersedia bagi routing engine tanpa membuka secret lama.
- Related pages: PAGE-001–PAGE-004

## Main flow

| Step | Actor/system action | Data read/written | Rule/requirement |
|---|---|---|---|
| 1 | Admin login dan membuka konfigurasi | User/session | NFR-001 |
| 2 | Admin membuat client application lalu credential | Application/Credential | FR-011 |
| 3 | Sistem menampilkan token plaintext satu kali dan menyimpan hash | Credential | NFR-001 |
| 4 | Admin membuat provider account serta credential write-only | ProviderAccount | FR-011, NFR-001 |
| 5 | Admin membuat policy dan menyusun ordered provider steps | Policy/Steps | FR-006, FR-011 |
| 6 | Sistem memvalidasi step unik/aktif dan menyimpan policy | Policy/Steps | FR-011 |

## Alternative flows

1. Admin merotasi token; token lama direvoke dan token baru hanya ditampilkan sekali.
2. Admin menonaktifkan provider; routing engine melewati step terkait.
3. Policy aplikasi mengoverride global default untuk route key/purpose tertentu.

## Exception flows

- Non-admin/inactive user: 403.
- Duplicate application slug/policy key/step position: validation error.
- Secret dibiarkan kosong saat edit: credential lama tetap tersimpan; UI tidak mengirim kembali plaintext.
- Provider yang masih dipakai tidak hard-delete; dinonaktifkan/soft-delete.

## Acceptance criteria

| AC ID | Given | When | Then | Related requirement |
|---|---|---|---|---|
| AC-011 | Active admin | Membuka `/admin` | Akses diberikan | FR-011 |
| AC-012 | Non-admin/inactive user | Membuka `/admin` | 403 | NFR-001 |
| AC-013 | Admin membuat credential | Save sukses | Plaintext tampil sekali, DB hanya menyimpan hash | FR-011, NFR-001 |
| AC-014 | Admin menyimpan provider secret | Record dibaca ulang | Field terenkripsi dan tidak dipresentasikan di list/detail | NFR-001 |

## Evidence and validation

Flow diturunkan dari kebutuhan operasional pusat dan standar Filament. Status Reviewed; prototype executable akan menjadi bukti review berikutnya.
