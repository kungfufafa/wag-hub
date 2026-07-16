<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class MessageValidationTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_a_client_cannot_select_a_provider_or_submit_multiple_targets(): void
    {
        Queue::fake();
        Http::fake();
        $client = $this->createClientApplication();

        $payload = $this->messagePayload(overrides: [
            'provider_account_id' => 999,
            'recipients' => ['081234567890', '081298765432'],
        ]);

        $this->postMessage($client['token'], 'forged-provider-1', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors(['provider_account_id', 'recipients']);

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_raw_jids_groups_and_non_indonesian_numbers_are_rejected(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();

        foreach ([
            '6281234567890@c.us',
            '120363012345678901@g.us',
            '+14155552671',
        ] as $index => $recipient) {
            $this->postMessage(
                $client['token'],
                "invalid-recipient-{$index}",
                $this->messagePayload(overrides: [
                    'recipient' => ['type' => 'phone', 'value' => $recipient],
                ]),
            )
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonValidationErrors('recipient.value');
        }

        $this->assertSame(0, DB::table('gateway_messages')->count());
        Queue::assertNothingPushed();
    }

    public function test_otp_requires_a_future_expiry_before_any_message_is_recorded(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();

        foreach ([null, now()->subSecond()->toIso8601String()] as $index => $expiresAt) {
            $payload = $this->messagePayload('sync', [
                'purpose' => 'otp',
                'expires_at' => $expiresAt,
            ]);

            $this->postMessage($client['token'], "invalid-otp-expiry-{$index}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonValidationErrors('expires_at');
        }

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_oversized_metadata_and_invalid_route_key_are_rejected_before_persistence(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();

        $this->postMessage($client['token'], 'oversized-metadata', $this->messagePayload(overrides: [
            'metadata' => ['context' => str_repeat('x', 8193)],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metadata');

        $this->postMessage($client['token'], 'invalid-route-key', $this->messagePayload(overrides: [
            'route_key' => 'route key with spaces',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('route_key');

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
    }
}
