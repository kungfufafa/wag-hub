# Test Execution — WhatsApp Gateway Hub MVP

Baseline: Artifacts 0.1.0; code baseline `8d48494` plus documentation handoff
Environment: PHP 8.4.23, Laravel 12.64, Filament 4.11.8, SQLite in-memory
Executed at: 2026-07-15

| Test ID/scope | Command/manual evidence | Result | Evidence | Failure owner |
|---|---|---|---|---|
| TC-001–TC-047 | `php artisan test` di `gateway-hub` | Pass | 201 tests, 894 assertions; tidak ada skipped/failure | — |
| TC-048–TC-051 | Targeted WhatsApp tests di `web-shelf` | Pass | 29 tests, 106 assertions | — |
| Full Shelf regression | `php artisan test` di `web-shelf` | Conditional | 156 pass, 932 assertions; 2 failure lama dan tidak menyentuh adapter WhatsApp | Shelf: action size + missing test migration |
| Admin runtime | Browser lokal `/admin` | Pass | Login, dashboard stats, empat resource, create forms, empty states; tidak ada console error | — |
| Schema/routes/schedule | fresh SQLite migration, route list, schedule list | Pass | 5 migrations; 14 application routes; recovery setiap menit | — |
| Static/build | Pint, diff check, Vite production build | Pass | Pint bersih; 55 modules built | — |
| Dependency security | Composer audit Hub+Shelf; npm audit Hub | Pass | Tidak ada advisory/vulnerability | — |
| Coverage threshold | `php artisan test --coverage --min=80` | Not Run | Runtime tidak menyediakan Xdebug/PCOV | CI owner |
| Live provider smoke | Credential provider nyata | Not Run | Credential dan host pilot belum diberikan | Deployment owner |

## Residual risk and release decision

Kode berstatus **ready for controlled pilot configuration**, bukan production go-live. Pilot masih memerlukan HTTPS/domain, database dan backup, process manager, queue worker, scheduler, endpoint allowlist, credential baru, deployment adapter Shelf, serta satu smoke test nyata untuk setiap provider.

Risiko yang sengaja belum ditutup dalam outbound MVP:

- endpoint inbound asset-query Shelf yang sudah ada masih publik dan harus dibatasi jaringan sampai autentikasi/rate limit fase inbound diterapkan;
- pruning/retention otomatis belum diimplementasikan;
- live provider dan load/HA test belum dijalankan;
- dua failure full-suite Shelf berada pada layout Filament dan fixture PDF, bukan perubahan WhatsApp;
- coverage line 80% menunggu CI dengan Xdebug atau PCOV.
