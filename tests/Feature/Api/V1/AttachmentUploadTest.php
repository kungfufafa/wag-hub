<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchGatewayMessage;
use App\Models\Attachment;
use App\Models\GatewayMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class AttachmentUploadTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_a_client_can_upload_an_attachment_and_reference_it_from_a_message(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = $this->createClientApplication();

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('promo.jpg', 64, 64),
        ]);

        $upload->assertCreated()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.filename', 'promo.jpg')
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.status', 'active');

        $id = (string) $upload->json('data.id');
        $this->assertDatabaseHas('attachments', [
            'uuid' => $id,
            'client_application_id' => $client['id'],
            'media_kind' => 'image',
        ]);

        $this->postMessage($client['token'], 'upload-reference-1', $this->messagePayload('async', [
            'message' => [
                'type' => 'image',
                'text' => 'Promo',
                'attachment' => ['id' => $id],
            ],
        ]))->assertAccepted()
            ->assertJsonPath('data.message_type', 'image')
            ->assertJsonPath('data.attachment.id', $id);

        Queue::assertPushed(DispatchGatewayMessage::class);
        $this->assertSame($id, Attachment::query()->firstOrFail()->uuid);
    }

    public function test_an_attachment_cannot_be_referenced_by_another_client(): void
    {
        Storage::fake('local');
        $owner = $this->createClientApplication();
        $other = $this->createClientApplication();

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$owner['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $this->postMessage($other['token'], 'cross-client-attachment-1', $this->messagePayload(overrides: [
            'message' => [
                'type' => 'document',
                'attachment' => ['id' => $upload->json('data.id')],
            ],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('message.attachment.id');
    }

    public function test_an_owner_can_read_metadata_and_fetch_a_signed_file(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', '0123456789'),
        ])->assertCreated();

        $id = (string) $upload->json('data.id');
        $metadata = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->get('/api/v1/attachments/'.$id);

        $metadata->assertOk()->assertJsonPath('data.id', $id);

        $downloadUrl = $upload->json('data.download_url');

        $this->get($downloadUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->withHeaders(['Range' => 'bytes=0-0'])
            ->get($downloadUrl)
            ->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes');

        $this->withHeaders(['Range' => 'bytes=0-0'])
            ->head($downloadUrl)
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_upload_rejects_oversized_and_mime_extension_mismatches(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ];

        $this->withHeaders($headers)
            ->post('/api/v1/attachments', [
                'file' => UploadedFile::fake()->create('large.pdf', 16385, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->withHeaders($headers)
            ->post('/api/v1/attachments', [
                'file' => UploadedFile::fake()->create('spoof.jpg', 1, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'attachment_invalid');
    }

    public function test_cleanup_expires_unused_files_and_signed_downloads_stop_working(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('cleanup.png', 20, 20),
        ])->assertCreated();

        $id = (string) $upload->json('data.id');
        $attachment = Attachment::query()->where('uuid', $id)->firstOrFail();
        $path = $attachment->path;
        $downloadUrl = $upload->json('data.download_url');
        $attachment->forceFill([
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ])->saveQuietly();

        $this->artisan('gateway:cleanup-attachments')->assertExitCode(0);

        $this->assertSame('expired', $attachment->refresh()->status);
        Storage::disk('local')->assertMissing($path);
        $this->get($downloadUrl)->assertNotFound();
    }

    public function test_cleanup_protects_attachment_while_message_is_pending_then_uses_terminal_retention(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = $this->createClientApplication();

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('pending.png', 20, 20),
        ])->assertCreated();

        $attachment = Attachment::query()->where('uuid', $upload->json('data.id'))->firstOrFail();
        $message = $this->postMessage($client['token'], 'pending-cleanup-1', $this->messagePayload('async', [
            'message' => [
                'type' => 'image',
                'attachment' => ['id' => $attachment->uuid],
            ],
        ]))->assertAccepted();
        $path = $attachment->path;

        $attachment->forceFill([
            'last_referenced_at' => now()->subDays(91),
            'expires_at' => now()->subDay(),
        ])->saveQuietly();

        $this->artisan('gateway:cleanup-attachments')->assertExitCode(0);
        $this->assertSame('active', $attachment->refresh()->status);
        Storage::disk('local')->assertExists($path);

        GatewayMessage::query()
            ->where('uuid', $message->json('data.id'))
            ->update(['status' => 'failed']);

        $this->artisan('gateway:cleanup-attachments')->assertExitCode(0);
        $this->assertSame('expired', $attachment->refresh()->status);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_signed_download_keeps_authenticated_dashboard_access_admin_only(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->createWithContent('admin-only.txt', 'private'),
        ])->assertCreated();
        $downloadUrl = $upload->json('data.download_url');

        $this->actingAs(User::factory()->create(['is_admin' => false, 'is_active' => true]))
            ->get($downloadUrl)
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => false]))
            ->get($downloadUrl)
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => true]))
            ->get($downloadUrl)
            ->assertOk();
    }

    public function test_replaying_an_identical_message_after_attachment_cleanup_returns_the_original(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-idempotent-cleanup', [
            'base_url' => 'https://waha-idempotent-cleanup.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('replay.png', 20, 20),
        ])->assertCreated();
        $id = (string) $upload->json('data.id');
        $payload = $this->messagePayload('sync', [
            'message' => [
                'type' => 'image',
                'attachment' => ['id' => $id],
            ],
        ]);

        Http::fake([
            'waha-idempotent-cleanup.test/*' => Http::response(['id' => 'waha-replay-1'], 201),
        ]);

        $first = $this->postMessage($client['token'], 'replay-after-cleanup', $payload)
            ->assertCreated();
        $originalId = $first->json('data.id');

        $attachment = Attachment::query()->where('uuid', $id)->firstOrFail();
        $attachment->forceFill([
            'expires_at' => now()->subMinute(),
        ])->saveQuietly();
        $this->artisan('gateway:cleanup-attachments')->assertExitCode(0);
        $this->assertSame('expired', $attachment->refresh()->status);

        $this->postMessage($client['token'], 'replay-after-cleanup', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $originalId)
            ->assertJsonPath('data.duplicate', true);

        $this->postMessage($client['token'], 'new-key-dead-attachment', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors('message.attachment.id');

        $this->assertSame(1, GatewayMessage::query()->count());
        Http::assertSentCount(1);
    }
}
