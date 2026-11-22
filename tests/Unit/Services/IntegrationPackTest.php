<?php

namespace Tests\Unit\Services;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Services\IntegrationPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_application_gets_only_a_url_and_token(): void
    {
        foreach (['web-cesa', 'cesa-web', 'dnd-web'] as $slug) {
            $env = app(IntegrationPack::class)->envSnippet(new ClientApplication(['slug' => $slug]), 'https://hub.test/', 'wgh_token');

            $this->assertSame("WAG_URL=https://hub.test\nWAG_TOKEN=wgh_token", $env);
        }
    }

    public function test_pack_issues_one_application_scoped_credential_and_does_not_revoke_existing_tokens(): void
    {
        $application = ClientApplication::query()->create([
            'name' => 'DND', 'slug' => 'dnd-web', 'is_active' => true, 'rate_limit_per_minute' => 60,
        ]);
        $existing = ApiCredential::issue($application, 'Existing engine', ['engine:use']);
        $pack = app(IntegrationPack::class)->issue($application, 'https://hub.test');

        $this->assertSame($application->getKey(), $pack['credential']->credential->client_application_id);
        $this->assertEqualsCanonicalizing([
            'integration:read', 'sessions:read', 'sessions:manage', 'messages:read', 'messages:send',
            'media:read', 'media:write', 'webhooks:manage', 'capacity:manage', 'numbers:check', 'engine:use',
        ], $pack['credential']->credential->abilities);
        $this->assertStringContainsString('WAG_TOKEN='.$pack['credential']->plainTextToken, $pack['env']);
        $this->assertNull($existing->credential->fresh()->revoked_at);
        $this->assertSame(2, $application->apiCredentials()->count());
    }

    public function test_copy_or_issue_reuses_an_existing_full_pack_token_instead_of_minting_another(): void
    {
        $application = ClientApplication::query()->create([
            'name' => 'HR', 'slug' => 'hr-web', 'is_active' => true, 'rate_limit_per_minute' => 60,
        ]);
        app(IntegrationPack::class)->issue($application);

        $pack = app(IntegrationPack::class)->copyOrIssue($application, 'https://hub.test');

        $this->assertFalse($pack['issued']);
        $this->assertNull($pack['credential']);
        $this->assertStringContainsString('WAG_TOKEN=wgh_', $pack['env']);
        $this->assertStringContainsString('…', $pack['env']);
        $this->assertStringContainsString('tidak bisa ditampilkan ulang', $pack['env']);
        $this->assertSame(1, $application->apiCredentials()->count());
    }

    public function test_copy_or_issue_mints_a_v2_capable_token_when_existing_ones_lack_pack_abilities(): void
    {
        $application = ClientApplication::query()->create([
            'name' => 'HR', 'slug' => 'hr-web', 'is_active' => true, 'rate_limit_per_minute' => 60,
        ]);
        $legacy = ApiCredential::issue($application, 'Pre-v2 WAG_TOKEN', ['messages:send', 'messages:read', 'numbers:check', 'engine:use']);

        $pack = app(IntegrationPack::class)->copyOrIssue($application, 'https://hub.test');

        $this->assertTrue($pack['issued']);
        $this->assertNotNull($pack['credential']);
        $this->assertTrue($pack['credential']->credential->hasAbility('sessions:manage'));
        $this->assertTrue($pack['credential']->credential->hasAbility('messages:send'));
        $this->assertStringContainsString('WAG_TOKEN='.$pack['credential']->plainTextToken, $pack['env']);
        $this->assertStringNotContainsString('…', $pack['env']);
        $this->assertNull($legacy->credential->fresh()->revoked_at);
        $this->assertSame(2, $application->apiCredentials()->count());
    }
}
