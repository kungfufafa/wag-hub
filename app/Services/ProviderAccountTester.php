<?php

namespace App\Services;

use App\Domain\Delivery\OutboundMessage;
use App\Domain\Delivery\ProviderAccountTestResult;
use App\Domain\Delivery\ProviderOutcome;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Infrastructure\WhatsApp\ProviderDriverManager;
use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageAttempt;
use App\Models\MessageEvent;
use App\Models\NumberCheckAttempt;
use App\Models\NumberCheckRequest;
use App\Models\ProviderAccount;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class ProviderAccountTester
{
    public const PURPOSE = 'admin_test';

    public const ROUTE_KEY = 'admin-test';

    public const CLIENT_SLUG = 'hub-admin-tests';

    public function __construct(
        private ProviderDriverManager $drivers,
        private ProviderHealthRecorder $health,
        private PhoneNormalizer $phones,
    ) {}

    public function send(
        ProviderAccount $account,
        string $recipient,
        string $body,
        ?int $administratorId = null,
    ): ProviderAccountTestResult {
        $normalizedRecipient = $this->phones->normalize($recipient);
        $normalizedBody = trim($body);

        if ($normalizedBody === '') {
            throw new InvalidArgumentException('Isi pesan uji tidak boleh kosong.');
        }

        $startedAt = now();
        $hrStart = hrtime(true);

        try {
            $result = $this->drivers->send(
                $account,
                new OutboundMessage(
                    recipient: $normalizedRecipient,
                    body: $normalizedBody,
                ),
            );
        } catch (Throwable) {
            $result = ProviderResult::outcomeUnknown(
                errorCode: 'unexpected_driver_failure',
                errorMessage: 'Driver berhenti setelah proses pengiriman dimulai.',
            );
        }

        if ($result->outcome === ProviderOutcome::ProviderFailed
            && $result->httpStatus !== null
            && $result->httpStatus >= 500) {
            $result = ProviderResult::outcomeUnknown(
                httpStatus: $result->httpStatus,
                errorCode: 'ambiguous_provider_http_error',
                errorMessage: 'Provider gagal setelah request mungkin sudah diproses.',
            );
        }

        $finishedAt = now();
        $latencyMs = max(0, (int) floor((hrtime(true) - $hrStart) / 1_000_000));

        DB::transaction(function () use (
            $account,
            $administratorId,
            $finishedAt,
            $latencyMs,
            $normalizedBody,
            $normalizedRecipient,
            $result,
            $startedAt,
        ): void {
            $client = $this->resolveAdminClient();
            $message = new GatewayMessage;
            $message->forceFill([
                'uuid' => (string) Str::uuid(),
                'client_application_id' => $client['application']->getKey(),
                'routing_policy_id' => null,
                'accepted_provider_account_id' => $result->outcome === ProviderOutcome::Accepted
                    ? $account->getKey()
                    : null,
                'idempotency_key' => 'admin-test-'.Str::uuid(),
                'payload_hash' => hash('sha256', $normalizedRecipient."\n".$normalizedBody),
                'correlation_id' => 'admin-test-'.Str::uuid(),
                'client_reference' => null,
                'recipient' => $normalizedRecipient,
                'recipient_hash' => hash_hmac('sha256', $normalizedRecipient, (string) config('app.key')),
                'recipient_last4' => substr($normalizedRecipient, -4),
                'body' => $normalizedBody,
                'purpose' => self::PURPOSE,
                'route_key' => self::ROUTE_KEY,
                'mode' => 'sync',
                'origin' => 'api',
                'priority' => 50,
                'status' => match ($result->outcome) {
                    ProviderOutcome::Accepted => 'provider_accepted',
                    ProviderOutcome::OutcomeUnknown => 'outcome_unknown',
                    default => 'failed',
                },
                'metadata' => [
                    'source' => 'admin_test',
                    'administrator_id' => $administratorId,
                    'provider_account_id' => $account->getKey(),
                ],
                'provider_message_id' => $result->providerMessageId,
                'last_error_code' => $this->sanitize($result->errorCode, 120),
                'last_error_message' => $this->sanitize($result->errorMessage, 500),
                'processing_at' => $startedAt,
                'provider_accepted_at' => $result->outcome === ProviderOutcome::Accepted ? $finishedAt : null,
                'failed_at' => in_array($result->outcome, [
                    ProviderOutcome::Rejected,
                    ProviderOutcome::ProviderFailed,
                ], true) ? $finishedAt : null,
                'outcome_unknown_at' => $result->outcome === ProviderOutcome::OutcomeUnknown
                    ? $finishedAt
                    : null,
            ])->save();

            MessageEvent::query()->create([
                'gateway_message_id' => $message->getKey(),
                'type' => 'admin_provider_test',
                'source' => 'admin',
                'data' => [
                    'type' => 'send',
                    'provider_account_id' => $account->getKey(),
                    'administrator_id' => $administratorId,
                    'outcome' => $result->outcome->value,
                ],
                'occurred_at' => $startedAt,
            ]);

            MessageAttempt::forceCreate([
                'gateway_message_id' => $message->getKey(),
                'provider_account_id' => $account->getKey(),
                'sequence' => 1,
                'status' => $result->outcome->value,
                'delivery_certainty' => $result->deliveryCertainty->value,
                'retry_disposition' => $result->retryDisposition->value,
                'http_status' => $result->httpStatus,
                'provider_message_id' => $result->providerMessageId,
                'latency_ms' => $latencyMs,
                'error_code' => $this->sanitize($result->errorCode, 120),
                'error_message' => $this->sanitize($result->errorMessage, 500),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);
        });

        // Admin rejection probes must not open production circuits or spam ops alerts.
        if ($result->outcome !== ProviderOutcome::Rejected) {
            $this->health->record($account, $result);
        }

        $success = $result->outcome === ProviderOutcome::Accepted;

        return new ProviderAccountTestResult(
            success: $success,
            title: $success ? 'Uji kirim berhasil' : 'Uji kirim gagal',
            body: $success
                ? 'Provider menerima pesan'
                    .($result->providerMessageId ? " (ID: {$result->providerMessageId})." : '.')
                : ($result->errorMessage ?: $result->errorCode ?: 'Provider menolak atau gagal menerima pesan.'),
            type: 'send',
        );
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
