<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Infrastructure\WhatsApp\HostResolver;
use App\Infrastructure\WhatsApp\ProviderEndpointGuard;
use App\Models\ProviderAccount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProviderEndpointGuardTest extends TestCase
{
    public function test_public_http_and_https_hosts_are_allowed_without_a_host_list(): void
    {
        $guard = app(ProviderEndpointGuard::class);

        $guard->assertAllowed('https://waha.completeselular.com/api/sendText');
        $guard->assertAllowed('https://api.fonnte.com/send');
        $guard->assertAllowed('http://waha.internal.example/api/sendText');
        $guard->assertAllowed('https://sub.api.fonnte.com/send');

        $this->addToAssertionCount(4);
    }

    #[DataProvider('blockedEndpoints')]
    public function test_private_metadata_and_malformed_endpoints_are_blocked(string $endpoint): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProviderEndpointGuard::class)->assertAllowed($endpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedEndpoints(): iterable
    {
        yield 'cloud metadata IP' => ['https://169.254.169.254/latest/meta-data'];
        yield 'cloud metadata hostname' => ['https://metadata.google.internal/computeMetadata/v1'];
        yield 'localhost' => ['https://localhost/internal'];
        yield 'localhost subdomain' => ['https://app.localhost/internal'];
        yield 'loopback IPv4' => ['https://127.0.0.1/internal'];
        yield 'loopback IPv6' => ['https://[::1]/internal'];
        yield 'private IPv4' => ['http://10.0.0.8/api/sendText'];
        yield 'userinfo confusion' => ['https://attacker@api.fonnte.com/send'];
        yield 'unsupported scheme' => ['ftp://api.fonnte.com/send'];
    }

    public function test_admin_accounts_keep_hostname_endpoints_without_resolving_them(): void
    {
        $guard = new ProviderEndpointGuard(new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                throw new RuntimeException('Admin endpoints are not resolved.');
            }
        });

        $admin = new ProviderAccount(['slug' => 'waha-main']);

        $this->assertSame([], $guard->assertAllowed('http://waha:3000/api/sendText', $admin));
        $this->assertSame([], $guard->assertAllowed('http://waha:3000/api/sendText'));
    }

    #[DataProvider('nonPublicTenantEndpoints')]
    public function test_tenant_endpoints_must_resolve_to_public_addresses(string $endpoint): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->guardResolving([
            'internal.example.com' => ['10.1.2.3'],
            'mixed.example.com' => ['93.184.216.34', '127.0.0.1'],
            'cgnat.example.com' => ['100.64.0.1'],
            'ula.example.com' => ['fd00::1'],
            'nowhere.example.com' => [],
        ])->assertAllowed($endpoint, $this->tenantAccount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublicTenantEndpoints(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/send'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'private' => ['http://10.0.0.5/send'];
        yield 'cgnat literal' => ['http://100.64.0.1/send'];
        yield 'unspecified' => ['http://0.0.0.0/send'];
        yield 'reserved' => ['http://240.0.0.1/send'];
        yield 'decimal loopback' => ['http://2130706433/send'];
        yield 'hex loopback' => ['http://0x7f000001/send'];
        yield 'short loopback' => ['http://127.1/send'];
        yield 'link-local IPv6' => ['http://[fe80::1]/send'];
        yield 'unique-local IPv6' => ['http://[fd12::1]/send'];
        yield 'unspecified IPv6' => ['http://[::]/send'];
        yield 'mapped IPv6 loopback' => ['http://[::ffff:127.0.0.1]/send'];
        yield 'hostname resolving to private' => ['https://internal.example.com/send'];
        yield 'hostname with one private record' => ['https://mixed.example.com/send'];
        yield 'hostname resolving to CGNAT' => ['https://cgnat.example.com/send'];
        yield 'hostname resolving to unique-local' => ['https://ula.example.com/send'];
        yield 'unresolvable hostname' => ['https://nowhere.example.com/send'];
        yield 'trailing-dot hostname that would skip the pin' => ['http://api.fonnte.com.:8080/send'];
        yield 'trailing-dot IPv4' => ['http://93.184.216.34./send'];
    }

    public function test_tenant_endpoints_are_pinned_to_the_checked_address(): void
    {
        $options = $this->guardResolving(['api.fonnte.com' => ['93.184.216.34']])
            ->assertPublic('https://api.fonnte.com/send');

        $this->assertSame('', $options['proxy']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['api.fonnte.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    /**
     * @param  array<string, list<string>>  $records
     */
    private function guardResolving(array $records): ProviderEndpointGuard
    {
        return new ProviderEndpointGuard(new class($records) extends HostResolver
        {
            /** @param array<string, list<string>> $records */
            public function __construct(private array $records) {}

            public function resolve(string $host): array
            {
                return $this->records[$host] ?? ['93.184.216.34'];
            }
        });
    }

    private function tenantAccount(): ProviderAccount
    {
        return new ProviderAccount(['slug' => ProviderAccount::CONNECTION_SLUG_PREFIX.'abc']);
    }
}
