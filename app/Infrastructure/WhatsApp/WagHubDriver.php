<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Contracts\WhatsApp\ProviderNumberChecker;
use App\Domain\Delivery\OutboundMessage;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Exceptions\WhatsAppEngineException;
use App\Models\ProviderAccount;

final readonly class WagHubDriver implements ProviderDriver, ProviderNumberChecker
{
    public function __construct(private BaileysClient $client) {}

    public function send(ProviderAccount $account, OutboundMessage $message): ProviderResult
    {
        if ($message->hasAttachment()) {
            return ProviderResult::rejected(errorCode: 'attachment_format_unsupported', errorMessage: 'Engine WAG Hub saat ini mendukung pesan teks. Pilih provider lain untuk lampiran.');
        }
        if (! $this->client->isConfigured() || ! $message->idempotencyKey) {
            return ProviderResult::providerFailed(errorCode: 'provider_configuration_invalid', errorMessage: 'Engine WAG Hub belum siap.');
        }

        try {
            return $this->result($this->client->send($account, $message->recipient, $message->body, $message->idempotencyKey));
        } catch (WhatsAppEngineException) {
            return ProviderResult::outcomeUnknown(errorCode: 'engine_unreachable', errorMessage: 'Hasil pengiriman WAG Hub belum pasti.');
        }
    }

    public function messageStatus(ProviderAccount $account, string $key): ProviderResult
    {
        try {
            return $this->result($this->client->message($account, $key));
        } catch (WhatsAppEngineException) {
            return ProviderResult::outcomeUnknown(errorCode: 'delivery_unknown');
        }
    }

    public function checkNumber(ProviderAccount $account, string $recipient): NumberCheckResult
    {
        try {
            $payload = $this->client->checkNumber($account, $recipient);
        } catch (WhatsAppEngineException) {
            return NumberCheckResult::unknown('provider_unavailable');
        }

        return match ($payload['registered'] ?? null) {
            true => NumberCheckResult::registered(),
            false => NumberCheckResult::notRegistered(),
            default => NumberCheckResult::unknown(),
        };
    }

    private function result(array $payload): ProviderResult
    {
        if (($payload['status'] ?? null) === 'sent' && ($payload['ok'] ?? false) === true) {
            return ProviderResult::accepted(providerMessageId: isset($payload['id']) ? (string) $payload['id'] : null);
        }
        $code = (string) ($payload['error_code'] ?? 'delivery_unknown');
        if (($payload['status'] ?? null) === 'failed'
            && in_array($code, ['not_connected', 'invalid_phone', 'invalid_text', 'invalid_idempotency_key', 'unauthenticated'], true)) {
            return ProviderResult::rejected(errorCode: $code, errorMessage: 'Engine WAG Hub belum mengirim pesan.');
        }

        return ProviderResult::outcomeUnknown(errorCode: 'delivery_unknown', errorMessage: 'Hasil pengiriman WAG Hub belum pasti. Periksa status sebelum mencoba lagi.');
    }
}
