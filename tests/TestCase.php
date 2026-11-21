<?php

namespace Tests;

use App\Infrastructure\WhatsApp\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Config default is async (production-safe). Tests force async so Queue::fake() stays meaningful
        // even if a local .env sets GATEWAY_DISPATCH=sync.
        config(['gateway.dispatch' => 'async']);

        // Tests never hit real DNS: tenant endpoint checks see a public address unless a test overrides it.
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }
}
