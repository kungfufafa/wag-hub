<?php

namespace Tests\Feature\Api\V2;

use App\Infrastructure\WhatsApp\HostResolver;
use App\Jobs\EngineDispatchMessage;
use App\Models\EngineMedia;
use App\Models\EngineMessage;
use App\Models\EngineOperation;
use App\Models\GatewayMessage;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

/**
 * POST /api/v2/messages: default connection, forgiving phone numbers, media by URL, one response shape.
 */
class SimpleSendTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config([
            'gateway.engine.baileys_url' => 'http://runner.test',
            'gateway.engine.baileys_token' => 'private-test-token',
        ]);
        Http::preventStrayRequests();
    }

    public function test_the_default_connection_is_used_when_none_is_named(): void
    {
        $client = $this->client();
        $id = $this->managed($client, 'm-1');

        $this->simple($client, 'order-1', ['to' => '6281234567890', 'text' => 'Halo'])
            ->assertAccepted()
            ->assertJsonPath('data.connection_id', $id)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.transport_status', 'queued');

        $this->assertSame('6281234567890', EngineMessage::query()->sole()->recipient);
        Queue::assertPushed(EngineDispatchMessage::class, 1);
    }

    public function test_a_named_connection_wins_and_another_tenants_connection_is_not_found(): void
    {
        $client = $this->client();
        $first = $this->managed($client, 'm-1');
        $second = $this->managed($client, 'm-2');

        $this->simple($client, 'order-1', ['connection' => $second, 'to' => '6281234567890', 'text' => 'Halo'])
            ->assertAccepted()
            ->assertJsonPath('data.connection_id', $second);
        $this->assertNotSame($first, $second);

        $other = $this->client();
        $this->simple($other, 'steal-1', ['connection' => $first, 'to' => '6281234567890', 'text' => 'Halo'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'connection_not_found');
    }

    public function test_without_a_default_the_hub_promotes_the_first_connection(): void
    {
        $client = $this->client();
        $first = $this->managed($client, 'm-1');
        $this->managed($client, 'm-2');
        WhatsAppConnection::query()->update(['is_default' => false]);

        $this->simple($client, 'order-1', ['to' => '6281234567890', 'text' => 'Halo'])
            ->assertAccepted()
            ->assertJsonPath('data.connection_id', $first);
        $this->assertTrue(WhatsAppConnection::query()->where('uuid', $first)->sole()->is_default);
    }

    public function test_an_application_without_connections_gets_a_clear_error(): void
    {
        $this->simple($this->client(), 'order-1', ['to' => '6281234567890', 'text' => 'Halo'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'connection_not_found');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function recipients(): iterable
    {
        yield 'local with dashes' => ['0812-3456-7890', '6281234567890'];
        yield 'international with spaces' => ['+62 812 3456 7890', '6281234567890'];
        yield 'brackets and dots' => ['(0812) 3456.7890', '6281234567890'];
        yield 'double zero prefix' => ['0062 812 3456 7890', '6281234567890'];
        yield 'other country keeps its code' => ['+1 (415) 555-2671', '14155552671'];
    }

    #[DataProvider('recipients')]
    public function test_recipients_are_normalised_by_the_hub(string $input, string $expected): void
    {
        $client = $this->client();
        $this->managed($client, 'm-1');

        $this->simple($client, 'order-1', ['to' => $input, 'text' => 'Halo'])->assertAccepted();

        $this->assertSame($expected, EngineMessage::query()->sole()->recipient);
    }

    public function test_invalid_recipients_are_still_rejected(): void
    {
        $client = $this->client();
        $this->managed($client, 'm-1');

        foreach (['abc', 'mail@example.com', '12'] as $index => $value) {
            $this->simple($client, 'bad-'.$index, ['to' => $value, 'text' => 'Halo'])->assertUnprocessable();
        }

        $this->assertSame(0, EngineMessage::query()->count());
    }

    public function test_managed_media_by_url_is_downloaded_once_and_replays_without_a_second_download(): void
    {
        $client = $this->client();
        $this->managed($client, 'm-1');
        $png = UploadedFile::fake()->image('a.png', 8, 8)->getContent();
        Http::fake(['https://cdn.example.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $payload = ['to' => '6281234567890', 'image' => 'https://cdn.example.com/promo.png', 'text' => 'Promo'];

        $first = $this->simple($client, 'media-1', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.type', 'image');
        $this->simple($client, 'media-1', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->simple($client, 'media-1', [...$payload, 'image' => 'https://cdn.example.com/other.png'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        Http::assertSentCount(1);
        $media = EngineMedia::query()->sole();
        $this->assertSame('https://cdn.example.com/promo.png', $media->source_url);
        $this->assertSame('image', $media->type);
        $this->assertSame($media->id, EngineMessage::query()->sole()->engine_media_id);
    }

    public function test_document_media_url_works_with_the_explicit_form_too(): void
    {
        $client = $this->client();
        $this->managed($client, 'm-1');
        Http::fake(['https://files.example.com/*' => Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf'])]);

        $this->simple($client, 'doc-1', ['to' => '6281234567890', 'type' => 'document', 'media_url' => 'https://files.example.com/invoice.pdf'])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'document');

        $this->assertSame('invoice.pdf', EngineMedia::query()->sole()->filename);
    }

    public function test_media_urls_on_internal_hosts_are_refused_without_a_request(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['10.0.0.8'];
            }
        });
        $client = $this->client();
        $this->managed($client, 'm-1');

        foreach (['http://127.0.0.1/a.png', 'http://169.254.169.254/latest', 'https://intranet.example.com/a.png'] as $index => $url) {
            $this->simple($client, 'ssrf-'.$index, ['to' => '6281234567890', 'image' => $url])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'media_url_not_allowed');
        }

        Http::assertNothingSent();
        $this->assertSame(0, EngineMedia::query()->count());
    }

    public function test_media_that_is_too_large_or_unreachable_is_reported(): void
    {
        $client = $this->client();
        $this->managed($client, 'm-1');
        config(['engine_v2.media_max_bytes' => 10]);
        Http::fake([
            'https://cdn.example.com/big.png' => Http::response(str_repeat('x', 50), 200),
            'https://cdn.example.com/missing.png' => Http::response('', 404),
        ]);

        $this->simple($client, 'big-1', ['to' => '6281234567890', 'image' => 'https://cdn.example.com/big.png'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'media_too_large');
        $this->simple($client, 'missing-1', ['to' => '6281234567890', 'image' => 'https://cdn.example.com/missing.png'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'media_url_unreachable');
        $this->assertSame(0, EngineMessage::query()->count());
    }

    public function test_media_needs_a_url_or_an_id_and_not_both(): void
    {
        $client = $this->client();
        $this->managed($client, 'm-1');

        $this->simple($client, 'none-1', ['to' => '6281234567890', 'type' => 'image'])->assertUnprocessable();
        $this->simple($client, 'both-1', [
            'to' => '6281234567890', 'type' => 'image',
            'media_url' => 'https://cdn.example.com/a.png', 'media_id' => '0199a7a5-5f1c-7000-8000-000000000001',
        ])->assertUnprocessable();
        $this->simple($client, 'ftp-1', ['to' => '6281234567890', 'image' => 'ftp://cdn.example.com/a.png'])->assertUnprocessable();
    }

    public function test_provider_routes_send_text_and_url_media_with_the_same_202_shape(): void
    {
        $client = $this->client();
        Http::fake(['https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['fonnte-1']])]);
        $id = $this->withToken($client['token'])->postJson('/api/v2/connections', [
            'name' => 'Fonnte', 'type' => 'provider_route', 'is_default' => true,
            'provider' => ['driver' => 'fonnte', 'configuration' => ['token' => 'fonnte-secret']],
        ], ['Idempotency-Key' => 'route-1'])->assertCreated()->json('data.id');

        $this->simple($client, 'route-text-1', ['to' => '0812 3456 7890', 'text' => 'Halo'])
            ->assertAccepted()
            ->assertJsonPath('data.connection_id', $id)
            ->assertJsonPath('data.status', 'sent');
        $this->simple($client, 'route-img-1', ['to' => '081234567890', 'image' => 'https://cdn.example.com/a.png', 'text' => 'Caption'])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.status', 'sent');
        $this->simple($client, 'route-img-1', ['to' => '081234567890', 'image' => 'https://cdn.example.com/b.png', 'text' => 'Caption'])
            ->assertConflict();
        $this->simple($client, 'route-voice-1', ['to' => '081234567890', 'voice' => 'https://cdn.example.com/a.ogg'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'capability_not_supported');

        $image = GatewayMessage::query()->where('idempotency_key', 'route-img-1')->sole();
        $this->assertSame('image', $image->message_type);
        $this->assertSame('https://cdn.example.com/a.png', $image->outboundAttachment()?->url);
        $this->assertSame('6281234567890', $image->recipient);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.fonnte.com')
            && str_contains((string) $request->body(), 'cdn.example.com/a.png'));
    }

    public function test_the_existing_connection_send_endpoint_keeps_its_contract(): void
    {
        $client = $this->client();
        $id = $this->managed($client, 'm-1');

        $this->withToken($client['token'])
            ->postJson('/api/v2/connections/'.$id.'/messages', ['to' => '0812-3456-7890', 'text' => 'Halo'], ['Idempotency-Key' => 'old-1'])
            ->assertAccepted()
            ->assertJsonMissingPath('data.status')
            ->assertJsonPath('data.transport_status', 'queued');
    }

    public function test_the_idempotency_key_is_still_required_and_abilities_apply(): void
    {
        $client = $this->client();
        $this->managed($client, 'm-1');

        $this->simple($client, '', ['to' => '6281234567890', 'text' => 'Halo'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_idempotency_key');

        $reader = $this->createClientApplication(['sessions:read', 'messages:read']);
        $this->simple($reader, 'r-1', ['to' => '6281234567890', 'text' => 'Halo'])->assertForbidden();
    }

    /**
     * @return array{token: string}
     */
    private function client(): array
    {
        return $this->createClientApplication(config('engine_v2.scopes'));
    }

    /**
     * Create a managed connection whose session is WORKING, and return its id.
     *
     * @param  array{token: string}  $client
     */
    private function managed(array $client, string $key): string
    {
        $id = $this->withToken($client['token'])
            ->postJson('/api/v2/connections', ['type' => 'managed_number'], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->json('data.id');
        $session = WhatsAppConnection::query()->where('uuid', $id)->sole()->engineSession;
        EngineOperation::query()->where('engine_session_id', $session->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $session->refresh()->update([
            'status' => 'WORKING', 'desired_state' => 'RUNNING', 'pending_operation_id' => null,
            'observed_at' => now(), 'engine_available' => true, 'last_error' => null, 'authenticated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array{token: string}  $client
     * @param  array<string, mixed>  $payload
     */
    private function simple(array $client, string $key, array $payload)
    {
        return $this->withToken($client['token'])->postJson('/api/v2/messages', $payload, ['Idempotency-Key' => $key]);
    }
}
