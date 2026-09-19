<?php

namespace Tests\Unit\Support;

use App\Support\ApplicationErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationErrorMapperTest extends TestCase
{
    #[DataProvider('mappingCases')]
    public function test_maps_internal_codes_to_application_actions(string $internal, string $action): void
    {
        $this->assertSame($action, ApplicationErrorMapper::actionFor($internal));
        $this->assertSame($internal, ApplicationErrorMapper::payload($internal)['code']);
        $this->assertSame($action, ApplicationErrorMapper::payload($internal)['action']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mappingCases(): array
    {
        return [
            'route' => ['route_unavailable', 'connection_not_ready'],
            'providers failed' => ['providers_failed', 'delivery_failed'],
            'outcome unknown' => ['provider_outcome_unknown', 'delivery_outcome_unknown'],
            'expired' => ['message_expired', 'message_expired'],
        ];
    }
}
