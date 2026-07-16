<?php

namespace App\Http\Requests;

use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class CheckWhatsAppNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $normalizer = app(PhoneNormalizer::class);

        return [
            'recipient' => ['required', 'array:type,value'],
            'recipient.type' => ['required', Rule::in(['phone'])],
            'recipient.value' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($normalizer): void {
                    try {
                        $normalizer->normalize((string) $value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'provider' => ['prohibited'],
            'provider_id' => ['prohibited'],
            'provider_account_id' => ['prohibited'],
            'credential' => ['prohibited'],
            'credential_id' => ['prohibited'],
        ];
    }

    public function canonicalRecipient(): string
    {
        return app(PhoneNormalizer::class)->normalize((string) $this->validated('recipient.value'));
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
}
