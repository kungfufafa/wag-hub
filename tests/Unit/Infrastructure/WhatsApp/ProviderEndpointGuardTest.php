<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Infrastructure\WhatsApp\ProviderEndpointGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderEndpointGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('gateway.provider_endpoints.https_hosts', [
            'api.fonnte.com',
            'waha.example.com',
        ]);
        config()->set('gateway.provider_endpoints.http_hosts', [
            'waha.internal.example',
        ]);
    }

    public function test_exact_allowlisted_https_hosts_are_allowed_by_default(): void
    {
        $guard = app(ProviderEndpointGuard::class);

        $guard->assertAllowed('https://api.fonnte.com/send');
        $guard->assertAllowed('https://WAHA.EXAMPLE.COM/api');

        $this->addToAssertionCount(2);
    }

    public function test_plain_http_requires_an_explicit_http_host_allowlist(): void
    {
        $guard = app(ProviderEndpointGuard::class);

        $guard->assertAllowed('http://waha.internal.example/api/sendText');
        $this->addToAssertionCount(1);

        $this->expectException(InvalidArgumentException::class);
        $guard->assertAllowed('http://api.fonnte.com/send');
    }

    #[DataProvider('blockedEndpoints')]
    public function test_arbitrary_subdomain_metadata_and_userinfo_endpoints_are_blocked(string $endpoint): void
    {
        config()->set('gateway.provider_endpoints.https_hosts', array_merge(
            config('gateway.provider_endpoints.https_hosts'),
            ['169.254.169.254', 'metadata.google.internal', 'localhost'],
        ));

        $this->expectException(InvalidArgumentException::class);

        app(ProviderEndpointGuard::class)->assertAllowed($endpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedEndpoints(): iterable
    {
        yield 'arbitrary host' => ['https://attacker.example/send'];
        yield 'subdomain is not an exact match' => ['https://sub.api.fonnte.com/send'];
        yield 'allowlisted hostname suffix trick' => ['https://api.fonnte.com.attacker.example/send'];
        yield 'cloud metadata IP' => ['https://169.254.169.254/latest/meta-data'];
        yield 'cloud metadata hostname' => ['https://metadata.google.internal/computeMetadata/v1'];
        yield 'localhost' => ['https://localhost/internal'];
        yield 'loopback IPv4' => ['https://127.0.0.1/internal'];
        yield 'loopback IPv6' => ['https://[::1]/internal'];
        yield 'userinfo confusion' => ['https://attacker@api.fonnte.com/send'];
        yield 'unsupported scheme' => ['ftp://api.fonnte.com/send'];
    }
}
