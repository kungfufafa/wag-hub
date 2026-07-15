<?php

namespace Tests\Feature\Security;

use App\Models\MessageAttempt;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ProviderErrorPersistenceTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_dispatcher_persists_provider_error_text_through_the_encrypted_model_cast(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-error-encryption', [
            'base_url' => 'https://waha-error-encryption.test',
            'api_key' => 'test-key',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake([
            'waha-error-encryption.test/*' => Http::response([
                'error' => 'Reflected OTP 918273 must remain encrypted',
            ], 401),
        ]);

        $this->postMessage(
            $client['token'],
            'encrypted-dispatch-error-1',
            $this->messagePayload('sync'),
        )->assertStatus(503);

        $attemptId = DB::table('message_attempts')->value('id');
        $rawError = DB::table('message_attempts')->where('id', $attemptId)->value('error_message');

        $this->assertStringNotContainsString('918273', $rawError);
        $this->assertSame(
            'Reflected OTP 918273 must remain encrypted',
            MessageAttempt::query()->findOrFail($attemptId)->error_message,
        );
    }
}
