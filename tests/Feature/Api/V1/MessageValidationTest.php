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

    public function test_attachment_messages_require_a_public_url_and_text_messages_reject_attachments(): void
    {
        Queue::fake();
        Http::fake();
        $client = $this->createClientApplication();

        $cases = [
            'missing-attachment' => [
                ['message' => ['type' => 'image', 'text' => 'Caption']],
                ['message.attachment'],
            ],
            'missing-url' => [
                ['message' => ['type' => 'document', 'attachment' => ['filename' => 'a.pdf']]],
                ['message.attachment.url'],
            ],
            'unknown-type' => [
                ['message' => ['type' => 'sticker', 'attachment' => ['url' => 'https://cdn.example.com/a.webp']]],
                ['message.type'],
            ],
            'non-http-scheme' => [
                ['message' => ['type' => 'image', 'attachment' => ['url' => 'ftp://cdn.example.com/a.png']]],
                ['message.attachment.url'],
            ],
            'private-host' => [
                ['message' => ['type' => 'image', 'attachment' => ['url' => 'http://127.0.0.1/a.png']]],
                ['message.attachment.url'],
            ],
            'localhost' => [
                ['message' => ['type' => 'image', 'attachment' => ['url' => 'http://localhost:8000/a.png']]],
                ['message.attachment.url'],
            ],
            'bad-filename' => [
                ['message' => ['type' => 'document', 'attachment' => ['url' => 'https://cdn.example.com/a.pdf', 'filename' => '../a.pdf']]],
                ['message.attachment.filename'],
            ],
            'bad-mime' => [
                ['message' => ['type' => 'document', 'attachment' => ['url' => 'https://cdn.example.com/a.pdf', 'mime_type' => 'pdf']]],
                ['message.attachment.mime_type'],
            ],
            'caption-too-long' => [
                ['message' => ['type' => 'image', 'text' => str_repeat('x', 1025), 'attachment' => ['url' => 'https://cdn.example.com/a.png']]],
                ['message.text'],
            ],
            'audio-with-caption' => [
                ['message' => ['type' => 'audio', 'text' => 'Halo', 'attachment' => ['url' => 'https://cdn.example.com/a.mp3']]],
                ['message.text'],
            ],
            'text-with-attachment' => [
                ['message' => ['type' => 'text', 'text' => 'Halo', 'attachment' => ['url' => 'https://cdn.example.com/a.png']]],
                ['message.attachment'],
            ],
            'id-and-url' => [
                ['message' => ['type' => 'image', 'attachment' => [
                    'id' => '11111111-1111-1111-1111-111111111111',
                    'url' => 'https://cdn.example.com/a.png',
                ]]],
                ['message.attachment'],
            ],
        ];

        foreach ($cases as $key => [$message, $errors]) {
            $payload = $this->messagePayload();
            $payload['message'] = $message['message'];

            $this->postMessage($client['token'], "attachment-{$key}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonValidationErrors($errors);
        }

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_hexadecimal_and_mixed_base_private_ipv4_attachment_urls_are_rejected(): void
    {
        Queue::fake();
        Http::fake();
        $client = $this->createClientApplication();

        foreach ([
            'http://127.0.0.1/x',
            'http://0x7f000001/x',
            'http://0x7f.0.0.1/x',
        ] as $index => $url) {
            $payload = $this->messagePayload();
            $payload['message'] = [
                'type' => 'image',
                'attachment' => ['url' => $url],
            ];

            $this->postMessage($client['token'], "hex-private-{$index}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonValidationErrors('message.attachment.url');
        }

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_an_attachment_without_caption_is_accepted_and_queued(): void
    {
        Queue::fake();
        Http::fake();
        $client = $this->createClientApplication();

        $payload = $this->messagePayload();
        $payload['message'] = [
            'type' => 'document',
            'attachment' => [
                'url' => 'https://cdn.example.com/invoices/INV-0001.pdf',
                'filename' => 'INV-0001.pdf',
                'mime_type' => 'application/pdf',
            ],
        ];

        $this->postMessage($client['token'], 'attachment-no-caption', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.message_type', 'document');

        $this->assertDatabaseCount('gateway_messages', 1);
        $this->assertSame('document', DB::table('gateway_messages')->value('message_type'));
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
