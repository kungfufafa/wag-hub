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

            $this->assertSame("# {$slug}\nWAG_URL=https://hub.test\nWAG_TOKEN=wgh_token", $env);
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
        $this->assertSame(['messages:send', 'messages:read', 'numbers:check', 'engine:use'], $pack['credential']->credential->abilities);
        $this->assertStringContainsString('WAG_TOKEN='.$pack['credential']->plainTextToken, $pack['env']);
        $this->assertNull($existing->credential->fresh()->revoked_at);
        $this->assertSame(2, $application->apiCredentials()->count());
    }

    public function test_copy_or_issue_reuses_an_existing_token_instead_of_minting_another(): void
    {
        $application = ClientApplication::query()->create([
            'name' => 'HR', 'slug' => 'hr-web', 'is_active' => true, 'rate_limit_per_minute' => 60,
        ]);
        ApiCredential::issue($application, 'Existing', ['messages:send']);

        $pack = app(IntegrationPack::class)->copyOrIssue($application, 'https://hub.test');

        $this->assertFalse($pack['issued']);
        $this->assertNull($pack['credential']);
        $this->assertStringContainsString('WAG_TOKEN=wgh_', $pack['env']);
        $this->assertStringContainsString('…', $pack['env']);
        $this->assertSame(1, $application->apiCredentials()->count());
    }
}
