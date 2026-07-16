<?php

namespace Database\Seeders;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\RoutingStep;
use App\Models\User;
use Illuminate\Database\Seeder;

class GatewayHubManagementSeeder extends Seeder
{
    /** @var array<string, array{name: string, rate_limit_per_minute: int}> */
    private const APPLICATIONS = [
        'appscript-ft' => ['name' => 'AppScript FT', 'rate_limit_per_minute' => 120],
        'web-cesa' => ['name' => 'Web CESA', 'rate_limit_per_minute' => 120],
        'web-helpdesk' => ['name' => 'Web Helpdesk', 'rate_limit_per_minute' => 120],
        'web-sam' => ['name' => 'Web SAM', 'rate_limit_per_minute' => 120],
        'web-shelf' => ['name' => 'Web Shelf', 'rate_limit_per_minute' => 120],
    ];

    /** @var array<string, string> */
    private const DEFAULT_ROUTE_KEYS = [
        'appscript-ft' => 'default',
        'web-cesa' => 'web-cesa-messages',
        'web-helpdesk' => 'default',
        'web-sam' => 'default',
        'web-shelf' => 'shelf-notifications',
    ];

    public function run(): void
    {
        $this->seedAdministrator();

        $applications = $this->seedApplications();
        $providers = $this->seedProviders();

        $this->seedDefaultRoutes($applications, $providers);

        $this->seedApplicationCredentials($applications);
    }

    private function seedAdministrator(): void
    {
        $administrator = config('gateway.seed.administrator', []);
        $name = $this->value($administrator['name'] ?? null);
        $email = $this->value($administrator['email'] ?? null);
        $password = $this->value($administrator['password'] ?? null);

        if ($name === null && $email === null && $password === null) {
            return;
        }

        if ($name === null || $email === null || $password === null) {
            throw new \InvalidArgumentException(
                'GATEWAY_SEED_ADMIN_NAME, GATEWAY_SEED_ADMIN_EMAIL, dan GATEWAY_SEED_ADMIN_PASSWORD harus diisi bersama.',
            );
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'is_admin' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, ClientApplication>
     */
    private function seedApplications(): array
    {
        $applications = [];

        foreach (self::APPLICATIONS as $slug => $attributes) {
            $applications[$slug] = ClientApplication::query()->updateOrCreate(
                ['slug' => $slug],
                [...$attributes, 'is_active' => true],
            );
        }

        return $applications;
    }

    /**
     * @return list<ProviderAccount>
     */
    private function seedProviders(): array
    {
        $providers = [];
        $waha = config('gateway.seed.providers.waha', []);

        if ($this->hasValues($waha, ['base_url', 'session', 'api_key'])) {
            $providers[] = ProviderAccount::query()->updateOrCreate(
                ['slug' => 'waha-primary'],
                [
                    'name' => 'WAHA Primary',
                    'driver' => 'waha',
                    'configuration' => [
                        'base_url' => $this->value($waha['base_url']),
                        'session' => $this->value($waha['session']),
                        'api_key' => $this->value($waha['api_key']),
                    ],
                    'is_active' => true,
                    'health_status' => 'unknown',
                    'consecutive_failures' => 0,
                    'circuit_open_until' => null,
                    'timeout_seconds' => 15,
                ],
            );
        } else {
            $providers[] = ProviderAccount::query()->firstOrCreate(
                ['slug' => 'waha-primary'],
                [
                    'name' => 'WAHA Primary',
                    'driver' => 'waha',
                    'configuration' => [],
                    'is_active' => false,
                    'health_status' => 'unknown',
                    'consecutive_failures' => 0,
                    'timeout_seconds' => 15,
                ],
            );
        }

        $fonnte = config('gateway.seed.providers.fonnte', []);

        if ($this->hasValues($fonnte, ['endpoint', 'token'])) {
            $providers[] = ProviderAccount::query()->updateOrCreate(
                ['slug' => 'fonnte-primary'],
                [
                    'name' => 'Fonnte Primary',
                    'driver' => 'fonnte',
                    'configuration' => [
                        'endpoint' => $this->value($fonnte['endpoint']),
                        'validate_endpoint' => $this->value(
                            $fonnte['validate_endpoint'] ?? 'https://api.fonnte.com/validate',
                        ),
                        'token' => $this->value($fonnte['token']),
                    ],
                    'is_active' => true,
                    'health_status' => 'unknown',
                    'consecutive_failures' => 0,
                    'circuit_open_until' => null,
                    'timeout_seconds' => 15,
                ],
            );
        } else {
            $providers[] = ProviderAccount::query()->firstOrCreate(
                ['slug' => 'fonnte-primary'],
                [
                    'name' => 'Fonnte Primary',
                    'driver' => 'fonnte',
                    'configuration' => [
                        'endpoint' => 'https://api.fonnte.com/send',
                        'validate_endpoint' => 'https://api.fonnte.com/validate',
                    ],
                    'is_active' => false,
                    'health_status' => 'unknown',
                    'consecutive_failures' => 0,
                    'timeout_seconds' => 15,
                ],
            );
        }

        $gowa = config('gateway.seed.providers.gowa', []);

        if ($this->hasValues($gowa, ['base_url', 'username', 'password'])) {
            $providers[] = ProviderAccount::query()->updateOrCreate(
                ['slug' => 'gowa-primary'],
                [
                    'name' => 'GOWA Primary',
                    'driver' => 'gowa',
                    'configuration' => array_filter([
                        'base_url' => $this->value($gowa['base_url']),
                        'username' => $this->value($gowa['username']),
                        'password' => $this->value($gowa['password']),
                        'device_id' => $this->value($gowa['device_id'] ?? null),
                    ], static fn (?string $value): bool => $value !== null),
                    'is_active' => true,
                    'health_status' => 'unknown',
                    'consecutive_failures' => 0,
                    'circuit_open_until' => null,
                    'timeout_seconds' => 15,
                ],
            );
        } else {
            $providers[] = ProviderAccount::query()->firstOrCreate(
                ['slug' => 'gowa-primary'],
                [
                    'name' => 'GOWA Primary',
                    'driver' => 'gowa',
                    'configuration' => [],
                    'is_active' => false,
                    'health_status' => 'unknown',
                    'consecutive_failures' => 0,
                    'timeout_seconds' => 15,
                ],
            );
        }

        $waba = config('gateway.seed.providers.waba', []);

        if ($this->hasValues($waba, ['base_url', 'api_version', 'phone_number_id', 'access_token'])) {
            $providers[] = ProviderAccount::query()->updateOrCreate(
                ['slug' => 'waba-primary'],
                [
                    'name' => 'WABA Primary',
                    'driver' => 'waba',
                    'configuration' => [
                        'base_url' => $this->value($waba['base_url']),
                        'api_version' => $this->value($waba['api_version']),
                        'phone_number_id' => $this->value($waba['phone_number_id']),
                        'access_token' => $this->value($waba['access_token']),
                    ],
                    'is_active' => true,
                    'health_status' => 'unknown',
                    'consecutive_failures' => 0,
                    'circuit_open_until' => null,
                    'timeout_seconds' => 15,
                ],
            );
        } else {
            $providers[] = ProviderAccount::query()->firstOrCreate(
                ['slug' => 'waba-primary'],
                [
                    'name' => 'WABA Primary',
                    'driver' => 'waba',
                    'configuration' => [
                        'base_url' => $this->value($waba['base_url'] ?? null) ?? 'https://graph.facebook.com',
                        'api_version' => $this->value($waba['api_version'] ?? null) ?? 'v25.0',
                    ],
                    'is_active' => false,
                    'health_status' => 'unknown',
                    'consecutive_failures' => 0,
                    'timeout_seconds' => 15,
                ],
            );
        }

        return $providers;
    }

    /**
     * @param  array<string, ClientApplication>  $applications
     * @param  list<ProviderAccount>  $providers
     */
    private function seedDefaultRoutes(array $applications, array $providers): void
    {
        foreach ($applications as $slug => $application) {
            $routeKey = self::DEFAULT_ROUTE_KEYS[$slug];

            $policy = RoutingPolicy::query()->updateOrCreate(
                [
                    'client_application_id' => $application->id,
                    'operation' => 'message',
                    'key' => $routeKey,
                    'purpose' => null,
                ],
                [
                    'name' => $application->name.' default WhatsApp route',
                    'is_default' => true,
                    'is_active' => true,
                ],
            );

            foreach ($providers as $position => $provider) {
                RoutingStep::query()->updateOrCreate(
                    [
                        'routing_policy_id' => $policy->id,
                        'provider_account_id' => $provider->id,
                    ],
                    [
                        'position' => $position + 1,
                        'is_active' => true,
                    ],
                );
            }

            if ($slug === 'web-cesa') {
                $this->seedWebCesaNumberCheckRoute($application, $providers);
            }
        }

        $this->retireNumberCheckRoutesOutsideWebCesa($applications['web-cesa']);
    }

    /**
     * @param  list<ProviderAccount>  $providers
     */
    private function seedWebCesaNumberCheckRoute(
        ClientApplication $application,
        array $providers,
    ): void {
        $numberCheckPolicy = RoutingPolicy::query()->updateOrCreate(
            [
                'client_application_id' => $application->id,
                'operation' => 'number_check',
                'key' => 'lead-number-check',
                'purpose' => null,
            ],
            [
                'name' => 'Web CESA Lead WhatsApp number-check route',
                'is_default' => true,
                'is_active' => true,
            ],
        );
        $checkPosition = 1;

        foreach ($providers as $provider) {
            if ((string) $provider->driver === 'waba') {
                continue;
            }

            RoutingStep::query()->updateOrCreate(
                [
                    'routing_policy_id' => $numberCheckPolicy->id,
                    'provider_account_id' => $provider->id,
                ],
                [
                    'is_active' => true,
                    'position' => $checkPosition++,
                ],
            );
        }
    }

    private function retireNumberCheckRoutesOutsideWebCesa(ClientApplication $webCesa): void
    {
        RoutingPolicy::query()
            ->where('operation', 'number_check')
            ->where(function ($query) use ($webCesa): void {
                $query
                    ->whereNull('client_application_id')
                    ->orWhere('client_application_id', '!=', $webCesa->id);
            })
            ->delete();
    }

    /**
     * @param  array<string, ClientApplication>  $applications
     */
    private function seedApplicationCredentials(array $applications): void
    {
        $credentials = config('gateway.seed.credentials', []);

        foreach ($applications as $slug => $application) {
            $token = $this->value($credentials[$slug] ?? null);

            if ($token === null) {
                continue;
            }

            ApiCredential::query()->updateOrCreate(
                [
                    'client_application_id' => $application->id,
                    'name' => 'Seeded application token',
                ],
                [
                    'token_hash' => hash('sha256', $token),
                    'token_prefix' => substr($token, 0, 16),
                    'abilities' => $slug === 'web-cesa'
                        ? ['messages:send', 'messages:read', 'numbers:check']
                        : ['messages:send', 'messages:read'],
                    'revoked_at' => null,
                    'expires_at' => null,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function hasValues(array $values, array $keys): bool
    {
        return collect($keys)->every(fn (string $key): bool => $this->value($values[$key] ?? null) !== null);
    }

    private function value(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
