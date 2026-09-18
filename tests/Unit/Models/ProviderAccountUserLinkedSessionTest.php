<?php

namespace Tests\Unit\Models;

use App\Models\ProviderAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderAccountUserLinkedSessionTest extends TestCase
{
    #[DataProvider('linkedSessionProvider')]
    public function test_detects_sessions_created_when_an_app_user_links_a_number(
        array $attributes,
        bool $expected,
    ): void {
        $account = new ProviderAccount($attributes);

        $this->assertSame($expected, $account->isUserLinkedSession());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function linkedSessionProvider(): array
    {
        return [
            'host waha machine' => [
                [
                    'slug' => 'waha-engine',
                    'configuration' => [
                        'base_url' => 'https://waha-engine.test',
                        'session' => 'default',
                    ],
                ],
                false,
            ],
            'slug from engine provision' => [
                [
                    'slug' => 'web-cesa-sess-rekrutmen-12',
                    'configuration' => ['session' => 'rekrutmen-12'],
                ],
                true,
            ],
            'owned by application' => [
                [
                    'slug' => 'helpdesk-number',
                    'configuration' => ['owned_by_application_id' => 4],
                ],
                true,
            ],
            'cesa session id' => [
                [
                    'slug' => 'legacy-linked',
                    'configuration' => ['cesa_session_id' => 'rekrutmen-9'],
                ],
                true,
            ],
        ];
    }
}
