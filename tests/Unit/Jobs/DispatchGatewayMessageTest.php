<?php

namespace Tests\Unit\Jobs;

use App\Jobs\DispatchGatewayMessage;
use PHPUnit\Framework\TestCase;

class DispatchGatewayMessageTest extends TestCase
{
    public function test_the_job_allows_a_layered_route_to_finish_before_the_queue_retries_it(): void
    {
        $job = new DispatchGatewayMessage(123);

        self::assertSame(1, $job->tries);
        self::assertGreaterThanOrEqual(300, $job->timeout);
    }
}
