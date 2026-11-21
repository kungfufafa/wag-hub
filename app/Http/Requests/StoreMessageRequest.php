<?php

namespace App\Http\Requests;

use App\Domain\Delivery\AttachmentKind;
use App\Domain\Delivery\OutboundAttachment;
use App\Models\Attachment;
use App\Models\GatewayMessage;
use App\Services\AttachmentService;
use App\Support\PayloadHasher;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreMessageRequest extends FormRequest
{
    /**
     * WhatsApp caps media captions well below the plain-text limit.
     */
    public const CAPTION_MAX_LENGTH = 1024;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public static function messageTypes(): array
    {
        return ['text', ...AttachmentKind::values()];
    }

    protected function prepareForValidation(): void
    {
        $defaults = [
            'idempotency_key' => trim((string) $this->header('Idempotency-Key', '')),
        ];

        if (! $this->exists('route_key')) {
            $defaults['route_key'] = 'default';
        }

        $this->merge($defaults);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $phoneNormalizer = app(PhoneNormalizer::class);

        return [
            'idempotency_key' => ['required', 'string', 'min:1', 'max:160'],
            'recipient' => ['required', 'array:type,value'],
            'recipient.type' => ['required', Rule::in(['phone'])],
            'recipient.value' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($phoneNormalizer): void {
                    try {
                        $phoneNormalizer->normalize((string) $value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'message' => ['required', 'array:type,text,attachment'],
            'message.type' => ['required', Rule::in(self::messageTypes())],
            'message.text' => [
                'required_if:message.type,text',
                'nullable',
                'string',
                'min:1',
                Rule::when(
                    static fn (Fluent $input): bool => ($input->get('message')['type'] ?? null) === 'text',
                    ['max:10000'],
                    ['max:'.self::CAPTION_MAX_LENGTH],
                ),
            ],
            'message.attachment' => [
                'required_unless:message.type,text',
                'prohibited_if:message.type,text',
                'array:url,id,filename,mime_type',
            ],
            'message.attachment.url' => [
                'nullable',
                'string',
                'max:2048',
                'url:http,https',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        app(AttachmentService::class)->assertPublicUrl((string) $value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'message.attachment.id' => [
                'nullable',
                'uuid',
            ],
            'message.attachment.filename' => [
                'nullable',
                'string',
                'max:255',
                'regex:/\A[^\/\\\\\x00-\x1f]+\z/u',
            ],
            'message.attachment.mime_type' => [
                'nullable',
                'string',
                'max:120',
                'regex:/\A[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+(;\s*[a-z0-9_.-]+=[a-z0-9_.+"-]+)*\z/i',
            ],
            'purpose' => ['required', Rule::in(['otp', 'transactional', 'notification'])],
            'mode' => ['required', Rule::in(['sync', 'async'])],
            'route_key' => ['required', 'string', 'min:1', 'max:80', 'regex:/\A[a-zA-Z0-9._:-]+\z/'],
            'expires_at' => ['nullable', 'date', 'after:now', 'required_if:purpose,otp'],
            'client_reference' => ['nullable', 'string', 'max:160'],
            'metadata' => ['nullable', 'array', 'max:20'],
            'provider' => ['prohibited'],
            'provider_id' => ['prohibited'],
            'provider_account_id' => ['prohibited'],
            'credential' => ['prohibited'],
            'credential_id' => ['prohibited'],
            'callback_url' => ['prohibited'],
            'recipients' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $metadata = $this->input('metadata');

            if (is_array($metadata)) {
                $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if (! is_string($json) || strlen($json) > 8192) {
                    $validator->errors()->add('metadata', 'Metadata may not exceed 8 KB.');
                }
            }

            $type = $this->input('message.type');
            $attachment = $this->input('message.attachment');

            if ($type === AttachmentKind::Audio->value
                && is_string($this->input('message.text'))
                && trim((string) $this->input('message.text')) !== '') {
                $validator->errors()->add('message.text', 'Audio tidak mendukung caption.');
            }

            if (! is_array($attachment) || ! in_array($type, AttachmentKind::values(), true)) {
                return;
            }

            $hasUrl = is_string($attachment['url'] ?? null) && trim((string) $attachment['url']) !== '';
            $hasId = is_string($attachment['id'] ?? null) && trim((string) $attachment['id']) !== '';

            if ($hasUrl === $hasId) {
                $validator->errors()->add('message.attachment', 'Isi tepat salah satu: url atau id.');

                if (! $hasUrl && ! $hasId) {
                    $validator->errors()->add('message.attachment.url', 'The message.attachment.url field is required.');
                }

                return;
            }

            if ($hasId) {
                $application = $this->attributes->get('client_application');
                $idempotencyKey = $this->input('idempotency_key');

                // Replays of an existing request must not require the file to
                // still be live; MessageController returns the original row.
                if ($application !== null
                    && is_string($idempotencyKey)
                    && $idempotencyKey !== ''
                    && GatewayMessage::query()
                        ->where('client_application_id', $application->getKey())
                        ->where('idempotency_key', $idempotencyKey)
                        ->exists()) {
                    return;
                }

                $owned = $application !== null
                    && Attachment::query()
                        ->where('uuid', $attachment['id'])
                        ->where('client_application_id', $application->getKey())
                        ->where('media_kind', $type)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                        ->exists();

                if (! $owned) {
                    $validator->errors()->add('message.attachment.id', 'Attachment tidak ditemukan atau tidak sesuai jenis pesan.');
                }
            }
        });
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    public function canonicalRecipient(): string
    {
        return app(PhoneNormalizer::class)->normalize((string) $this->validated('recipient.value'));
    }

    public function messageType(): string
    {
        return (string) $this->validated('message.type');
    }

    /**
     * Text body for plain messages, caption for attachments. Empty when the
     * client sent an attachment without a caption.
     */
    public function messageText(): string
    {
        $text = $this->validated('message.text');

        return is_string($text) ? $text : '';
    }

    public function outboundAttachment(): ?OutboundAttachment
    {
        $kind = AttachmentKind::tryFrom($this->messageType());
        $attachment = $this->validated('message.attachment');

        if ($kind === null || ! is_array($attachment)) {
            return null;
        }

        return OutboundAttachment::fromArray([
            'kind' => $kind->value,
            'url' => $attachment['url'] ?? null,
            'id' => $attachment['id'] ?? null,
            'filename' => $attachment['filename'] ?? null,
            'mime_type' => $attachment['mime_type'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function canonicalPayload(): array
    {
        $expiresAt = $this->validated('expires_at');
        $attachment = $this->outboundAttachment();

        $message = [
            'type' => $this->messageType(),
            'text' => $this->messageText(),
        ];

        // Only attachment messages carry the extra key so existing text payload hashes stay stable.
        if ($attachment !== null) {
            $message['attachment'] = $attachment->attachmentId !== null
                ? [
                    'id' => $attachment->attachmentId,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mimeType,
                ]
                : [
                    'url' => $attachment->url,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mimeType,
                ];
        }

        return [
            'recipient' => [
                'type' => 'phone',
                'value' => $this->canonicalRecipient(),
            ],
            'message' => $message,
            'purpose' => (string) $this->validated('purpose'),
            'mode' => (string) $this->validated('mode'),
            'route_key' => (string) $this->validated('route_key'),
            'expires_at' => is_string($expiresAt)
                ? CarbonImmutable::parse($expiresAt)->utc()->format('Y-m-d\TH:i:s.u\Z')
                : null,
            'client_reference' => $this->validated('client_reference'),
            'metadata' => $this->sortRecursively($this->validated('metadata', [])),
        ];
    }

    public function payloadHash(): string
    {
        return app(PayloadHasher::class)->hash(
            $this->canonicalPayload(),
            (string) config('app.key'),
        );
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'error' => [
                'code' => 'validation_failed',
                'retryable' => false,
            ],
            'errors' => $validator->errors()->toArray(),
            'request_id' => $this->attributes->get('request_id'),
        ], 422));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
