<?php

namespace App\Services;

use App\Domain\Delivery\AttachmentKind;
use App\Domain\Delivery\OutboundAttachment;
use App\Domain\Delivery\ProviderAccountTestResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Http\Requests\StoreMessageRequest;
use App\Infrastructure\WhatsApp\ProviderDriverManager;
use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use App\Models\NumberCheckAttempt;
use App\Models\NumberCheckRequest;
use App\Models\ProviderAccount;
use App\Support\PhoneNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ProviderAccountTester
{
    public const PURPOSE = 'admin_test';

    public const ROUTE_KEY = 'admin-test';

    public const CLIENT_SLUG = 'hub-admin-tests';

    public function __construct(
        private ProviderDriverManager $drivers,
        private ProviderHealthRecorder $health,
        private PhoneNormalizer $phones,
        private GatewayMessageDispatcher $dispatcher,
        private AttachmentService $attachments,
    ) {}

    public function send(
        ProviderAccount $account,
        string $recipient,
        string $body,
        ?int $administratorId = null,
        ?OutboundAttachment $attachment = null,
    ): ProviderAccountTestResult {
        $normalizedRecipient = $this->phones->normalize($recipient);
        $normalizedBody = trim($body);
        $this->assertSendable($normalizedBody, $attachment);

        $startedAt = now();
        $message = DB::transaction(function () use (
            $account,
            $administratorId,
            $attachment,
            $normalizedBody,
            $normalizedRecipient,
            $startedAt,
        ): GatewayMessage {
            $client = $this->resolveAdminClient();

            if ($attachment?->attachmentId !== null) {
                $this->attachments->markReferenced($attachment->attachmentId);
            }

            $payload = [
                'recipient' => $normalizedRecipient,
                'body' => $normalizedBody,
                'attachment' => $attachment?->toArray(),
            ];

            $message = new GatewayMessage;
            $message->forceFill([
                'uuid' => (string) Str::uuid(),
                'client_application_id' => $client['application']->getKey(),
                'routing_policy_id' => null,
                'accepted_provider_account_id' => null,
                'idempotency_key' => 'admin-test-'.Str::uuid(),
                'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'correlation_id' => 'admin-test-'.Str::uuid(),
                'client_reference' => null,
                'recipient' => $normalizedRecipient,
                'recipient_hash' => hash_hmac('sha256', $normalizedRecipient, (string) config('app.key')),
                'recipient_last4' => substr($normalizedRecipient, -4),
                'body' => $normalizedBody,
                'message_type' => $attachment?->kind->value ?? 'text',
                'attachment' => $attachment?->toArray(),
                'purpose' => self::PURPOSE,
                'route_key' => self::ROUTE_KEY,
                'mode' => 'sync',
                'origin' => 'api',
                'origin_user_id' => $administratorId,
                'priority' => 50,
                'status' => 'queued',
                'queued_at' => $startedAt,
                'metadata' => [
                    'source' => 'admin_test',
                    'administrator_id' => $administratorId,
                    'provider_account_id' => $account->getKey(),
                ],
            ])->save();

            MessageEvent::query()->create([
                'gateway_message_id' => $message->getKey(),
                'type' => 'admin_provider_test',
                'source' => 'admin',
                'data' => [
                    'type' => 'send',
                    'provider_account_id' => $account->getKey(),
                    'administrator_id' => $administratorId,
                    'message_type' => $attachment?->kind->value ?? 'text',
                ],
                'occurred_at' => $startedAt,
            ]);

            return $message;
        });

        $dispatched = $this->dispatcher->dispatch($message);
        $dispatched = $dispatched->fresh() ?? $dispatched;
        $success = (string) $dispatched->status === 'provider_accepted';

        return new ProviderAccountTestResult(
            success: $success,
            title: $success ? 'Uji kirim berhasil' : 'Uji kirim gagal',
            body: $success
                ? 'Provider menerima pesan'
                    .($dispatched->provider_message_id ? " (ID: {$dispatched->provider_message_id})." : '.')
                : ($dispatched->last_error_message ?: $dispatched->last_error_code ?: 'Provider menolak atau gagal menerima pesan.'),
            type: 'send',
        );
    }

    public function storeUpload(UploadedFile $file, ?int $administratorId = null): OutboundAttachment
    {
        $client = $this->resolveAdminClient();
        $stored = $this->attachments->createFromUpload(
            $file,
            clientApplicationId: $client['application']->getKey(),
            userId: $administratorId,
        );

        return new OutboundAttachment(
            kind: $stored->kind(),
            url: 'attachment://'.$stored->uuid,
            filename: $stored->original_filename,
            mimeType: $stored->mime_type,
            attachmentId: (string) $stored->uuid,
            size: (int) $stored->size,
        );
    }

    public function attachmentFromPublicUrl(string $url, string $kind): OutboundAttachment
    {
        $attachmentKind = AttachmentKind::tryFrom($kind);

        if ($attachmentKind === null) {
            throw new InvalidArgumentException('Pilih jenis lampiran.');
        }

        $this->attachments->assertPublicUrl($url);

        return new OutboundAttachment(
            kind: $attachmentKind,
            url: trim($url),
        );
    }

    private function assertSendable(string $body, ?OutboundAttachment $attachment): void
    {
        if ($body === '' && $attachment === null) {
            throw new InvalidArgumentException('Isi pesan uji atau lampiran tidak boleh kosong.');
        }

        if ($attachment?->kind->supportsCaption() === false && $body !== '') {
            throw new InvalidArgumentException('Audio tidak mendukung caption.');
        }

        if ($attachment !== null && mb_strlen($body) > StoreMessageRequest::CAPTION_MAX_LENGTH) {
            throw new InvalidArgumentException('Caption attachment maksimal 1.024 karakter.');
        }

        if ($attachment === null && mb_strlen($body) > 10000) {
            throw new InvalidArgumentException('Isi pesan maksimal 10.000 karakter.');
        }

        if ($attachment !== null && $attachment->attachmentId === null) {
            $this->attachments->assertPublicUrl($attachment->url);
        }
    }

    public function checkNumber(
        ProviderAccount $account,
        string $recipient,
        ?int $administratorId = null,
    ): ProviderAccountTestResult {
        $normalizedRecipient = $this->phones->normalize($recipient);
        $startedAt = now();
        $hrStart = hrtime(true);
        $result = $this->drivers->checkNumber($account, $normalizedRecipient);
        $finishedAt = now();
        $latencyMs = max(0, (int) floor((hrtime(true) - $hrStart) / 1_000_000));

        $status = match ($result->status) {
            'registered', 'not_registered' => $result->status,
            'unsupported' => 'unsupported',
            default => 'unknown',
        };

        DB::transaction(function () use (
            $account,
            $finishedAt,
            $latencyMs,
            $normalizedRecipient,
            $result,
            $startedAt,
            $status,
        ): void {
            $client = $this->resolveAdminClient();
            $audit = NumberCheckRequest::forceCreate([
                'client_application_id' => $client['application']->getKey(),
                'api_credential_id' => $client['credential']->getKey(),
                'resolved_provider_account_id' => in_array($status, ['registered', 'not_registered'], true)
                    ? $account->getKey()
                    : null,
                'correlation_id' => 'admin-test-'.Str::uuid(),
                'recipient' => $normalizedRecipient,
                'recipient_hash' => hash_hmac('sha256', $normalizedRecipient, (string) config('app.key')),
                'recipient_last4' => substr($normalizedRecipient, -4),
                'route_key' => self::ROUTE_KEY,
                'status' => $status,
                'registered' => $result->registered,
                'last_error_code' => $status === 'registered' || $status === 'not_registered'
                    ? null
                    : ($result->reasonCode ?? 'provider_check_failed'),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            NumberCheckAttempt::forceCreate([
                'number_check_request_id' => $audit->getKey(),
                'provider_account_id' => $account->getKey(),
                'sequence' => 1,
                'status' => $status,
                'registered' => $result->registered,
                'http_status' => $result->httpStatus,
                'reason_code' => $result->reasonCode,
                'latency_ms' => $latencyMs,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);
        });

        $this->health->recordNumberCheck($account, $result);

        return $this->numberCheckResultSummary($result);
    }

    private function numberCheckResultSummary(NumberCheckResult $result): ProviderAccountTestResult
    {
        return match ($result->status) {
            'registered' => new ProviderAccountTestResult(
                success: true,
                title: 'Uji cek nomor berhasil',
                body: 'Nomor terdaftar di WhatsApp.',
                type: 'check_number',
            ),
            'not_registered' => new ProviderAccountTestResult(
                success: true,
                title: 'Uji cek nomor berhasil',
                body: 'Nomor tidak terdaftar di WhatsApp.',
                type: 'check_number',
            ),
            'unsupported' => new ProviderAccountTestResult(
                success: false,
                title: 'Cek nomor tidak didukung',
                body: 'Driver provider ini tidak mendukung pengecekan nomor.',
                type: 'check_number',
            ),
            default => new ProviderAccountTestResult(
                success: false,
                title: 'Uji cek nomor gagal',
                body: $result->reasonCode
                    ? "Hasil tidak dapat dipastikan ({$result->reasonCode})."
                    : 'Hasil tidak dapat dipastikan dari respons provider.',
                type: 'check_number',
            ),
        };
    }

    /**
     * @return array{application: ClientApplication, credential: ApiCredential}
     */
    private function resolveAdminClient(): array
    {
        $application = ClientApplication::query()->firstOrCreate(
            ['slug' => self::CLIENT_SLUG],
            [
                'name' => 'Hub Admin Tests',
                'is_active' => true,
                'rate_limit_per_minute' => 60,
            ],
        );

        $credential = ApiCredential::query()
            ->where('client_application_id', $application->getKey())
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->first();

        if ($credential === null) {
            $credential = ApiCredential::issue(
                $application,
                'Internal admin tests',
                ['messages:send', 'messages:read', 'numbers:check'],
            )->credential;
        }

        return [
            'application' => $application,
            'credential' => $credential,
        ];
    }

    private function sanitize(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }
}
