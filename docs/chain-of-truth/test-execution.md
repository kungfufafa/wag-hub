# Test Execution — WhatsApp Gateway Hub MVP

Baseline: Artifacts 0.1.0; code baseline `8d48494` plus documentation handoff
Environment: PHP 8.4.23, Laravel 12.64, Filament 4.11.8, SQLite in-memory
Executed at: 2026-09-14

| Test ID/scope | Command/manual evidence | Result | Evidence | Failure owner |
|---|---|---|---|---|
| TC-001–TC-060 + attachment regression | `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php vendor/bin/phpunit` | Pass | 328 tests, 1,740 assertions; tidak ada skipped/failure | — |
| TC-048–TC-051 | Targeted WhatsApp tests di `web-shelf` | Pass | 29 tests, 106 assertions | — |
| Full Shelf regression | `php artisan test` di `web-shelf` | Conditional | 156 pass, 932 assertions; 2 failure lama dan tidak menyentuh adapter WhatsApp | Shelf: action size + missing test migration |
| Admin runtime | Browser lokal `/admin` | Pass | Login, dashboard stats, empat resource, create forms, empty states; tidak ada console error | — |
| Schema/routes/schedule | fresh SQLite migration, route list, schedule list | Pass | Attachment migration, signed GET/HEAD route, cleanup scheduler, recovery setiap menit | — |
| Static/build | PHP lint, diff check, view cache, Vite production build | Pass | Semua file PHP valid; Blade cache dan build frontend berhasil | — |
| Dependency security | Composer audit Hub+Shelf; npm audit Hub | Pass | Tidak ada advisory/vulnerability | — |
| Coverage threshold | `php artisan test --coverage --min=80` | Not Run | Runtime tidak menyediakan Xdebug/PCOV | CI owner |
| Live provider smoke | Credential provider nyata | Not Run | Credential dan host pilot belum diberikan | Deployment owner |

## Residual risk and release decision

Kode berstatus **ready for controlled pilot configuration**, bukan production go-live. Pilot masih memerlukan HTTPS/domain, database dan backup, process manager, queue worker, scheduler, endpoint allowlist, credential baru, deployment adapter Shelf, serta satu smoke test nyata untuk setiap provider.

Risiko yang sengaja belum ditutup dalam outbound MVP:

- endpoint inbound asset-query Shelf yang sudah ada masih publik dan harus dibatasi jaringan sampai autentikasi/rate limit fase inbound diterapkan;
- live provider dan load/HA test belum dijalankan;
- scheduler/worker production tetap harus dipastikan aktif agar cleanup 24 jam/retensi 90 hari berjalan;
- dua failure full-suite Shelf berada pada layout Filament dan fixture PDF, bukan perubahan WhatsApp;
- coverage line 80% menunggu CI dengan Xdebug atau PCOV.
