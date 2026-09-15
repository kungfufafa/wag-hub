<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Contracts\WhatsApp\ProviderNumberChecker;
use App\Domain\Delivery\AttachmentKind;
use App\Domain\Delivery\OutboundMessage;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final readonly class GowaDriver implements ProviderDriver, ProviderNumberChecker
{
    public function __construct(
        private GowaResponseClassifier $responses,
        private TransportFailureClassifier $transportFailures,
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function send(ProviderAccount $account, OutboundMessage $message): ProviderResult
    {
        $configuration = is_array($account->configuration) ? $account->configuration : [];
        $baseUrl = $this->nonEmptyString($configuration['base_url'] ?? null);
        $username = $this->nonEmptyString($configuration['username'] ?? null);
        $password = $this->nonEmptyString($configuration['password'] ?? null);

        if ($baseUrl === null || $username === null || $password === null) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_configuration_invalid',
                errorMessage: 'Konfigurasi GOWA belum lengkap.',
            );
        }

        $version = $this->nonEmptyString($configuration['version'] ?? $configuration['gowa_version'] ?? null);

        if ($message->attachment?->kind === AttachmentKind::Document
            && ($version === null || version_compare(ltrim($version, 'v'), '8.10.0', '<'))) {
            return ProviderResult::rejected(
                errorCode: 'attachment_format_unsupported',
                errorMessage: 'Pengiriman URL dokumen GOWA membutuhkan versi 8.10.0 atau lebih baru yang terkonfigurasi.',
            );
        }

        [$path, $fields] = $this->sendRequest($message);
        $endpoint = rtrim($baseUrl, '/').$path;

        try {
            $this->endpoints->assertAllowed($endpoint);
        } catch (InvalidArgumentException) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_endpoint_not_allowed',
                errorMessage: 'Endpoint GOWA tidak diizinkan.',
            );
        }

        $request = Http::acceptJson()
            ->withBasicAuth($username, $password)
            ->withoutRedirecting()
            ->timeout($this->timeout($account))
            ->connectTimeout(min(5, $this->timeout($account)));
        $deviceId = $this->nonEmptyString($configuration['device_id'] ?? null);

        if ($deviceId !== null) {
            $request = $request->withHeaders(['X-Device-Id' => $deviceId]);
        }

        try {
            // GOWA's text endpoint is JSON while every media endpoint is multipart/form-data.
            $response = $message->hasAttachment()
                ? $request->asMultipart()->post($endpoint, $this->multipart($fields))
                : $request->asJson()->post($endpoint, $fields);
        } catch (ConnectionException $exception) {
            return $this->transportFailures->classifyException($exception);
        }

        return $this->responses->classify($response->status(), $response->body());
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function sendRequest(OutboundMessage $message): array
    {
        $fields = ['phone' => $message->gowaPhone()];
        $attachment = $message->attachment;

        if ($attachment === null) {
            return ['/send/message', $fields + ['message' => $message->body]];
        }

        [$path, $urlField] = match ($attachment->kind) {
            AttachmentKind::Image => ['/send/image', 'image_url'],
            AttachmentKind::Document => ['/send/file', 'file_url'],
            AttachmentKind::Video => ['/send/video', 'video_url'],
            AttachmentKind::Audio => ['/send/audio', 'audio_url'],
        };

        $fields[$urlField] = $attachment->url;
        $caption = $message->caption();

        if ($caption !== null) {
            $fields['caption'] = $caption;
        }

        return [$path, $fields];
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<array{name: string, contents: string}>
     */
    private function multipart(array $fields): array
    {
        $parts = [];

        foreach ($fields as $name => $contents) {
            $parts[] = ['name' => $name, 'contents' => $contents];
        }

        return $parts;
    }

    public function checkNumber(ProviderAccount $account, string $recipient): NumberCheckResult
    {
        $configuration = is_array($account->configuration) ? $account->configuration : [];
        $baseUrl = $this->nonEmptyString($configuration['base_url'] ?? null);
        $username = $this->nonEmptyString($configuration['username'] ?? null);
        $password = $this->nonEmptyString($configuration['password'] ?? null);

        if ($baseUrl === null || $username === null || $password === null) {
            return NumberCheckResult::unknown('provider_configuration_invalid');
        }

        $endpoint = rtrim($baseUrl, '/').'/user/check';

        try {
            $this->endpoints->assertAllowed($endpoint);
        } catch (InvalidArgumentException) {
            return NumberCheckResult::unknown('provider_endpoint_not_allowed');
        }

        $request = Http::acceptJson()
            ->withBasicAuth($username, $password)
            ->withoutRedirecting()
            ->timeout($this->timeout($account))
            ->connectTimeout(min(5, $this->timeout($account)));
        $deviceId = $this->nonEmptyString($configuration['device_id'] ?? null);

        if ($deviceId !== null) {
            $request = $request->withHeaders(['X-Device-Id' => $deviceId]);
        }

        try {
            $response = $request->get($endpoint, ['phone' => $recipient]);
        } catch (ConnectionException) {
            return NumberCheckResult::unknown('provider_unavailable');
        }

        if (! $response->successful()) {
            return NumberCheckResult::unknown('provider_unavailable', $response->status());
        }

        return match ($response->json('results.is_on_whatsapp')) {
            true => NumberCheckResult::registered($response->status()),
            false => NumberCheckResult::notRegistered($response->status()),
            default => NumberCheckResult::unknown(httpStatus: $response->status()),
        };
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function timeout(ProviderAccount $account): int
    {
        return max(1, min(60, (int) ($account->timeout_seconds ?: 15)));
    }
}
