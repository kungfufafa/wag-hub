<?php

namespace Tests\Feature\Delivery;

use App\Jobs\DispatchGatewayMessage;
use App\Models\Attachment;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use App\Services\GatewayMessageDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class AttachmentDeliveryTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_waha_sends_an_image_with_caption_through_send_image(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-primary.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake([
            'waha-primary.test/*' => Http::response(['id' => 'waha-img-1'], 201),
        ]);

        $response = $this->postMessage($client['token'], 'waha-image-1', $this->attachmentPayload('image', [
            'url' => 'https://cdn.example.com/promo/banner.png',
        ], 'Promo minggu ini'));

        $response
            ->assertCreated()
            ->assertJsonPath('data.message_type', 'image')
            ->assertJsonPath('data.attachment.url', 'https://cdn.example.com/promo/banner.png')
            ->assertJsonPath('data.provider', 'waha-primary')
            ->assertJsonPath('data.provider_message_id', 'waha-img-1');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://waha-primary.test/api/sendImage'
            && $request['session'] === 'default'
            && $request['chatId'] === '6281234567890@c.us'
            && $request['caption'] === 'Promo minggu ini'
            && $request['file'] === [
                'mimetype' => 'image/png',
                'url' => 'https://cdn.example.com/promo/banner.png',
                'filename' => 'banner.png',
            ]
            && ! isset($request['text']));

        $stored = GatewayMessage::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame('image', $stored->message_type);
        $this->assertSame('Promo minggu ini', $stored->plaintextBody());
        $this->assertSame([
            'kind' => 'image',
            'url' => 'https://cdn.example.com/promo/banner.png',
            'filename' => null,
            'mime_type' => null,
        ], $stored->outboundAttachment()?->toArray());
    }

    public function test_waha_sends_audio_as_voice_without_caption_or_filename(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-primary.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake(['waha-primary.test/*' => Http::response(['id' => 'waha-voice-1'], 201)]);

        $this->postMessage($client['token'], 'waha-audio-1', $this->attachmentPayload('audio', [
            'url' => 'https://cdn.example.com/voice/greeting.ogg',
            'mime_type' => 'audio/ogg; codecs=opus',
        ]))->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://waha-primary.test/api/sendVoice'
            && $request['file'] === [
                'mimetype' => 'audio/ogg; codecs=opus',
                'url' => 'https://cdn.example.com/voice/greeting.ogg',
            ]
            && ! isset($request['caption']));
    }

    public function test_fonnte_sends_a_document_using_url_filename_and_caption_fields(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('fonnte', 'fonnte-primary', [
            'endpoint' => 'https://fonnte-primary.test/send',
            'token' => 'fonnte-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake([
            'fonnte-primary.test/*' => Http::response(['status' => true, 'id' => ['fonnte-doc-1'], 'process' => 'pending']),
        ]);

        $this->postMessage($client['token'], 'fonnte-doc-1', $this->attachmentPayload('document', [
            'url' => 'https://cdn.example.com/invoices/INV-0001.pdf',
            'filename' => 'Invoice INV-0001.pdf',
        ], 'Terlampir invoice Anda.'))
            ->assertCreated()
            ->assertJsonPath('data.provider', 'fonnte-primary');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fonnte-primary.test/send'
            && $request->isMultipart()
            && $request->hasFile('target', '6281234567890')
            && $request->hasFile('countryCode', '62')
            && $request->hasFile('url', 'https://cdn.example.com/invoices/INV-0001.pdf')
            && $request->hasFile('filename', 'Invoice INV-0001.pdf')
            && $request->hasFile('message', 'Terlampir invoice Anda.'));
    }

    public function test_gowa_sends_a_document_as_multipart_to_send_file(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('gowa', 'gowa-primary', [
            'base_url' => 'https://gowa-primary.test',
            'username' => 'gateway',
            'password' => 'gowa-secret',
            'device_id' => 'device-main',
            'version' => '8.10.0',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake([
            'gowa-primary.test/*' => Http::response([
                'code' => 'SUCCESS',
                'message' => 'Success',
                'results' => ['message_id' => 'gowa-file-1', 'status' => 'sent'],
            ]),
        ]);

        $this->postMessage($client['token'], 'gowa-doc-1', $this->attachmentPayload('document', [
            'url' => 'https://cdn.example.com/invoices/INV-0002.pdf',
        ], 'Invoice bulan ini'))
            ->assertCreated()
            ->assertJsonPath('data.provider_message_id', 'gowa-file-1');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gowa-primary.test/send/file'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('gateway:gowa-secret'))
            && $request->hasHeader('X-Device-Id', 'device-main')
            && $request->isMultipart()
            && $request->hasFile('phone', '6281234567890@s.whatsapp.net')
            && $request->hasFile('file_url', 'https://cdn.example.com/invoices/INV-0002.pdf')
            && $request->hasFile('caption', 'Invoice bulan ini'));
    }

    public function test_gowa_sends_a_video_to_send_video_without_caption(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('gowa', 'gowa-primary', [
            'base_url' => 'https://gowa-primary.test',
            'username' => 'gateway',
            'password' => 'gowa-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake([
            'gowa-primary.test/*' => Http::response([
                'code' => 'SUCCESS',
                'message' => 'Success',
                'results' => ['message_id' => 'gowa-video-1', 'status' => 'sent'],
            ]),
        ]);

        $this->postMessage($client['token'], 'gowa-video-1', $this->attachmentPayload('video', [
            'url' => 'https://cdn.example.com/media/tutorial.mp4',
        ]))->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gowa-primary.test/send/video'
            && $request->isMultipart()
            && $request->hasFile('video_url', 'https://cdn.example.com/media/tutorial.mp4')
            && ! $request->hasFile('caption'));
    }

    public function test_gowa_rejects_a_document_when_version_is_too_old(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('gowa', 'gowa-old', [
            'base_url' => 'https://gowa-old.test',
            'username' => 'gateway',
            'password' => 'gowa-secret',
            'version' => '8.9.9',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake();

        $this->postMessage($client['token'], 'gowa-old-doc-1', $this->attachmentPayload('document', [
            'url' => 'https://cdn.example.com/invoices/legacy.pdf',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'attachment_format_unsupported');

        Http::assertNothingSent();
    }

    public function test_waba_sends_media_objects_by_link_with_caption_and_document_filename(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waba', 'waba-primary', [
            'base_url' => 'https://graph-meta.test',
            'api_version' => 'v25.0',
            'phone_number_id' => '123456789012345',
            'access_token' => 'meta-system-user-token',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake([
            'graph-meta.test/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['wa_id' => '6281234567890']],
                'messages' => [['id' => 'wamid.media']],
            ]),
        ]);

        $this->postMessage($client['token'], 'waba-image-1', $this->attachmentPayload('image', [
            'url' => 'https://cdn.example.com/promo/banner.jpg',
        ], 'Promo'))->assertCreated();

        $this->postMessage($client['token'], 'waba-doc-1', $this->attachmentPayload('document', [
            'url' => 'https://cdn.example.com/invoices/INV-0003.pdf',
        ]))->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph-meta.test/v25.0/123456789012345/messages'
            && $request['type'] === 'image'
            && $request['image'] === ['link' => 'https://cdn.example.com/promo/banner.jpg', 'caption' => 'Promo']
            && ! isset($request['text']));

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'document'
            && $request['document'] === [
                'link' => 'https://cdn.example.com/invoices/INV-0003.pdf',
                'filename' => 'INV-0003.pdf',
            ]);
    }

    public function test_the_same_attachment_payload_is_idempotent_while_a_changed_url_conflicts(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-primary.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake(['waha-primary.test/*' => Http::response(['id' => 'waha-img-2'], 201)]);

        $payload = $this->attachmentPayload('image', ['url' => 'https://cdn.example.com/a.png']);

        $first = $this->postMessage($client['token'], 'attach-idem-1', $payload)->assertCreated();

        $this->postMessage($client['token'], 'attach-idem-1', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.duplicate', true);

        $this->postMessage($client['token'], 'attach-idem-1', $this->attachmentPayload('image', [
            'url' => 'https://cdn.example.com/b.png',
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        Http::assertSentCount(1);
    }

    public function test_fonnte_enforces_its_configured_attachment_limit_before_http(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('fonnte', 'fonnte-size-limit', [
            'endpoint' => 'https://fonnte-size-limit.test/send',
            'token' => 'fonnte-secret',
            'attachment_max_bytes' => 4 * 1024 * 1024,
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->createWithContent(
                'large.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=').
                    str_repeat('x', (5 * 1024 * 1024) - 68),
            ),
        ])->assertCreated();
        $attachment = Attachment::query()->where('uuid', $upload->json('data.id'))->firstOrFail();

        Http::fake();
        $this->postMessage($client['token'], 'fonnte-size-1', $this->messagePayload('sync', [
            'message' => [
                'type' => 'image',
                'attachment' => ['id' => $attachment->uuid],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'attachment_size_unsupported');

        Http::assertNothingSent();
        $this->assertSame('healthy', ProviderAccount::query()->findOrFail($provider['id'])->health_status);
    }

    public function test_waha_rejects_non_ogg_audio_without_sending_or_degrading_health(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-audio-format', [
            'base_url' => 'https://waha-audio-format.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake();

        $this->postMessage($client['token'], 'waha-audio-format-1', $this->attachmentPayload('audio', [
            'url' => 'https://cdn.example.com/voice/greeting.mp3',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'attachment_format_unsupported');

        Http::assertNothingSent();
        $this->assertSame('healthy', ProviderAccount::query()->findOrFail($provider['id'])->health_status);
    }

    public function test_a_corrupt_private_attachment_fails_before_provider_http(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-corrupt-attachment', [
            'base_url' => 'https://waha-corrupt-attachment.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('corrupt.png', 20, 20),
        ])->assertCreated();
        $attachment = Attachment::query()->where('uuid', $upload->json('data.id'))->firstOrFail();
        Storage::disk('local')->put($attachment->path, 'tampered');

        Http::fake();
        $this->postMessage($client['token'], 'corrupt-attachment-1', $this->messagePayload('sync', [
            'message' => [
                'type' => 'image',
                'attachment' => ['id' => $attachment->uuid],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'attachment_unavailable');

        Http::assertNothingSent();
        $this->assertSame('healthy', ProviderAccount::query()->findOrFail($provider['id'])->health_status);
        $this->assertSame('image', GatewayMessage::query()->value('message_type'));
        $this->assertSame($attachment->uuid, GatewayMessage::query()->firstOrFail()->outboundAttachment()?->attachmentId);
    }

    public function test_a_deleted_private_attachment_fails_before_provider_http(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-deleted-attachment', [
            'base_url' => 'https://waha-deleted-attachment.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('missing.png', 20, 20),
        ])->assertCreated();
        $attachment = Attachment::query()->where('uuid', $upload->json('data.id'))->firstOrFail();
        Storage::disk('local')->delete($attachment->path);

        Http::fake();
        $this->postMessage($client['token'], 'deleted-attachment-1', $this->messagePayload('sync', [
            'message' => [
                'type' => 'image',
                'attachment' => ['id' => $attachment->uuid],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'attachment_unavailable')
            ->assertJsonPath('data.message_type', 'image')
            ->assertJsonPath('data.attachment.id', $attachment->uuid);

        Http::assertNothingSent();
        $this->assertSame('healthy', ProviderAccount::query()->findOrFail($provider['id'])->health_status);
        $this->assertSame('image', GatewayMessage::query()->value('message_type'));
    }

    public function test_a_private_upload_is_dispatched_as_an_http_url_not_an_internal_id(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-private-url', [
            'base_url' => 'https://waha-private-url.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('promo.png', 32, 32),
        ])->assertCreated();
        $id = (string) $upload->json('data.id');

        Http::fake([
            'waha-private-url.test/*' => Http::response(['id' => 'waha-priv-1'], 201),
        ]);

        $this->postMessage($client['token'], 'private-url-1', $this->messagePayload('sync', [
            'message' => [
                'type' => 'image',
                'text' => 'Promo',
                'attachment' => ['id' => $id],
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.message_type', 'image')
            ->assertJsonPath('data.attachment.id', $id);

        $stored = GatewayMessage::query()->firstOrFail();
        $this->assertSame('image', $stored->message_type);
        $this->assertSame($id, $stored->outboundAttachment()?->attachmentId);

        Http::assertSent(function (Request $request) use ($id): bool {
            $url = is_array($request['file'] ?? null) ? ($request['file']['url'] ?? null) : null;

            return $request->url() === 'https://waha-private-url.test/api/sendImage'
                && is_string($url)
                && preg_match('#^https?://#i', $url) === 1
                && ! str_contains($url, 'attachment://')
                && str_contains($url, $id)
                && ($request['caption'] ?? null) === 'Promo'
                && ! isset($request['text']);
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sendText'));
    }

    public function test_reusing_an_attachment_after_the_old_deadline_still_delivers_while_pending(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-reuse-expiry', [
            'base_url' => 'https://waha-reuse-expiry.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('reuse.png', 24, 24),
        ])->assertCreated();
        $attachment = Attachment::query()->where('uuid', $upload->json('data.id'))->firstOrFail();
        $path = $attachment->path;

        Http::fake([
            'waha-reuse-expiry.test/*' => Http::response(['id' => 'waha-reuse-1'], 201),
        ]);

        $this->postMessage($client['token'], 'reuse-first', $this->messagePayload('sync', [
            'message' => [
                'type' => 'image',
                'attachment' => ['id' => $attachment->uuid],
            ],
        ]))->assertCreated();

        $attachment->forceFill([
            'expires_at' => now()->subMinute(),
        ])->saveQuietly();
        $this->assertFalse($attachment->fresh()->isAvailable());

        Queue::fake();
        $second = $this->postMessage($client['token'], 'reuse-second', $this->messagePayload('async', [
            'message' => [
                'type' => 'image',
                'attachment' => ['id' => $attachment->uuid],
            ],
        ]))->assertAccepted();

        $attachment->refresh();
        $this->assertNull($attachment->expires_at);
        $this->assertTrue($attachment->isAvailable());

        $this->artisan('gateway:cleanup-attachments')->assertExitCode(0);
        $this->assertSame('active', $attachment->refresh()->status);
        Storage::disk('local')->assertExists($path);

        Queue::assertPushed(DispatchGatewayMessage::class);
        (new DispatchGatewayMessage(
            (int) GatewayMessage::query()->where('uuid', $second->json('data.id'))->value('id'),
        ))->handle(app(GatewayMessageDispatcher::class));

        $reused = GatewayMessage::query()->where('uuid', $second->json('data.id'))->firstOrFail();
        $this->assertSame('provider_accepted', $reused->status);
        $this->assertNotSame('attachment_unavailable', $reused->last_error_code);
        $this->assertSame($attachment->uuid, $reused->outboundAttachment()?->attachmentId);
    }

    public function test_waba_enforces_its_image_size_limit_before_http(): void
    {
        Storage::fake('local');
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waba', 'waba-size-limit', [
            'base_url' => 'https://graph-waba-size.test',
            'api_version' => 'v25.0',
            'phone_number_id' => '123456789012345',
            'access_token' => 'meta-system-user-token',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $upload = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$client['token'],
        ])->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->createWithContent(
                'large.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=').
                    str_repeat('x', (6 * 1024 * 1024) - 68),
            ),
        ])->assertCreated();
        $attachment = Attachment::query()->where('uuid', $upload->json('data.id'))->firstOrFail();

        Http::fake();
        $this->postMessage($client['token'], 'waba-size-1', $this->messagePayload('sync', [
            'message' => [
                'type' => 'image',
                'attachment' => ['id' => $attachment->uuid],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'attachment_size_unsupported');

        Http::assertNothingSent();
        $this->assertSame('healthy', ProviderAccount::query()->findOrFail($provider['id'])->health_status);
    }

    /**
     * @param  array<string, string>  $attachment
     * @return array<string, mixed>
     */
    private function attachmentPayload(string $type, array $attachment, ?string $caption = null): array
    {
        $payload = $this->messagePayload('sync');
        $payload['message'] = ['type' => $type, 'attachment' => $attachment];

        if ($caption !== null) {
            $payload['message']['text'] = $caption;
        }

        return $payload;
    }
}
