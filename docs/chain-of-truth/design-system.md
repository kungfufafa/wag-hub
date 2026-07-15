# Design System — WhatsApp Gateway Hub

Status: Reviewed  
Derived from: SRS 0.1.0 dan komponen standar Filament 4

## Principles

- Operational clarity: status dan tindakan aman harus terbaca cepat.
- Secret-safe: credential selalu masked/write-only dan response provider disanitasi.
- Accessible by default: komponen, focus state, label, contrast, dan keyboard behavior memakai standar Filament.
- Conservative actions: retry, revoke token, dan disable provider membutuhkan konfirmasi.

## Foundations

| Area | Tokens/rules | Rationale/evidence |
|---|---|---|
| Color | `success` accepted, `warning` queued/outcome_unknown, `danger` failed/dead-letter, `info` processing | Semantik status operasional Filament |
| Type | Font dan scale bawaan Filament; tabular numbers untuk latency/count | Konsistensi dan keterbacaan |
| Spacing | Scale bawaan Filament, section gap minimal 24 px | Tidak membuat theme baru pada MVP |
| Radius/elevation | Bawaan Filament | Mengurangi surface kustom |
| Breakpoints | Form satu kolom pada small, dua kolom pada large; table memakai responsive columns | Admin dapat melakukan triage mobile |

## Components

| Component ID | Component | Variants | States | Accessibility | Used by pages |
|---|---|---|---|---|---|
| CMP-001 | Status badge | queued, processing, accepted, failed, outcome_unknown, expired | semantic color + text | Tidak bergantung pada warna | PAGE-001, PAGE-005, PAGE-006 |
| CMP-002 | Provider health badge | healthy, degraded, unavailable, disabled | text/icon | Label eksplisit | PAGE-001, PAGE-003 |
| CMP-003 | Secret input | password/reveal saat create only | empty, valid, error | Label dan help text | PAGE-002, PAGE-003 |
| CMP-004 | Ordered route repeater | provider + position | add, reorder, remove, invalid | Keyboard operable | PAGE-004 |
| CMP-005 | Attempt timeline/table | accepted/failure/outcome_unknown | empty, loading, populated | Header dan timestamp eksplisit | PAGE-006 |
| CMP-006 | Confirmed action | retry, revoke, disable | idle, submitting, success, failure | Confirmation text dan focus | PAGE-002–PAGE-006 |
| CMP-007 | Stats overview | queued, accepted, failed, fallback rate | loading, value, empty | Text value tersedia | PAGE-001 |

## Interaction and feedback

- Form error muncul dekat field dan tidak membuka secret lama.
- Retry menampilkan alasan, message ID, dan mencatat attempt baru.
- Token baru ditampilkan satu kali dengan instruksi menyimpan secara aman.
- Tidak ada animasi dekoratif; perubahan status memakai notifikasi singkat dan refresh data.
- Destructive/configuration actions memerlukan konfirmasi dan mencatat actor melalui timestamp/model event yang tersedia.

## Validation record

Reviewed sebagai batas desain MVP. Prototype executable memakai Filament 4; visual brand khusus ditunda agar fokus pada reliability dan keamanan.
