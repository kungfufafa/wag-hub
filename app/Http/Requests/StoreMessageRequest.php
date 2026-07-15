<?php

namespace App\Http\Requests;

use App\Support\PayloadHasher;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'message' => ['required', 'array:type,text'],
            'message.type' => ['required', Rule::in(['text'])],
            'message.text' => ['required', 'string', 'min:1', 'max:10000'],
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

            if (! is_array($metadata)) {
                return;
            }

            $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (! is_string($json) || strlen($json) > 8192) {
                $validator->errors()->add('metadata', 'Metadata may not exceed 8 KB.');
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

    /**
     * @return array<string, mixed>
     */
    public function canonicalPayload(): array
    {
        $expiresAt = $this->validated('expires_at');

        return [
            'recipient' => [
                'type' => 'phone',
                'value' => $this->canonicalRecipient(),
            ],
            'message' => [
                'type' => 'text',
                'text' => (string) $this->validated('message.text'),
            ],
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
