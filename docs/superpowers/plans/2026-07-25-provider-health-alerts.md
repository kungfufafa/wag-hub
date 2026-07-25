# Provider Health Alerts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Notify Filament admins over Telegram and/or Email whenever a provider account `health_status` changes, with UI-managed SMTP/bot credentials, per-admin destinations, cooldown, and delivery history.

**Architecture:** `ProviderHealthRecorder` emits `ProviderHealthChanged` (`ShouldDispatchAfterCommit`) only when status actually changes. A sync listener applies master switch + cooldown, writes `alert_deliveries`, and queues one `DeliverProviderHealthAlert` job per (user × channel). Each job sends via a non-queued Laravel Notification (custom `telegram` channel + runtime SMTP mailer from DB) and updates the delivery row.

**Tech Stack:** Laravel 12, Filament 4.11, PHPUnit 11, queue=`database`, encrypted Eloquent casts, `Http` facade for Telegram Bot API.

**Spec:** `docs/superpowers/specs/2026-07-25-provider-health-alerts-design.md`

## Global Constraints

- Alert on every `health_status` transition including recovery to `healthy`.
- Channels: Telegram + Email only (no Discord/webhooks).
- Sender credentials global in UI (`alert_settings`); destinations per admin (`user_alert_preferences`).
- Do **not** use `.env` `MAIL_*` for this feature’s live/test sends.
- Secrets (`smtp_password`, `telegram_bot_token`) use encrypted casts; never log or store in `alert_deliveries`.
- Cooldown per provider (default 300s); skipped sends still create `skipped_cooldown` rows.
- Test buttons ignore master switch; live alerts require `is_enabled = true`.
- Indonesian UI labels to match existing Filament copy.
- After code changes, run `graphify update .` (AST-only).

---

## File Structure

| Path | Responsibility |
|---|---|
| `database/migrations/2026_07_25_000400_create_provider_health_alert_tables.php` | `alert_settings`, `user_alert_preferences`, `alert_deliveries` |
| `app/Models/AlertSetting.php` | Singleton settings + encrypted secrets |
| `app/Models/UserAlertPreference.php` | Per-user channel prefs |
| `app/Models/AlertDelivery.php` | Delivery audit rows |
| `app/Models/User.php` | Relations + `routeNotificationForMail` / Telegram |
| `app/Events/ProviderHealthChanged.php` | After-commit domain event |
| `app/Services/ProviderHealthRecorder.php` | Emit event on real status change |
| `app/Services/Alerts/AlertCooldownStore.php` | Cache cooldown keys |
| `app/Services/Alerts/AlertErrorSummarizer.php` | Truncate/sanitize error text |
| `app/Services/Alerts/AlertMailerFactory.php` | Register runtime SMTP mailer from DB |
| `app/Listeners/SendProviderHealthAlerts.php` | Fan-out deliveries + queue jobs |
| `app/Jobs/DeliverProviderHealthAlert.php` | Send one channel; update delivery |
| `app/Notifications/ProviderHealthChangedNotification.php` | Mail + Telegram payload |
| `app/Notifications/Channels/TelegramChannel.php` | Bot API `sendMessage` |
| `app/Filament/Pages/ManageAlertSettings.php` | Global settings + tests |
| `app/Filament/Pages/MyAlertPreferences.php` | Per-admin prefs + tests |
| `app/Filament/Resources/AlertDeliveries/*` | Read-only history |
| `app/Providers/AppServiceProvider.php` | Event listen + `Notification::extend` |
| `tests/Feature/Alerts/*` | Feature coverage |
| `tests/Feature/Admin/AdminResourceSmokeTest.php` | Add new routes |

---

### Task 1: Schema + models

**Files:**
- Create: `database/migrations/2026_07_25_000400_create_provider_health_alert_tables.php`
- Create: `app/Models/AlertSetting.php`
- Create: `app/Models/UserAlertPreference.php`
- Create: `app/Models/AlertDelivery.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Alerts/AlertSettingsModelTest.php`

**Interfaces:**
- Produces: `AlertSetting::current(): self`, `User::alertPreference()`, `AlertDelivery` fillable/casts as below

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertSetting;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertSettingsModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_setting_secrets_are_encrypted_at_rest(): void
    {
        $settings = AlertSetting::current();
        $settings->forceFill([
            'smtp_password' => 'smtp-secret',
            'telegram_bot_token' => '123:ABC',
        ])->save();

        $raw = DB::table('alert_settings')->where('id', $settings->id)->first();
        $this->assertNotSame('smtp-secret', $raw->smtp_password);
        $this->assertNotSame('123:ABC', $raw->telegram_bot_token);

        $fresh = AlertSetting::query()->findOrFail($settings->id);
        $this->assertSame('smtp-secret', $fresh->smtp_password);
        $this->assertSame('123:ABC', $fresh->telegram_bot_token);
    }

    public function test_user_can_have_one_alert_preference(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        $pref = UserAlertPreference::query()->create([
            'user_id' => $user->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '999',
            'email_enabled' => true,
            'email_address' => null,
        ]);

        $this->assertTrue($user->fresh()->alertPreference->is($pref));
        $this->assertSame('999', $user->alertPreference->telegram_chat_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=AlertSettingsModelTest`

Expected: FAIL (missing migration/models)

- [ ] **Step 3: Write migration + models**

Migration creates:

```php
Schema::create('alert_settings', function (Blueprint $table) {
    $table->id();
    $table->boolean('is_enabled')->default(false);
    $table->unsignedInteger('cooldown_seconds')->default(300);
    $table->string('smtp_host')->nullable();
    $table->unsignedSmallInteger('smtp_port')->nullable();
    $table->string('smtp_username')->nullable();
    $table->text('smtp_password')->nullable();
    $table->string('smtp_encryption', 16)->nullable();
    $table->string('smtp_from_address')->nullable();
    $table->string('smtp_from_name')->nullable();
    $table->text('telegram_bot_token')->nullable();
    $table->timestamps();
});

Schema::create('user_alert_preferences', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->boolean('telegram_enabled')->default(false);
    $table->string('telegram_chat_id')->nullable();
    $table->boolean('email_enabled')->default(false);
    $table->string('email_address')->nullable();
    $table->timestamps();
});

Schema::create('alert_deliveries', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('provider_account_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('channel', 16);
    $table->string('from_status', 24);
    $table->string('to_status', 24);
    $table->unsignedInteger('consecutive_failures')->default(0);
    $table->timestamp('circuit_open_until')->nullable();
    $table->string('error_summary', 500)->nullable();
    $table->string('status', 32);
    $table->text('failure_reason')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamps();

    $table->index(['provider_account_id', 'created_at']);
    $table->index(['status', 'created_at']);
    $table->index(['user_id', 'created_at']);
    $table->index(['channel', 'created_at']);
});
```

`AlertSetting::current()`:

```php
public static function current(): self
{
    return static::query()->firstOrCreate([], [
        'is_enabled' => false,
        'cooldown_seconds' => 300,
    ]);
}
```

Casts: `is_enabled` bool, `cooldown_seconds` int, `smtp_password`/`telegram_bot_token` encrypted, ports int.

`AlertDelivery` uses `HasUuids` with `uniqueIds(): ['uuid']`.

`User`:

```php
public function alertPreference(): HasOne
{
    return $this->hasOne(UserAlertPreference::class);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php artisan test --filter=AlertSettingsModelTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
rtk git add database/migrations/2026_07_25_000400_create_provider_health_alert_tables.php app/Models/AlertSetting.php app/Models/UserAlertPreference.php app/Models/AlertDelivery.php app/Models/User.php tests/Feature/Alerts/AlertSettingsModelTest.php
rtk git commit -m "$(cat <<'EOF'
feat(alerts): add settings and delivery schema

EOF
)"
```

---

### Task 2: Event + emit from health recorder

**Files:**
- Create: `app/Events/ProviderHealthChanged.php`
- Create: `app/Services/Alerts/AlertErrorSummarizer.php`
- Modify: `app/Services/ProviderHealthRecorder.php`
- Test: `tests/Feature/Alerts/ProviderHealthChangedEventTest.php`

**Interfaces:**
- Consumes: `ProviderAccount`, `ProviderResult` error fields
- Produces:

```php
final class ProviderHealthChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $providerAccountId,
        public string $providerName,
        public string $providerSlug,
        public string $providerDriver,
        public string $providerUuid,
        public string $fromStatus,
        public string $toStatus,
        public int $consecutiveFailures,
        public ?string $circuitOpenUntilIso,
        public ?string $errorSummary,
    ) {}
}

final class AlertErrorSummarizer
{
    public function summarize(?string $errorCode, ?string $errorMessage, int $max = 500): ?string
}
```

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Alerts;

use App\Domain\Delivery\ProviderResult;
use App\Events\ProviderHealthChanged;
use App\Models\ProviderAccount;
use App\Services\ProviderHealthRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ProviderHealthChangedEventTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_recorder_dispatches_event_only_when_status_changes(): void
    {
        Event::fake([ProviderHealthChanged::class]);
        config()->set('gateway.provider_health.failure_threshold', 3);

        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-alert-event', [
                'base_url' => 'https://waha-alert-event.test',
                'api_key' => 'secret',
                'session' => 'default',
            ])['id'],
        );

        $recorder = app(ProviderHealthRecorder::class);

        $recorder->record($provider, ProviderResult::providerFailed(errorMessage: 'boom-1'));
        Event::assertNotDispatched(ProviderHealthChanged::class); // healthy → degraded? wait: failure 1 = degraded

        // Re-fetch: after 1 failure status is degraded — event SHOULD have fired once
        Event::assertDispatched(ProviderHealthChanged::class, function (ProviderHealthChanged $e) {
            return $e->fromStatus === 'healthy'
                && $e->toStatus === 'degraded'
                && $e->errorSummary !== null
                && str_contains($e->errorSummary, 'boom-1');
        });

        Event::fake([ProviderHealthChanged::class]);
        $provider = $provider->fresh();
        $recorder->record($provider, ProviderResult::providerFailed(errorMessage: 'boom-2'));
        Event::assertNotDispatched(ProviderHealthChanged::class); // still degraded

        Event::fake([ProviderHealthChanged::class]);
        $provider = $provider->fresh();
        $recorder->record($provider, ProviderResult::providerFailed(errorMessage: 'boom-3'));
        Event::assertDispatched(ProviderHealthChanged::class, fn (ProviderHealthChanged $e) => $e->toStatus === 'unavailable');

        Event::fake([ProviderHealthChanged::class]);
        $provider = $provider->fresh();
        $recorder->record($provider, ProviderResult::accepted());
        Event::assertDispatched(ProviderHealthChanged::class, fn (ProviderHealthChanged $e) => $e->fromStatus === 'unavailable' && $e->toStatus === 'healthy');
    }
}
```

Fix the first assertion comment in implementation: first failure **does** dispatch `healthy → degraded`. Remove the mistaken `assertNotDispatched` line from the sketch above when writing the real test — keep only the positive assertions shown after the comment.

Final test flow:

1. failure #1 → dispatch `healthy → degraded`
2. failure #2 → no dispatch (still `degraded`)
3. failure #3 → dispatch `degraded → unavailable`
4. accepted → dispatch `unavailable → healthy`

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=ProviderHealthChangedEventTest`

Expected: FAIL (event class / dispatch missing)

- [ ] **Step 3: Implement event, summarizer, recorder hook**

Inside `ProviderHealthRecorder::record` transaction, capture `$previousStatus = $lockedProvider->health_status` before mutate. After `save()`, if `$previousStatus !== $lockedProvider->health_status`, call:

```php
event(new ProviderHealthChanged(
    providerAccountId: (int) $lockedProvider->id,
    providerName: (string) $lockedProvider->name,
    providerSlug: (string) $lockedProvider->slug,
    providerDriver: (string) $lockedProvider->driver,
    providerUuid: (string) $lockedProvider->uuid,
    fromStatus: (string) $previousStatus,
    toStatus: (string) $lockedProvider->health_status,
    consecutiveFailures: (int) $lockedProvider->consecutive_failures,
    circuitOpenUntilIso: $lockedProvider->circuit_open_until?->toIso8601String(),
    errorSummary: app(AlertErrorSummarizer::class)->summarize(
        $result->errorCode,
        $result->errorMessage,
    ),
));
```

`AlertErrorSummarizer`: join non-empty code/message with `: `, truncate to 500, strip obvious `token=` / `password=` query-ish substrings with a simple regex.

Apply the same status-change emit path for `recordNumberCheck` via existing `record()` calls (no extra emit there).

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php artisan test --filter=ProviderHealthChangedEventTest`

Expected: PASS

Also run: `rtk php artisan test --filter=ProviderCircuitBreakerTest`

Expected: PASS (no regressions)

- [ ] **Step 5: Commit**

```bash
rtk git add app/Events/ProviderHealthChanged.php app/Services/Alerts/AlertErrorSummarizer.php app/Services/ProviderHealthRecorder.php tests/Feature/Alerts/ProviderHealthChangedEventTest.php
rtk git commit -m "$(cat <<'EOF'
feat(alerts): emit provider health changed events

EOF
)"
```

---

### Task 3: Cooldown store + fan-out listener

**Files:**
- Create: `app/Services/Alerts/AlertCooldownStore.php`
- Create: `app/Listeners/SendProviderHealthAlerts.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `app/Jobs/DeliverProviderHealthAlert.php` (stub `handle` empty / mark later — or create full job shell that throws until Task 4)
- Test: `tests/Feature/Alerts/SendProviderHealthAlertsTest.php`

**Interfaces:**
- Consumes: `ProviderHealthChanged`, `AlertSetting::current()`, `User` + prefs
- Produces:

```php
final class AlertCooldownStore
{
    public function cacheKey(int $providerAccountId): string; // alert:cooldown:provider:{id}
    public function isCoolingDown(int $providerAccountId): bool;
    public function hit(int $providerAccountId, int $cooldownSeconds): void;
}

final class SendProviderHealthAlerts
{
    public function handle(ProviderHealthChanged $event): void;
}

final class DeliverProviderHealthAlert implements ShouldQueue
{
    public function __construct(public int $alertDeliveryId) {}
    public int $tries = 3;
    /** @var list<int> */
    public array $backoff = [10, 30, 60];
    public int $timeout = 60;
}
```

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Alerts;

use App\Events\ProviderHealthChanged;
use App\Jobs\DeliverProviderHealthAlert;
use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class SendProviderHealthAlertsTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_listener_queues_jobs_for_enabled_channels_and_skips_cooldown(): void
    {
        Queue::fake();

        $settings = AlertSetting::current();
        $settings->forceFill([
            'is_enabled' => true,
            'cooldown_seconds' => 300,
            'smtp_host' => 'smtp.test',
            'smtp_port' => 587,
            'smtp_from_address' => 'alerts@test.local',
            'telegram_bot_token' => '1:token',
        ])->save();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $admin->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '111',
            'email_enabled' => true,
            'email_address' => null,
        ]);
        User::factory()->create(['is_admin' => true, 'is_active' => false]); // ignored
        $inactivePrefAdmin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $inactivePrefAdmin->id,
            'telegram_enabled' => false,
            'email_enabled' => false,
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-fanout', [
            'base_url' => 'https://waha-fanout.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);

        $event = new ProviderHealthChanged(
            providerAccountId: $provider['id'],
            providerName: 'Waha Fanout',
            providerSlug: 'waha-fanout',
            providerDriver: 'waha',
            providerUuid: (string) \Illuminate\Support\Facades\DB::table('provider_accounts')->where('id', $provider['id'])->value('uuid'),
            fromStatus: 'healthy',
            toStatus: 'degraded',
            consecutiveFailures: 1,
            circuitOpenUntilIso: null,
            errorSummary: 'timeout',
        );

        event($event);

        Queue::assertPushed(DeliverProviderHealthAlert::class, 2);
        $this->assertSame(2, AlertDelivery::query()->where('status', 'pending')->count());

        // Second event during cooldown → skipped rows, no new jobs
        Queue::fake();
        event($event);
        Queue::assertNothingPushed();
        $this->assertSame(2, AlertDelivery::query()->where('status', 'skipped_cooldown')->count());
    }

    public function test_listener_noops_when_master_switch_off(): void
    {
        Queue::fake();
        AlertSetting::current()->forceFill(['is_enabled' => false])->save();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $admin->id,
            'email_enabled' => true,
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-off', [
            'base_url' => 'https://waha-off.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);

        event(new ProviderHealthChanged(
            providerAccountId: $provider['id'],
            providerName: 'Off',
            providerSlug: 'waha-off',
            providerDriver: 'waha',
            providerUuid: (string) \Illuminate\Support\Facades\DB::table('provider_accounts')->where('id', $provider['id'])->value('uuid'),
            fromStatus: 'healthy',
            toStatus: 'degraded',
            consecutiveFailures: 1,
            circuitOpenUntilIso: null,
            errorSummary: null,
        ));

        Queue::assertNothingPushed();
        $this->assertSame(0, AlertDelivery::query()->count());
    }
}
```

Note: cooldown `hit()` must run when creating **pending** deliveries for a provider (first successful fan-out), not when all skipped.

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=SendProviderHealthAlertsTest`

Expected: FAIL

- [ ] **Step 3: Implement cooldown, listener, job shell, register listener**

Listener logic:

1. If `!AlertSetting::current()->is_enabled` return.
2. If `AlertCooldownStore::isCoolingDown($id)`: for each eligible (user, channel) create `skipped_cooldown` delivery; return (no jobs, no cooldown refresh).
3. Else: for each active admin (`is_admin && is_active`) with preference:
   - if telegram enabled + chat_id present → pending delivery + `DeliverProviderHealthAlert::dispatch($id)`
   - if email enabled → pending delivery (use override or user email) + dispatch
4. If at least one pending delivery created → `AlertCooldownStore::hit($id, $settings->cooldown_seconds)` (skip hit when cooldown_seconds === 0).

Job shell for now:

```php
public function handle(): void
{
    // Implemented in Task 4
}
```

`AppServiceProvider::boot`:

```php
Event::listen(ProviderHealthChanged::class, SendProviderHealthAlerts::class);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php artisan test --filter=SendProviderHealthAlertsTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
rtk git add app/Services/Alerts/AlertCooldownStore.php app/Listeners/SendProviderHealthAlerts.php app/Jobs/DeliverProviderHealthAlert.php app/Providers/AppServiceProvider.php tests/Feature/Alerts/SendProviderHealthAlertsTest.php
rtk git commit -m "$(cat <<'EOF'
feat(alerts): fan out health alerts with cooldown

EOF
)"
```

---

### Task 4: Telegram channel, mailer factory, delivery job

**Files:**
- Create: `app/Notifications/Channels/TelegramChannel.php`
- Create: `app/Notifications/ProviderHealthChangedNotification.php`
- Create: `app/Services/Alerts/AlertMailerFactory.php`
- Modify: `app/Jobs/DeliverProviderHealthAlert.php`
- Modify: `app/Models/User.php` (`routeNotificationForTelegram`, `routeNotificationForMail`)
- Modify: `app/Providers/AppServiceProvider.php` (`Notification::extend`)
- Test: `tests/Feature/Alerts/DeliverProviderHealthAlertTest.php`

**Interfaces:**
- Consumes: `AlertDelivery`, `AlertSetting`
- Produces:

```php
final class AlertMailerFactory
{
    public const MAILER_NAME = 'provider_health_alerts';

    public function registerFromSettings(AlertSetting $settings): void; // Config::set mailers + from; Mail::purge
}

final class TelegramChannel
{
    public function send(object $notifiable, Notification $notification): void;
}

// Notification methods:
public function via(object $notifiable): array; // single channel passed in ctor
public function toMail(object $notifiable): MailMessage;
public function toTelegram(object $notifiable): array; // ['text' => string, 'parse_mode' => null]
```

`DeliverProviderHealthAlert` constructor also needs channel from delivery row (load model).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Alerts;

use App\Jobs\DeliverProviderHealthAlert;
use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class DeliverProviderHealthAlertTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_telegram_delivery_marks_sent_and_posts_to_bot_api(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        AlertSetting::current()->forceFill([
            'is_enabled' => true,
            'telegram_bot_token' => '99:tok',
        ])->save();

        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $user->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '555',
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-tg', [
            'base_url' => 'https://waha-tg.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);
        $uuid = (string) \DB::table('provider_accounts')->where('id', $provider['id'])->value('uuid');

        $delivery = AlertDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'provider_account_id' => $provider['id'],
            'user_id' => $user->id,
            'channel' => 'telegram',
            'from_status' => 'healthy',
            'to_status' => 'unavailable',
            'consecutive_failures' => 3,
            'circuit_open_until' => now()->addMinutes(5),
            'error_summary' => 'down',
            'status' => 'pending',
        ]);

        (new DeliverProviderHealthAlert($delivery->id))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/bot99:tok/sendMessage')
            && $request['chat_id'] === '555'
            && str_contains($request['text'], 'UNAVAILABLE')
            && str_contains($request['text'], $uuid));

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->sent_at);
    }

    public function test_email_delivery_uses_runtime_smtp_mailer_not_log_mailer(): void
    {
        Mail::fake();

        AlertSetting::current()->forceFill([
            'is_enabled' => true,
            'smtp_host' => 'smtp.alerts.test',
            'smtp_port' => 587,
            'smtp_username' => 'u',
            'smtp_password' => 'p',
            'smtp_encryption' => 'tls',
            'smtp_from_address' => 'alerts@gateway.test',
            'smtp_from_name' => 'WAG Hub',
        ])->save();

        $user = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'email' => 'admin@example.test',
        ]);
        UserAlertPreference::query()->create([
            'user_id' => $user->id,
            'email_enabled' => true,
            'email_address' => 'ops@example.test',
        ]);

        $provider = $this->createProviderAccount('fonnte', 'fonnte-mail', [
            'endpoint' => 'https://fonnte-mail.test/send',
            'token' => 't',
        ]);

        $delivery = AlertDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'provider_account_id' => $provider['id'],
            'user_id' => $user->id,
            'channel' => 'email',
            'from_status' => 'degraded',
            'to_status' => 'healthy',
            'consecutive_failures' => 0,
            'circuit_open_until' => null,
            'error_summary' => null,
            'status' => 'pending',
        ]);

        (new DeliverProviderHealthAlert($delivery->id))->handle();

        Mail::assertSent(\App\Notifications\ProviderHealthChangedNotification::class /* won't work — use Notification::fake */);
    }
}
```

Prefer `Notification::fake()` for email assertion **or** assert `config('mail.mailers.provider_health_alerts.transport') === 'smtp'` and `host` after `AlertMailerFactory::registerFromSettings`, then use `Notification::route`/`Notification::sendNow` with `Mail::fake()` carefully.

Concrete email assertions:

```php
use Illuminate\Support\Facades\Notification;

Notification::fake();
(new DeliverProviderHealthAlert($delivery->id))->handle();
Notification::assertSentTo(
    $user,
    \App\Notifications\ProviderHealthChangedNotification::class,
    function ($notification, $channels) {
        return $channels === ['mail'];
    },
);
$this->assertSame('sent', $delivery->fresh()->status);
$this->assertSame('smtp.alerts.test', config('mail.mailers.provider_health_alerts.host'));
```

Add failure test: Telegram HTTP 500 → delivery `failed` with reason, no exception swallowed without update.

Add isolation test: two deliveries; telegram fails permanently; email still can succeed when its job runs separately.

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=DeliverProviderHealthAlertTest`

Expected: FAIL

- [ ] **Step 3: Implement channel, notification, factory, job**

`AlertMailerFactory::registerFromSettings`:

```php
config([
    'mail.mailers.'.self::MAILER_NAME => [
        'transport' => 'smtp',
        'host' => $settings->smtp_host,
        'port' => $settings->smtp_port,
        'encryption' => $settings->smtp_encryption,
        'username' => $settings->smtp_username,
        'password' => $settings->smtp_password,
        'timeout' => 30,
    ],
    'mail.from' => [
        'address' => $settings->smtp_from_address,
        'name' => $settings->smtp_from_name ?: 'WhatsApp Gateway Hub',
    ],
]);
app('mail.manager')->purge(self::MAILER_NAME);
```

If SMTP incomplete for email channel → mark delivery failed `"SMTP belum dikonfigurasi"` and return.

Telegram: `Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chatId, 'text' => $text])`; throw/`failed` if `!ok`.

Notification deep link:

```php
\App\Filament\Resources\ProviderAccounts\ProviderAccountResource::getUrl(
    'edit',
    ['record' => $providerAccountIdOrModel],
    panel: 'admin',
);
```

Load provider by id from delivery; if missing, still send text without link.

`User::routeNotificationForMail`: return preference email override or `$this->email`.

`User::routeNotificationForTelegram`: return chat id string.

`AppServiceProvider`:

```php
Notification::extend('telegram', fn ($app) => new TelegramChannel());
```

Job `failed(?Throwable $e)`: mark delivery failed if still pending.

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php artisan test --filter=DeliverProviderHealthAlertTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
rtk git add app/Notifications app/Services/Alerts/AlertMailerFactory.php app/Jobs/DeliverProviderHealthAlert.php app/Models/User.php app/Providers/AppServiceProvider.php tests/Feature/Alerts/DeliverProviderHealthAlertTest.php
rtk git commit -m "$(cat <<'EOF'
feat(alerts): deliver telegram and email health alerts

EOF
)"
```

---

### Task 5: Filament Alert Settings page

**Files:**
- Create: `app/Filament/Pages/ManageAlertSettings.php`
- Create: `app/Services/Alerts/AlertTestSender.php` (optional thin wrapper used by UI actions)
- Test: `tests/Feature/Admin/ManageAlertSettingsTest.php`

**Interfaces:**
- Consumes: `AlertSetting`, `AlertMailerFactory`, `TelegramChannel`/Http
- Produces: page slug `alert-settings`, nav group `Konfigurasi`, sort after providers

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AlertSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ManageAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_and_save_alert_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);

        $this->get('/panel/alert-settings')->assertOk();

        Livewire::test(\App\Filament\Pages\ManageAlertSettings::class)
            ->fillForm([
                'is_enabled' => true,
                'cooldown_seconds' => 120,
                'smtp_host' => 'smtp.example.test',
                'smtp_port' => 587,
                'smtp_from_address' => 'alerts@example.test',
                'telegram_bot_token' => '1:NEWTOKEN',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = AlertSetting::current()->fresh();
        $this->assertTrue($settings->is_enabled);
        $this->assertSame(120, $settings->cooldown_seconds);
        $this->assertSame('1:NEWTOKEN', $settings->telegram_bot_token);
    }

    public function test_blank_secret_fields_keep_existing_values(): void
    {
        AlertSetting::current()->forceFill([
            'telegram_bot_token' => 'keep-me',
            'smtp_password' => 'keep-pass',
        ])->save();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);

        Livewire::test(\App\Filament\Pages\ManageAlertSettings::class)
            ->fillForm([
                'telegram_bot_token' => '',
                'smtp_password' => '',
                'smtp_host' => 'smtp.example.test',
                'smtp_from_address' => 'a@b.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = AlertSetting::current()->fresh();
        $this->assertSame('keep-me', $settings->telegram_bot_token);
        $this->assertSame('keep-pass', $settings->smtp_password);
    }
}
```

Match Filament v4 page patterns used in this repo (Schema form, `Heroicon`, Indonesian labels). Use password fields that dehydrate empty as “unchanged”.

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=ManageAlertSettingsTest`

Expected: FAIL

- [ ] **Step 3: Implement page**

- Form sections: Umum (toggle + cooldown), SMTP, Telegram.
- Actions: Save; `Kirim email uji` (prompt destination); `Kirim Telegram uji` (prompt chat id).
- Tests ignore `is_enabled`; require channel config completeness.
- On test Telegram: Http fake in unit tests; real page action posts a short fixed message `WAG-Hub alert test`.

Register via `discoverPages` (already enabled in `AdminPanelProvider`).

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php artisan test --filter=ManageAlertSettingsTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
rtk git add app/Filament/Pages/ManageAlertSettings.php app/Services/Alerts/AlertTestSender.php tests/Feature/Admin/ManageAlertSettingsTest.php
rtk git commit -m "$(cat <<'EOF'
feat(alerts): add Filament global alert settings

EOF
)"
```

---

### Task 6: Filament My Alert Preferences page

**Files:**
- Create: `app/Filament/Pages/MyAlertPreferences.php`
- Test: `tests/Feature/Admin/MyAlertPreferencesTest.php`

**Interfaces:**
- Consumes: auth user + `UserAlertPreference`
- Produces: slug `my-alert-preferences`

- [ ] **Step 1: Write the failing test**

```php
public function test_admin_can_save_own_alert_preferences(): void
{
    $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
    $this->actingAs($admin);
    $this->get('/panel/my-alert-preferences')->assertOk();

    Livewire::test(\App\Filament\Pages\MyAlertPreferences::class)
        ->fillForm([
            'telegram_enabled' => true,
            'telegram_chat_id' => '4242',
            'email_enabled' => true,
            'email_address' => 'me@ops.test',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $pref = $admin->fresh()->alertPreference;
    $this->assertTrue($pref->telegram_enabled);
    $this->assertSame('4242', $pref->telegram_chat_id);
    $this->assertSame('me@ops.test', $pref->email_address);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=MyAlertPreferencesTest`

Expected: FAIL

- [ ] **Step 3: Implement page**

`firstOrNew` preference for auth id on mount. Nav label: `Preferensi Alert`. Optional test actions reuse `AlertTestSender` with the form’s chat id / email.

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php artisan test --filter=MyAlertPreferencesTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
rtk git add app/Filament/Pages/MyAlertPreferences.php tests/Feature/Admin/MyAlertPreferencesTest.php
rtk git commit -m "$(cat <<'EOF'
feat(alerts): add per-admin alert preferences page

EOF
)"
```

---

### Task 7: Alert Deliveries resource (read-only)

**Files:**
- Create: `app/Filament/Resources/AlertDeliveries/AlertDeliveryResource.php`
- Create: `app/Filament/Resources/AlertDeliveries/Pages/ListAlertDeliveries.php`
- Create: `app/Filament/Resources/AlertDeliveries/Pages/ViewAlertDelivery.php`
- Modify: `tests/Feature/Admin/AdminResourceSmokeTest.php`
- Test: `tests/Feature/Admin/AlertDeliveryResourceTest.php`

**Interfaces:**
- Produces: slug `alert-deliveries`, group `Operasi`, `canCreate/Edit/Delete = false`

- [ ] **Step 1: Write the failing test**

```php
public function test_admin_can_list_and_view_alert_deliveries(): void
{
    $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
    $delivery = AlertDelivery::query()->create([/* pending telegram row with provider */]);

    $this->actingAs($admin)
        ->get('/panel/alert-deliveries')
        ->assertOk();

    $this->actingAs($admin)
        ->get('/panel/alert-deliveries/'.$delivery->uuid)
        ->assertOk()
        ->assertSee('telegram');
}

public function test_alert_deliveries_resource_is_read_only(): void
{
    $this->assertFalse(AlertDeliveryResource::canCreate());
}
```

Add smoke paths:

```php
'alert settings' => ['/panel/alert-settings'],
'my alert preferences' => ['/panel/my-alert-preferences'],
'alert deliveries' => ['/panel/alert-deliveries'],
```

Use `uuid` as record route key (`getRouteKeyName(): string { return 'uuid'; }` on model or resource).

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=AlertDeliveryResourceTest`

Expected: FAIL

- [ ] **Step 3: Implement resource**

Mirror `NumberCheckRequestResource` patterns: table filters for channel/status/provider/date, view infolist with from→to, error_summary, failure_reason, sent_at. No create/edit pages.

- [ ] **Step 4: Run tests**

Run:

```bash
rtk php artisan test --filter=AlertDeliveryResourceTest
rtk php artisan test --filter=AdminResourceSmokeTest
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
rtk git add app/Filament/Resources/AlertDeliveries tests/Feature/Admin/AlertDeliveryResourceTest.php tests/Feature/Admin/AdminResourceSmokeTest.php app/Models/AlertDelivery.php
rtk git commit -m "$(cat <<'EOF'
feat(alerts): add read-only alert delivery history

EOF
)"
```

---

### Task 8: End-to-end health → alert integration + graphify

**Files:**
- Test: `tests/Feature/Alerts/ProviderHealthAlertIntegrationTest.php`
- Possibly tweak thresholds only in test

**Interfaces:**
- Consumes: full pipeline from Task 2–4

- [ ] **Step 1: Write the failing test**

Drive real `ProviderHealthRecorder` / message send until `unavailable`, with Queue sync (`Queue::fake` then `Bus::dispatchSync` **or** `Illuminate\Support\Facades\Queue::except` — simpler: `Queue::fake()` then manually run pushed jobs, **or** set `QUEUE_CONNECTION=sync` via `config()->set('queue.default', 'sync')` and `Notification`/`Http` fakes).

Preferred:

```php
config()->set('queue.default', 'sync');
Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
Notification::fake(); // if email also on — or only telegram enabled

// setup settings + admin telegram pref + failure_threshold=1
// call recorder or postMessage until unavailable
$this->assertDatabaseHas('alert_deliveries', [
    'channel' => 'telegram',
    'to_status' => 'unavailable',
    'status' => 'sent',
]);
```

Also assert recovery creates another sent delivery after cooldown cleared (`Cache::flush()` or `cooldown_seconds = 0`).

- [ ] **Step 2: Run test to verify it fails** (if any wiring gap)

Run: `rtk php artisan test --filter=ProviderHealthAlertIntegrationTest`

- [ ] **Step 3: Fix any gaps found (listener registration, sync queue, missing fields)**

- [ ] **Step 4: Full alert suite + related regression**

Run:

```bash
rtk php artisan test --filter=Alerts
rtk php artisan test --filter=ProviderCircuitBreakerTest
rtk php artisan test --filter=ProviderAccountTestActionTest
rtk php artisan test --filter=AdminResourceSmokeTest
```

Expected: all PASS

- [ ] **Step 5: Update graphify + commit**

```bash
graphify update .
rtk git add tests/Feature/Alerts/ProviderHealthAlertIntegrationTest.php graphify-out
rtk git commit -m "$(cat <<'EOF'
test(alerts): cover health change to delivery path

EOF
)"
```

---

## Self-review (plan vs spec)

| Spec requirement | Task |
|---|---|
| Alert every status change incl. recovery | Task 2 + 8 |
| Telegram + Email | Task 4 |
| UI SMTP + bot token (not env) | Task 1 + 5 |
| Global sender / per-admin destinations | Task 5 + 6 |
| Cooldown + skipped rows | Task 3 |
| Rich body + deep link | Task 4 |
| Alert deliveries history | Task 7 |
| Test buttons ignore master switch | Task 5/6 (`AlertTestSender`) |
| Encrypted secrets | Task 1 |
| After-commit event | Task 2 (`ShouldDispatchAfterCommit`) |
| Channel isolation | Task 4 tests |

No TBD placeholders remain. Named mailer constant `provider_health_alerts` consistent across Task 4–5.
