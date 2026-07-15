<?php

namespace Tests\Feature\Security;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiCredentialSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_a_credential_returns_plaintext_once_and_persists_only_its_hash(): void
    {
        $application = ClientApplication::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => 'Web Shelf',
            'slug' => 'web-shelf',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);

        $issued = ApiCredential::issue(
            application: $application,
            name: 'Pilot credential',
            abilities: ['messages:send', 'messages:read'],
        );

        $credential = $issued->credential;
        $plainTextToken = $issued->plainTextToken;

        $this->assertInstanceOf(ApiCredential::class, $credential);
        $this->assertIsString($plainTextToken);
        $this->assertGreaterThanOrEqual(32, strlen($plainTextToken));
        $this->assertStringStartsWith($credential->token_prefix, $plainTextToken);

        $raw = DB::table('api_credentials')->where('id', $credential->id)->first();

        $this->assertNotNull($raw);
        $this->assertSame(hash('sha256', $plainTextToken), $raw->token_hash);
        $this->assertSame(64, strlen($raw->token_hash));
        $this->assertObjectNotHasProperty('token', $raw);
        $this->assertObjectNotHasProperty('plain_text_token', $raw);
        $this->assertStringNotContainsString($plainTextToken, json_encode($raw, JSON_THROW_ON_ERROR));

        $reloaded = $credential->fresh();
        $serialized = $reloaded->toArray();

        $this->assertArrayNotHasKey('token', $serialized);
        $this->assertArrayNotHasKey('plain_text_token', $serialized);
        $this->assertStringNotContainsString(
            $plainTextToken,
            json_encode($serialized, JSON_THROW_ON_ERROR),
        );
    }

    public function test_each_issued_credential_uses_a_new_plaintext_token(): void
    {
        $application = ClientApplication::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => 'Web Helpdesk',
            'slug' => 'web-helpdesk',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);

        $first = ApiCredential::issue($application, 'Primary', ['messages:send']);
        $second = ApiCredential::issue($application, 'Replacement', ['messages:send']);

        $this->assertNotSame($first->plainTextToken, $second->plainTextToken);
        $this->assertNotSame(
            $first->credential->getRawOriginal('token_hash'),
            $second->credential->getRawOriginal('token_hash'),
        );
    }
}
