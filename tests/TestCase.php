<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Config default is async (production-safe). Tests force async so Queue::fake() stays meaningful
        // even if a local .env sets GATEWAY_DISPATCH=sync.
        config(['gateway.dispatch' => 'async']);
    }
}
