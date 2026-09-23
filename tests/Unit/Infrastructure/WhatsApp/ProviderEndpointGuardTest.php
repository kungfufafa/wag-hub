<?php

namespace Tests\Unit\Infrastructure\WhatsApp;

use App\Infrastructure\WhatsApp\ProviderEndpointGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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
}
